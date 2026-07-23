<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Http;

class StabilityAiService
{
    public function __construct(private MediaStorageConfigService $config) {}

    public function isReady(): bool
    {
        return $this->config->stabilityEnabled();
    }

    /**
     * Fast Upscale で画像を鮮明化（同期）。
     * API は 1 リクエストあたり最大 1MP のため、超過分は原本を縮小せずタイル分割して処理し、
     * 最終出力は元の解像度のまま合成する。
     *
     * @return array{binary: string, mime: string, extension: string}
     */
    public function enhanceImage(string $binary, string $filename, string $mime = 'image/jpeg'): array
    {
        if (! $this->isReady()) {
            throw new \InvalidArgumentException(__('Stability AI が有効ではありません。ストレージ設定を確認してください。'));
        }

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '1024M');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $row = $this->config->get(MediaStorageSetting::PROVIDER_STABILITY);
        $apiKey = (string) $row->secret('api_key', '');
        $mode = (string) $row->setting('mode', 'fast');
        if (! in_array($mode, ['fast', 'conservative'], true)) {
            $mode = 'fast';
        }
        $outputFormat = (string) $row->setting('output_format', 'jpeg');
        if (! in_array($outputFormat, ['jpeg', 'png', 'webp'], true)) {
            $outputFormat = 'jpeg';
        }
        $prompt = trim((string) $row->setting('default_prompt', ''));
        if ($prompt === '') {
            $prompt = 'high quality clear photograph, sharp details, natural colors';
        }

        $maxPixels = max(64 * 64, (int) config('photos.stability_max_input_pixels', 1_048_576));
        $src = @imagecreatefromstring($binary);
        if (! $src) {
            throw new \RuntimeException(__('画像を読み込めませんでした。'));
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 64 || $h < 64) {
            imagedestroy($src);
            throw new \InvalidArgumentException(__('画像が小さすぎます（各辺 64px 以上が必要です）。'));
        }

        try {
            if (($w * $h) <= $maxPixels) {
                return $this->enhanceWholeImage($apiKey, $src, $mode, $prompt, $outputFormat, $filename);
            }

            // 1MP 超: 全体縮小せずタイル処理 → 元解像度で合成
            return $this->enhanceByTiles($apiKey, $src, $mode, $prompt, $outputFormat, $maxPixels);
        } finally {
            if (is_resource($src) || $src instanceof \GdImage) {
                imagedestroy($src);
            }
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $row = $this->config->get(MediaStorageSetting::PROVIDER_STABILITY);
        $apiKey = (string) $row->secret('api_key', '');
        if ($apiKey === '') {
            return ['ok' => false, 'message' => __('Stability AI の API Key を入力してください')];
        }

        $response = Http::withToken($apiKey)
            ->timeout(20)
            ->acceptJson()
            ->get('https://api.stability.ai/v1/user/account');

        if ($response->successful()) {
            $email = (string) data_get($response->json(), 'email', '');

            return [
                'ok' => true,
                'message' => $email !== ''
                    ? __('Stability AI への接続に成功しました（:email）', ['email' => $email])
                    : __('Stability AI への接続に成功しました'),
            ];
        }

        return [
            'ok' => false,
            'message' => __('Stability AI 接続エラー: :detail', [
                'detail' => mb_substr($response->body() ?: ('HTTP '.$response->status()), 0, 300),
            ]),
        ];
    }

