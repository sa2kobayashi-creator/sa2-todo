"""
SwinIR GPU VPS worker (FastAPI).

Deploy on a CUDA GPU machine alongside the official SwinIR repo:
  https://github.com/JingyunLiang/SwinIR

Quick start (on the VPS):
  git clone https://github.com/JingyunLiang/SwinIR.git
  cd SwinIR
  # install torch (CUDA) + deps, download weights via download-weights.sh or first run
  pip install fastapi uvicorn python-multipart opencv-python-headless pillow numpy
  export SWINIR_ROOT=/path/to/SwinIR
  export SWINIR_API_KEY=change-me
  uvicorn tools_server:app --host 0.0.0.0 --port 8000

Or from this folder after setting SWINIR_ROOT:
  uvicorn app:app --host 0.0.0.0 --port 8000
"""

from __future__ import annotations

import argparse
import os
import sys
import threading
from pathlib import Path
from typing import Optional

import cv2
import numpy as np
import torch
from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile
from fastapi.responses import Response

SWINIR_ROOT = Path(os.environ.get("SWINIR_ROOT", "")).expanduser().resolve()
API_KEY = os.environ.get("SWINIR_API_KEY", "").strip()
DEFAULT_MODEL = os.environ.get(
    "SWINIR_MODEL",
    "model_zoo/swinir/003_realSR_BSRGAN_DFO_s64w8_SwinIR-M_x4_GAN.pth",
)
DEFAULT_LARGE_MODEL = os.environ.get(
    "SWINIR_LARGE_MODEL",
    "model_zoo/swinir/003_realSR_BSRGAN_DFOWMFC_s64w8_SwinIR-L_x4_GAN.pth",
)

if not SWINIR_ROOT.is_dir():
    raise SystemExit(
        "SWINIR_ROOT is not set or invalid. "
        "Clone https://github.com/JingyunLiang/SwinIR and export SWINIR_ROOT=/path/to/SwinIR"
    )

sys.path.insert(0, str(SWINIR_ROOT))
from main_test_swinir import define_model  # noqa: E402

app = FastAPI(title="SwinIR Worker", version="1.0.0")
_lock = threading.Lock()
_models: dict[str, torch.nn.Module] = {}
_device = torch.device("cuda" if torch.cuda.is_available() else "cpu")


def require_api_key(
    authorization: Optional[str] = Header(default=None),
    x_api_key: Optional[str] = Header(default=None, alias="X-API-Key"),
) -> None:
    if not API_KEY:
        return
    token = None
    if x_api_key:
        token = x_api_key.strip()
    elif authorization and authorization.lower().startswith("bearer "):
        token = authorization[7:].strip()
    if token != API_KEY:
        raise HTTPException(status_code=401, detail="Invalid API key")


def _model_key(scale: int, large_model: bool) -> str:
    return f"real_sr_x{scale}_{'L' if large_model else 'M'}"


def _model_path(large_model: bool) -> Path:
    rel = DEFAULT_LARGE_MODEL if large_model else DEFAULT_MODEL
    path = Path(rel)
    if not path.is_absolute():
        path = SWINIR_ROOT / path
    return path


def load_model(scale: int = 4, large_model: bool = False) -> torch.nn.Module:
    key = _model_key(scale, large_model)
    if key in _models:
        return _models[key]

    model_path = _model_path(large_model)
    if not model_path.is_file():
        raise HTTPException(
            status_code=500,
            detail=f"Model not found: {model_path}. Run download-weights.sh in the SwinIR repo.",
        )

    args = argparse.Namespace(
        task="real_sr",
        scale=scale,
        noise=15,
        jpeg=40,
        training_patch_size=128,
        large_model=large_model,
        model_path=str(model_path),
        folder_lq=None,
        folder_gt=None,
        tile=None,
        tile_overlap=32,
    )
    model = define_model(args)
    model.eval()
    model = model.to(_device)
    _models[key] = model
    return model


