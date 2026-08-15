<?php

namespace App\Services;

use Agence104\LiveKit\RoomServiceClient;
use App\Models\MediaStorageSetting;

class LiveKitConfigService
{
    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_LIVEKIT);
    }

    public function url(): string
    {
        $row = $this->configRow();
        $fromDb = rtrim(trim((string) $row->setting('url', '')), '/');
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return rtrim(trim((string) config('services.livekit.url', '')), '/');
    }

    public function storedUrl(): string
    {
        $fromDb = rtrim(trim((string) $this->configRow()->setting('url', '')), '/');
        if ($fromDb !== '') {
            return $fromDb;
        }

        return rtrim(trim((string) config('services.livekit.url', '')), '/');
    }

    public function apiKey(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('api_key', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.livekit.api_key', ''));
    }

    public function apiSecret(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('api_secret', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.livekit.api_secret', ''));
    }

    public function isReady(): bool
    {
        return $this->url() !== ''
            && $this->apiKey() !== ''
            && $this->apiSecret() !== '';
    }

    /**
     * @param  array{url?: mixed, api_key?: mixed, api_secret?: mixed}  $input
     */
    public function saveConfig(bool $enabled, array $input): MediaStorageSetting
    {
        $row = $this->configRow();
        $secrets = $row->secretsArray();
        $settings = $row->settingsArray();

        $url = rtrim(trim((string) ($input['url'] ?? '')), '/');
        if ($url !== '') {
            $settings['url'] = $url;
        }

        foreach (['api_key', 'api_secret'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '' && $value !== '••••••••' && ! str_starts_with($value, '••••')) {
                $secrets[$key] = $value;
            }
        }

        $row->fill([
            'enabled' => $enabled,
            'settings' => $settings,
            'secrets' => $secrets,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = $this->configRow();

        return [
            'enabled' => (bool) $row->enabled,
            'url' => $this->storedUrl(),
            'api_key_masked' => $row->maskedSecret('api_key'),
            'api_secret_masked' => $row->maskedSecret('api_secret'),
            'ready' => $this->isReady(),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $url = $this->storedUrl();
        $apiKey = trim((string) $this->configRow()->secret('api_key', '')) ?: trim((string) config('services.livekit.api_key', ''));
        $apiSecret = trim((string) $this->configRow()->secret('api_secret', '')) ?: trim((string) config('services.livekit.api_secret', ''));

        if ($url === '' || $apiKey === '' || $apiSecret === '') {
            return ['ok' => false, 'message' => __('LiveKit URL / API Key / API Secret を入力して保存してください。')];
        }

        if (! $this->configRow()->enabled && trim((string) $this->configRow()->secret('api_key', '')) !== '') {
            return ['ok' => false, 'message' => __('認証情報は保存されていますが「有効にする」がオフです。チェックを入れて保存してください。')];
        }

        if (! preg_match('#^wss?://.+#i', $url) && ! preg_match('#^https?://.+#i', $url)) {
            return ['ok' => false, 'message' => __('LiveKit URL は wss:// または https:// で始まる必要があります。')];
        }

        if (strlen($apiSecret) < 32) {
            return ['ok' => false, 'message' => __('API Secret が短すぎます。LiveKit Cloud の API Secret を貼り付けてください。')];
        }

        $httpHost = preg_replace('#^ws#i', 'http', $url) ?? $url;

        try {
            $client = new RoomServiceClient($httpHost, $apiKey, $apiSecret);
            $client->listRooms();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => __('LiveKit 接続に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 200)]),
            ];
        }

        return ['ok' => true, 'message' => __('LiveKit に接続できました。')];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = $this->configRow();
        $row->fill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'fail',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
        $row->save();
    }
}