    /**
     * @param  \GdImage  $src
     * @return array{binary: string, mime: string, extension: string}
     */
    private function enhanceWholeImage(
        string $apiKey,
        $src,
        string $mode,
        string $prompt,
        string $outputFormat,
        string $filename
    ): array {
        $tmp = $this->gdToTempJpeg($src);
        try {
            $name = $this->safeFilename($filename, 'image/jpeg');
            if ($mode === 'conservative') {
                return $this->upscaleConservative($apiKey, $tmp, $name, $prompt, $outputFormat);
            }

            return $this->upscaleFast($apiKey, $tmp, $name, $outputFormat);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * 原本解像度を保ったままタイル単位で鮮明化し合成する（全体の事前縮小はしない）。
     *
     * @param  \GdImage  $src
     * @return array{binary: string, mime: string, extension: string}
     */
    private function enhanceByTiles(
        string $apiKey,
        $src,
        string $mode,
        string $prompt,
        string $outputFormat,
        int $maxPixels
    ): array {
        $w = imagesx($src);
        $h = imagesy($src);
        $overlap = 48;
        [$cols, $rows] = $this->tileGrid($w, $h, $maxPixels, $overlap);
        $tileW = (int) ceil($w / $cols);
        $tileH = (int) ceil($h / $rows);

        $canvas = imagecreatetruecolor($w, $h);
        imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $x0 = max(0, $col * $tileW - ($col > 0 ? $overlap : 0));
                $y0 = max(0, $row * $tileH - ($row > 0 ? $overlap : 0));
                $x1 = min($w, ($col + 1) * $tileW + ($col < $cols - 1 ? $overlap : 0));
                $y1 = min($h, ($row + 1) * $tileH + ($row < $rows - 1 ? $overlap : 0));
                $tw = $x1 - $x0;
                $th = $y1 - $y0;
                if ($tw < 64 || $th < 64) {
                    continue;
                }
                if ($tw * $th > $maxPixels) {
                    throw new \RuntimeException(__('タイルサイズが API 上限を超えています。別の写真で再試行してください。'));
                }

                $tile = imagecreatetruecolor($tw, $th);
                imagecopy($tile, $src, 0, 0, $x0, $y0, $tw, $th);
                $tileTmp = $this->gdToTempJpeg($tile);
                imagedestroy($tile);

                try {
                    $enhanced = $mode === 'conservative'
                        ? $this->upscaleConservative($apiKey, $tileTmp, 'tile.jpg', $prompt, $outputFormat)
                        : $this->upscaleFast($apiKey, $tileTmp, 'tile.jpg', $outputFormat);
                } finally {
                    @unlink($tileTmp);
                }

                $enhancedGd = @imagecreatefromstring($enhanced['binary']);
                if (! $enhancedGd) {
                    throw new \RuntimeException(__('鮮明化タイルの読み込みに失敗しました。'));
                }

                // API は解像度を上げて返すため、元タイルサイズへ戻して合成（全体縮小ではない）
                $ew = imagesx($enhancedGd);
                $eh = imagesy($enhancedGd);
                if ($ew !== $tw || $eh !== $th) {
                    $fitted = imagecreatetruecolor($tw, $th);
                    imagecopyresampled($fitted, $enhancedGd, 0, 0, 0, 0, $tw, $th, $ew, $eh);
                    imagedestroy($enhancedGd);
                    $enhancedGd = $fitted;
                }

                imagecopy($canvas, $enhancedGd, $x0, $y0, 0, 0, $tw, $th);
                imagedestroy($enhancedGd);
            }
        }

        ob_start();
        if ($outputFormat === 'png') {
            imagepng($canvas);
            $mime = 'image/png';
            $ext = 'png';
        } elseif ($outputFormat === 'webp' && function_exists('imagewebp')) {
            imagewebp($canvas, null, 90);
            $mime = 'image/webp';
            $ext = 'webp';
        } else {
            imagejpeg($canvas, null, 92);
            $mime = 'image/jpeg';
            $ext = 'jpg';
        }
        $out = (string) ob_get_clean();
        imagedestroy($canvas);

        if ($out === '') {
            throw new \RuntimeException(__('鮮明化結果の書き出しに失敗しました。'));
        }

        return [
            'binary' => $out,
            'mime' => $mime,
            'extension' => $ext,
        ];
    }

    /**
     * @return array{0: int, 1: int} cols, rows
     */
    private function tileGrid(int $w, int $h, int $maxPixels, int $overlap = 0): array
    {
        $cols = 1;
        $rows = 1;
        while (true) {
            $tw = (int) ceil($w / $cols) + ($cols > 1 ? $overlap : 0);
            $th = (int) ceil($h / $rows) + ($rows > 1 ? $overlap : 0);
            if ($tw * $th <= $maxPixels) {
                return [$cols, $rows];
            }
            if ($tw >= $th) {
                $cols++;
            } else {
                $rows++;
            }
            if ($cols * $rows > 64) {
                return [max(1, $cols), max(1, $rows)];
            }
        }
    }

    /** @param  \GdImage  $img */
    private function gdToTempJpeg($img): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stab_tile_');
        if ($tmp === false) {
            throw new \RuntimeException(__('一時ファイルを作成できません。'));
        }
        if (! imagejpeg($img, $tmp, 92)) {
            @unlink($tmp);
            throw new \RuntimeException(__('一時JPEGの作成に失敗しました。'));
        }

