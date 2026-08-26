<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Http;

/**
 * Google Maps Routes API の接続設定。
 *
 * 専用キーが空のときは、有効化されていれば既存の Google マップキーを流用する。
 */
class GoogleRoutesConfigService
{
    public const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public const FIELD_MASK = 'routes.duration,routes.distanceMeters,routes.legs.steps.travelMode,routes.legs.steps.staticDuration,routes.legs.steps.transitDetails,routes.travelAdvisory.transitFare';

    public function __construct(private GoogleMapsConfigService $googleMaps) {}

    public function row(): MediaStorageSetting
    {
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_GOOGLE_ROUTES);
    }

    public function isReady(): bool
    {
        return $this->apiKey() !== '';
    }

    public function apiKey(): string
    {
        $row = $this->row();
        $fromDb = trim((string) $row->secret('api_key', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        $fromEnv = trim((string) config('services.google_routes.api_key', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        return $row->enabled ? $this->googleMaps->apiKey() : '';
    }

    public static function japanTransitUnsupportedMessage(): string
    {
        return __('Google は日本の交通機関ルートが API の提供対象外です。キーは有効でも電車・バスは返りません。路線検索は NAVITIME または駅すぱあと を設定するか、内蔵 RAPTOR（福岡都心）を使ってください。');
    }

    public function usesMapsKeyFallback(): bool
    {
        $row = $this->row();
        $own = trim((string) $row->secret('api_key', ''));
        $env = trim((string) config('services.google_routes.api_key', ''));

        return $own === '' && $env === '' && $row->enabled && $this->googleMaps->apiKey() !== '';
    }

    public function timeout(): int
    {
        return max(5, min(60, (int) config('services.google_routes.timeout', 20)));
    }

    /**
     * @param  array<string, mixed>  $secrets
     */
    public function save(bool $enabled, array $secrets): MediaStorageSetting
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_GOOGLE_ROUTES);
        $merged = $row->secretsArray();
        $key = trim((string) ($secrets['api_key'] ?? ''));
        if ($key !== '' && ! str_starts_with($key, '••••')) {
            $merged['api_key'] = $key;
        }

        $row->fill([
            'enabled' => $enabled,
            'settings' => $row->settingsArray(),
            'secrets' => $merged,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, data: array<string, mixed>, message: string, status: int}
     */
    public function computeRoutes(array $body, ?string $fieldMask = null): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            return [
                'ok' => false,
                'data' => [],
                'message' => __('Google Maps Routes API が未設定です。設定 → API設定 でキーを入れてください。'),
                'status' => 0,
            ];
        }

        try {
            $res = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => $fieldMask ?: self::FIELD_MASK,
            ])
                ->timeout($this->timeout())
                ->acceptJson()
                ->post(self::ENDPOINT, $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'data' => [], 'message' => mb_substr($e->getMessage(), 0, 300), 'status' => 0];
        }

        $payload = $res->json();
        $payload = is_array($payload) ? $payload : [];

        if (! $res->successful()) {
            return [
                'ok' => false,
                'data' => $payload,
                'message' => $this->errorMessage($res->status(), $payload, $res->body()),
                'status' => $res->status(),
            ];
        }

        return ['ok' => true, 'data' => $payload, 'message' => '', 'status' => $res->status()];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $result = $this->computeRoutes([
            'origin' => ['address' => '東京駅'],
            'destination' => ['address' => '新宿駅'],
            'travelMode' => 'TRANSIT',
            'computeAlternativeRoutes' => false,
            'languageCode' => 'ja',
            'regionCode' => 'JP',
        ]);

        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message']];
        }

        $count = is_array($result['data']['routes'] ?? null) ? count($result['data']['routes']) : 0;
        if ($count === 0) {
            return ['ok' => true, 'message' => self::japanTransitUnsupportedMessage()];
        }

        return ['ok' => true, 'message' => __('Google Maps Routes API に接続できました')];
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_GOOGLE_ROUTES);

        return [
            'enabled' => (bool) $row->enabled,
            'has_api_key' => $row->hasSecret('api_key'),
            'api_key_masked' => $row->maskedSecret('api_key'),
            'uses_maps_key_fallback' => $this->usesMapsKeyFallback(),
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
        ];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_GOOGLE_ROUTES);
        $row->fill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'fail',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
        $row->save();
    }

    /** @param array<string, mixed> $payload */
    private function errorMessage(int $status, array $payload, string $body): string
    {
        $apiMessage = (string) data_get($payload, 'error.message', '');
        $hint = match (true) {
            $status === 401 || $status === 403 => __('Google Maps Routes API の認証に失敗しました。API キーと、そのキーで Routes API が有効か確認してください。'),
            $status === 404 => __('Google Maps Routes API のエンドポイントが見つかりません。'),
            $status === 429 => __('Google Maps Routes API の利用回数上限に達しました。'),
            $status >= 500 => __('Google Maps Routes API 側で一時的なエラーが発生しました。'),
            default => '',
        };

        if ($hint !== '') {
            return $hint;
        }

        $detail = mb_substr(trim($apiMessage !== '' ? $apiMessage : $body), 0, 160);

        return __('Google Maps Routes API エラー（HTTP :status）:detail', [
            'status' => $status,
            'detail' => $detail !== '' ? ' '.$detail : '',
        ]);
    }
}