def test_tile(img, model, window_size: int, scale: int, tile: int, tile_overlap: int):
    """Official-style tiled inference for large images."""
    b, c, h, w = img.size()
    tile = min(tile, h, w)
    assert tile % window_size == 0, "tile size should be a multiple of window_size"
    stride = tile - tile_overlap
    h_idx_list = list(range(0, h - tile, stride)) + [h - tile]
    w_idx_list = list(range(0, w - tile, stride)) + [w - tile]
    e = torch.zeros(b, c, h * scale, w * scale, dtype=img.dtype, device=img.device)
    w_count = torch.zeros_like(e)
    for h_idx in h_idx_list:
        for w_idx in w_idx_list:
            in_patch = img[..., h_idx : h_idx + tile, w_idx : w_idx + tile]
            out_patch = model(in_patch)
            out_h = h_idx * scale
            out_w = w_idx * scale
            e[..., out_h : out_h + tile * scale, out_w : out_w + tile * scale].add_(out_patch)
            w_count[..., out_h : out_h + tile * scale, out_w : out_w + tile * scale].add_(1)
    return e.div_(w_count)


def enhance_bgr(img_bgr: np.ndarray, scale: int, large_model: bool, tile: int) -> np.ndarray:
    window_size = 8
    model = load_model(scale=scale, large_model=large_model)

    img_lq = img_bgr.astype(np.float32) / 255.0
    img_lq = np.transpose(img_lq[:, :, [2, 1, 0]], (2, 0, 1))  # HWC-BGR -> CHW-RGB
    img_lq = torch.from_numpy(img_lq).float().unsqueeze(0).to(_device)

    with torch.no_grad():
        _, _, h_old, w_old = img_lq.size()
        h_pad = (h_old // window_size + 1) * window_size - h_old
        w_pad = (w_old // window_size + 1) * window_size - w_old
        img_lq = torch.cat([img_lq, torch.flip(img_lq, [2])], 2)[:, :, : h_old + h_pad, :]
        img_lq = torch.cat([img_lq, torch.flip(img_lq, [3])], 3)[:, :, :, : w_old + w_pad]

        if tile and tile > 0:
            # tile must be multiple of window_size
            tile = max(window_size, (tile // window_size) * window_size)
            output = test_tile(img_lq, model, window_size, scale, tile, 32)
        else:
            output = model(img_lq)

        output = output[..., : h_old * scale, : w_old * scale]
        output = output.data.squeeze().float().cpu().clamp_(0, 1).numpy()
        if output.ndim == 3:
            output = np.transpose(output[[2, 1, 0], :, :], (1, 2, 0))  # CHW-RGB -> HWC-BGR
        else:
            output = np.expand_dims(output, axis=2)
        return (output * 255.0).round().astype(np.uint8)


@app.get("/health")
def health(_: None = Depends(require_api_key)):
    return {
        "ok": True,
        "device": str(_device),
        "cuda": torch.cuda.is_available(),
        "model": str(_model_path(False)),
        "large_model": str(_model_path(True)),
        "swinir_root": str(SWINIR_ROOT),
    }


@app.post("/upscale")
async def upscale(
    image: UploadFile = File(...),
    scale: int = Form(4),
    tile: int = Form(400),
    large_model: str = Form("0"),
    output_format: str = Form("png"),
    _: None = Depends(require_api_key),
):
    scale = 4 if scale not in (2, 3, 4) else scale
    # Official real_sr pretrained weights are x4; keep scale=4 for quality.
    if scale != 4:
        scale = 4
    use_large = large_model in ("1", "true", "True", "yes", "on")
    fmt = output_format.lower().strip()
    if fmt not in ("png", "jpg", "jpeg", "webp"):
        fmt = "png"
    if fmt == "jpeg":
        fmt = "jpg"

    raw = await image.read()
    if not raw:
        raise HTTPException(status_code=400, detail="Empty image upload")

    arr = np.frombuffer(raw, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise HTTPException(status_code=400, detail="Could not decode image")

    with _lock:
        try:
            out = enhance_bgr(img, scale=scale, large_model=use_large, tile=max(0, int(tile)))
        except HTTPException:
            raise
        except Exception as e:
            raise HTTPException(status_code=500, detail=str(e)) from e

    ext = ".jpg" if fmt == "jpg" else f".{fmt}"
    ok, encoded = cv2.imencode(ext, out, [int(cv2.IMWRITE_JPEG_QUALITY), 92] if fmt == "jpg" else [])
    if not ok:
        raise HTTPException(status_code=500, detail="Failed to encode output image")

    media = {
        "png": "image/png",
        "jpg": "image/jpeg",
        "webp": "image/webp",
    }[fmt]
    return Response(content=encoded.tobytes(), media_type=media)


@app.on_event("startup")
def warmup():
    # Optional warm load; ignore missing weights until first upscale.
    try:
        load_model(scale=4, large_model=False)
    except Exception as e:
        print(f"[swinir-worker] warmup skipped: {e}")