        return $tmp;
    }

    /**
     * @return array{binary: string, mime: string, extension: string}
     */
    private function upscaleFast(string $apiKey, string $tmpPath, string $filename, string $outputFormat): array
    {
        $response = Http::withToken($apiKey)
            ->timeout(180)
            ->accept('image/*')
            ->attach('image', (string) file_get_contents($tmpPath), $filename)
            ->post('https://api.stability.ai/v2beta/stable-image/upscale/fast', [
                'output_format' => $outputFormat,
            ]);

        return $this->parseImageResponse($response, $outputFormat);
    }

    /**
     * @return array{binary: string, mime: string, extension: string}
     */
    private function upscaleConservative(
        string $apiKey,
        string $tmpPath,
        string $filename,
        string $prompt,
        string $outputFormat
    ): array {
        $response = Http::withToken($apiKey)
            ->timeout(180)
            ->accept('application/json')
            ->attach('image', (string) file_get_contents($tmpPath), $filename)
            ->post('https://api.stability.ai/v2beta/stable-image/upscale/conservative', [
                'prompt' => $prompt,
                'output_format' => $outputFormat,
            ]);

        $contentType = strtolower((string) $response->header('Content-Type'));
        if ($response->successful() && str_starts_with($contentType, 'image/')) {
            return $this->parseImageResponse($response, $outputFormat);
        }

        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response));
        }

        $id = (string) data_get($response->json(), 'id', '');
        if ($id === '') {
            $b64 = (string) data_get($response->json(), 'image', '');
            if ($b64 !== '') {
                $binary = base64_decode($b64, true);
                if ($binary === false || $binary === '') {
                    throw new \RuntimeException(__('Stability AI の応答画像を解読できませんでした。'));
                }

                return [
                    'binary' => $binary,
                    'mime' => $this->mimeForFormat($outputFormat),
                    'extension' => $outputFormat === 'jpeg' ? 'jpg' : $outputFormat,
                ];
            }

            throw new \RuntimeException(__('Stability AI から生成IDを取得できませんでした。'));
        }

        return $this->pollResult($apiKey, $id, $outputFormat);
    }

    /**
     * @return array{binary: string, mime: string, extension: string}
     */
    private function pollResult(string $apiKey, string $id, string $outputFormat): array
    {
        $candidates = [
            'https://api.stability.ai/v2beta/stable-image/upscale/conservative/result/'.$id,
            'https://api.stability.ai/v2beta/results/'.$id,
        ];

        $deadline = microtime(true) + 120;
        $lastError = __('鮮明化の完了待ちがタイムアウトしました。');

        while (microtime(true) < $deadline) {
            foreach ($candidates as $candidate) {
                $response = Http::withToken($apiKey)
                    ->timeout(60)
                    ->accept('image/*')
                    ->get($candidate);

                if ($response->status() === 202) {
                    $lastError = __('鮮明化処理中です…');
                    usleep(1_500_000);
                    continue 2;
                }

                if ($response->successful()) {
                    $contentType = strtolower((string) $response->header('Content-Type'));
                    if (str_starts_with($contentType, 'image/')) {
                        return $this->parseImageResponse($response, $outputFormat);
                    }
                    $b64 = (string) data_get($response->json(), 'image', '');
                    if ($b64 !== '') {
                        $binary = base64_decode($b64, true);
                        if (is_string($binary) && $binary !== '') {
                            return [
                                'binary' => $binary,
                                'mime' => $this->mimeForFormat($outputFormat),
                                'extension' => $outputFormat === 'jpeg' ? 'jpg' : $outputFormat,
                            ];
                        }
                    }
                }

                if ($response->status() !== 404) {
                    $lastError = $this->errorMessage($response);
                }
            }
            usleep(1_500_000);
        }

        throw new \RuntimeException($lastError);
    }

    /**
     * @return array{binary: string, mime: string, extension: string}
     */
    private function parseImageResponse(\Illuminate\Http\Client\Response $response, string $outputFormat): array
    {
        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response));
        }

        $binary = $response->body();
        if ($binary === '') {
            throw new \RuntimeException(__('Stability AI から空の画像が返りました。'));
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $b64 = (string) data_get($response->json(), 'image', '');
            $binary = base64_decode($b64, true) ?: '';
            if ($binary === '') {
                throw new \RuntimeException(__('Stability AI の応答画像を解読できませんでした。'));
            }
        }

        $mime = $this->mimeForFormat($outputFormat);
        if (str_starts_with($contentType, 'image/')) {
            $mime = explode(';', $contentType)[0];
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        return [
            'binary' => $binary,
            'mime' => $mime,
            'extension' => $ext,
        ];
    }

    private function errorMessage(\Illuminate\Http\Client\Response $response): string
    {
        $jsonMessage = (string) data_get($response->json(), 'message', '');
        $errors = data_get($response->json(), 'errors');
        if (is_array($errors) && $errors !== []) {
            $jsonMessage = implode(' ', array_map('strval', $errors));
        }
        $detail = $jsonMessage !== '' ? $jsonMessage : ($response->body() ?: ('HTTP '.$response->status()));

        return __('Stability AI エラー: :detail', ['detail' => mb_substr($detail, 0, 400)]);
    }

    private function mimeForFormat(string $format): string
    {
        return match ($format) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private function safeFilename(string $filename, string $mime): string
    {
        $base = basename($filename);
        if ($base === '' || $base === '.' || $base === '..') {
            $ext = match (true) {
                str_contains($mime, 'png') => 'png',
                str_contains($mime, 'webp') => 'webp',
                default => 'jpg',
            };

            return 'photo.'.$ext;
        }

        return $base;
    }
}
