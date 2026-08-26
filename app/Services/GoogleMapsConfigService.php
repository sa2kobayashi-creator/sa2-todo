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
                    'Content-Type' => 'application/json',
                    'X-Goog-Api-Key' => $key,
                    'Referer' => rtrim((string) config('app.url'), '/').'/',
                ])
                ->post('https://places.googleapis.com/v1/places:autocomplete', [
                    'input' => '博多駅',
                    'includedRegionCodes' => ['jp'],
                    'languageCode' => 'ja',
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('接続に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 160)])];
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $error = $this->googleErrorMessage($json, $response->body());
        $lower = mb_strtolower($error);

        if ($response->successful()) {
            return ['ok' => true, 'message' => __('Google Maps API への接続に成功しました。')];
        }

        if (str_contains($lower, 'not activated') || str_contains($lower, 'enable this api')) {
            return [
                'ok' => false,
                'message' => __('Places API (New) が未有効です。Google マップ用キーのプロジェクトで Places API (New) を有効にしてください。'),
            ];
        }

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

        $detail = $error !== '' ? $error : ('HTTP '.$response->status());

        return [
            'ok' => false,
            'message' => __('Google Maps API エラー: :msg', ['msg' => mb_substr($detail, 0, 200)]),
        ];
    }

    /** @param array<string, mixed> $json */
    private function googleErrorMessage(array $json, string $fallback): string
    {
        $error = $json['error'] ?? null;
        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return $error['message'];
        }
        if (isset($json['error_message']) && is_string($json['error_message'])) {
            return $json['error_message'];
        }

        return $fallback;
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
