<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Http;

class GoogleMapsConfigService
{
    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_GOOGLE_MAPS);
    }

    public function apiKey(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('api_key', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.google_maps.api_key', ''));
    }

    public function isReady(): bool
    {
        return $this->apiKey() !== '';
    }

    public function usesEnvFallback(): bool
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('api_key', ''));

        return $fromDb === '' && trim((string) config('services.google_maps.api_key', '')) !== '';
    }

    /**
     * @param  array{api_key?: mixed}  $input
     */
    public function saveConfig(bool $enabled, array $input): MediaStorageSetting
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_GOOGLE_MAPS);
        $secrets = $row->secretsArray();
        $key = is_string($input['api_key'] ?? null) ? trim($input['api_key']) : '';
        if ($key !== '' && $key !== '••••••••' && ! str_starts_with($key, '••••')) {
            $secrets['api_key'] = $key;
        }

        $row->fill([
            'enabled' => $enabled,
            'settings' => $row->settingsArray(),
            'secrets' => $secrets,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_GOOGLE_MAPS);

        return [
            'enabled' => (bool) $row->enabled || $this->usesEnvFallback() || ! $row->hasSecret('api_key'),
            'api_key_masked' => $row->maskedSecret('api_key'),
            'ready' => $this->isReady(),
            'uses_env_fallback' => $this->usesEnvFallback(),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            return ['ok' => false, 'message' => __('Google Maps API キーを入力してください。')];
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders([
                    'Referer' => rtrim((string) config('app.url'), '/').'/',
                ])
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => 'Fukuoka',
                    'key' => $key,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('接続に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 160)])];
        }

        $json = $response->json();
        $status = is_array($json) ? (string) ($json['status'] ?? '') : '';
        $error = is_array($json) ? (string) ($json['error_message'] ?? '') : '';

        if (in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            return ['ok' => true, 'message' => __('Google Maps API への接続に成功しました。')];
        }

        if ($status === 'OVER_QUERY_LIMIT') {
            return ['ok' => true, 'message' => __('キーは有効ですが、いまは利用上限に達しています。')];
        }

        if ($status === 'REQUEST_DENIED') {
            $lower = mb_strtolower($error);
            if (
                str_contains($lower, 'referer')
                || str_contains($lower, 'referrer')
                || str_contains($lower, 'browser')
                || str_contains($lower, 'ip address')
            ) {
                return [
                    'ok' => true,
                    'message' => __('キーは受け付けられました。HTTP リファラ制限があるためサーバーからの完全な検証はできません。Map 画面で表示を確認してください。'),
                ];
            }
        }

        $detail = $error !== '' ? $error : ($status !== '' ? $status : $response->body());

        return [
            'ok' => false,
            'message' => __('Google Maps API エラー: :msg', ['msg' => mb_substr((string) $detail, 0, 200)]),
        ];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_GOOGLE_MAPS);
        $row->fill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'fail',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
        $row->save();
    }
}
