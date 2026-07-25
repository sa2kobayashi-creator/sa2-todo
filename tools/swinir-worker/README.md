# SwinIR GPU VPS worker

Laravel アプリから呼ぶ **SwinIR HTTP API** です。GPU 付き VPS 上で動かします。

## Windows（PowerShell）で重みを取る場合

`bash download-weights.sh` は Linux/WSL 用です。Windows では次を実行します。

```powershell
cd D:\Development\todo_sa2\SwinIR
New-Item -ItemType Directory -Force -Path model_zoo\swinir | Out-Null
Invoke-WebRequest -Uri "https://github.com/JingyunLiang/SwinIR/releases/download/v0.0/003_realSR_BSRGAN_DFO_s64w8_SwinIR-M_x4_GAN.pth" -OutFile "model_zoo\swinir\003_realSR_BSRGAN_DFO_s64w8_SwinIR-M_x4_GAN.pth"
Invoke-WebRequest -Uri "https://github.com/JingyunLiang/SwinIR/releases/download/v0.0/003_realSR_BSRGAN_DFOWMFC_s64w8_SwinIR-L_x4_GAN.pth" -OutFile "model_zoo\swinir\003_realSR_BSRGAN_DFOWMFC_s64w8_SwinIR-L_x4_GAN.pth"
```

## 1. VPS / ローカル GPU 準備

```bash
# CUDA 対応 GPU 機で
git clone https://github.com/JingyunLiang/SwinIR.git
cd SwinIR
# PyTorch (CUDA) を公式手順で入れてから:
bash download-weights.sh   # または初回推論時に自動DLされる場合あり

# このワーカーをコピー
# （todo_sa2/tools/swinir-worker を VPS に置く）
cd /path/to/swinir-worker
pip install -r requirements.txt
# ※ torch は CUDA 版を別途インストールすること
```

## 2. 起動

```bash
export SWINIR_ROOT=/path/to/SwinIR
export SWINIR_API_KEY='長いランダム文字列'
# 任意: モデルパス上書き
# export SWINIR_MODEL=model_zoo/swinir/003_realSR_BSRGAN_DFO_s64w8_SwinIR-M_x4_GAN.pth
# export SWINIR_LARGE_MODEL=model_zoo/swinir/003_realSR_BSRGAN_DFOWMFC_s64w8_SwinIR-L_x4_GAN.pth

uvicorn app:app --host 0.0.0.0 --port 8000
```

ファイアウォールで **Laravel サーバからのみ** 8000 を許可してください。

## 3. Laravel 側（鮮明化設定）

1. SwinIR を有効にする
2. エンドポイント: `http://あなたのVPS:8000`（HTTPS 推奨）
3. API Key: 上の `SWINIR_API_KEY` と同じ値
4. タイル: VRAM 不足なら `400` や `256`
5. 「接続テスト」→ 使用エンジンを SwinIR に切替

## API

- `GET /health` … 疎通・CUDA 確認
- `POST /upscale` … `multipart/form-data` で `image` + `scale` / `tile` / `large_model` / `output_format`
