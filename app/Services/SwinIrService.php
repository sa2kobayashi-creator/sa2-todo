<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SwinIrService
{
    public function __construct(private EnhanceConfigService $enhance) {}

    public function isReady(): bool
    {
        return $this->enhance->isReady(EnhanceConfigService::PROVIDER_SWINIR);
    }

    /**
     * GPU VPS 上の SwinIR API でアップスケール。
     *
     * @return array{binary: string, mime: string, extension: string, width: ?int, height: ?int}
     */
    public function enhanceImage(string $binary, string $filename, string $mime = 'image/jpeg'): array
    {
        if (! $this->isReady()) {
            throw new \InvalidArgumentException(__('SwinIR が有効ではありません。鮮明化設定を確認してください。'));
        }

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '1024M');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(900);
        }

        $row = $this->enhance->providerRow(EnhanceConfigService::PROVIDER_SWINIR);
        $endpoint = rtrim(trim((string) $row->setting('endpoint', '')), '/');
        $apiKey = (string) $row->secret('api_key', '');
        $scale = max(2, min(4, (int) $row->setting('scale', 4)));
        $tile = max(0, (int) $row->setting('tile', 400));
        $largeModel = (bool) $row->setting('large_model', false);
        $timeout = max(30, (int) $row->setting('timeout_seconds', config('photos.swinir_timeout', 600)));
        $outputFormat = (string) $row->setting('output_format', 'png');
        if (! in_array($outputFormat, ['jpg', 'png', 'webp'], true)) {
            $outputFormat = 'png';
        }

        $url = $endpoint.'/upscale';
        $request = Http::timeout($timeout)
            ->connectTimeout(15)
            ->accept('image/*, application/json')
            ->attach('image', $binary, $filename !== '' ? $filename : 'photo.jpg');

        if ($apiKey !== '') {
            $request = $request->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'X-API-Key' => $apiKey,
            ]);
        }

        $response = $request->post($url, [
            'scale' => (string) $scale,
            'tile' => (string) $tile,
            'large_model' => $largeModel ? '1' : '0',
            'output_format' => $outputFormat,
        ]);

        if (! $response->successful()) {
            $detail = $this->errorDetail($response->body(), $response->status());
            throw new \RuntimeException(__('SwinIR エラー: :detail', ['detail' => $detail]));
        }

        $outBinary = $response->body();
        if ($outBinary === '' || strlen($outBinary) < 32) {
            throw new \RuntimeException(__('SwinIR から空の画像が返りました。'));
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        [$outMime, $extension] = $this->mimeAndExtension($contentType, $outputFormat);

        $tmp = tempnam(sys_get_temp_dir(), 'swinir_');
        $width = null;
        $height = null;
        if ($tmp !== false) {
            file_put_contents($tmp, $outBinary);
            $size = @getimagesize($tmp);
            if (is_array($size)) {
                $width = (int) $size[0];
                $height = (int) $size[1];
            }
            @unlink($tmp);
        }

        return [
            'binary' => $outBinary,
            'mime' => $outMime,
            'extension' => $extension,
            'width' => $width,
            'height' => $height,
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $row = $this->enhance->providerRow(EnhanceConfigService::PROVIDER_SWINIR);
        if (! $row->enabled) {
            return ['ok' => false, 'message' => __('SwinIR が有効ではありません。')];
        }

        $endpoint = rtrim(trim((string) $row->setting('endpoint', '')), '/');
        if ($endpoint === '' || ! preg_match('#^https?://#i', $endpoint)) {
            return ['ok' => false, 'message' => __('SwinIR のエンドポイント URL を入力してください（例: http://gpu-vps:8000）。')];
        }

        $apiKey = (string) $row->secret('api_key', '');
        $timeout = max(5, min(60, (int) $row->setting('timeout_seconds', 30)));

        try {
            $request = Http::timeout($timeout)->connectTimeout(10)->acceptJson();
            if ($apiKey !== '') {
                $request = $request->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'X-API-Key' => $apiKey,
                ]);
            }
            $response = $request->get($endpoint.'/health');
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => __('SwinIR に接続できません: :detail', ['detail' => mb_substr($e->getMessage(), 0, 300)]),
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => __('SwinIR ヘルスチェック失敗: :detail', [
                    'detail' => $this->errorDetail($response->body(), $response->status()),
                ]),
            ];
        }

        $data = $response->json();
        $device = is_array($data) ? (string) ($data['device'] ?? '') : '';
        $model = is_array($data) ? (string) ($data['model'] ?? '') : '';
        $cuda = is_array($data) && ! empty($data['cuda']);

        $parts = [__('SwinIR API に接続できました')];
        if ($device !== '') {
            $parts[] = 'device='.$device;
        }
        if ($cuda) {
            $parts[] = 'CUDA';
        }
        if ($model !== '') {
            $parts[] = 'model='.$model;
        }

        return ['ok' => true, 'message' => implode(' / ', $parts)];
    }

    private function errorDetail(string $body, int $status): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $msg = $json['detail'] ?? $json['message'] ?? $json['error'] ?? null;
            if (is_string($msg) && $msg !== '') {
                return mb_substr($msg, 0, 400);
            }
            if (is_array($msg)) {
                return mb_substr(json_encode($msg, JSON_UNESCAPED_UNICODE) ?: '', 0, 400);
            }
        }
        $plain = trim(strip_tags($body));
        if ($plain !== '') {
            return mb_substr($plain, 0, 400);
        }

        return __('HTTP :status', ['status' => $status]);
    }

    /** @return array{0: string, 1: string} */
    private function mimeAndExtension(string $contentType, string $fallbackFormat): array
    {
        if (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
            return ['image/jpeg', 'jpeg'];
        }
        if (str_contains($contentType, 'webp')) {
            return ['image/webp', 'webp'];
        }
        if (str_contains($contentType, 'png')) {
            return ['image/png', 'png'];
        }

        return match ($fallbackFormat) {
            'jpg' => ['image/jpeg', 'jpeg'],
            'webp' => ['image/webp', 'webp'],
            default => ['image/png', 'png'],
        };
    }
}
