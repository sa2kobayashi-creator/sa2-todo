<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Http;

/**
 * 駅すぱあと Webサービスの接続設定。
 */
class EkispertConfigService
{
    public const DEFAULT_BASE_URL = 'https://api.ekispert.jp/v1/json';

    public function row(): MediaStorageSetting
    {
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_EKISPERT);
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

        return trim((string) config('services.ekispert.api_key', ''));
    }

    public function baseUrl(): string
    {
        $row = $this->row();
        $fromDb = self::normalizeBaseUrl((string) $row->setting('base_url', ''));
        if ($fromDb !== '' && $row->enabled) {
            return $fromDb;
        }

        $fromEnv = self::normalizeBaseUrl((string) config('services.ekispert.base_url', ''));

        return $fromEnv !== '' ? $fromEnv : self::DEFAULT_BASE_URL;
    }

    public function timeout(): int
    {
        return max(5, min(60, (int) config('services.ekispert.timeout', 20)));
    }

    public static function normalizeBaseUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://'.$raw;
        }

        return rtrim($raw, '/');
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $secrets
     */
    public function save(bool $enabled, array $settings, array $secrets): MediaStorageSetting
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_EKISPERT);
        $mergedSecrets = $row->secretsArray();
        $key = trim((string) ($secrets['api_key'] ?? ''));
        if ($key !== '' && ! str_starts_with($key, '••••')) {
            $mergedSecrets['api_key'] = $key;
        }

        $row->fill([
            'enabled' => $enabled,
            'settings' => [
                'base_url' => self::normalizeBaseUrl((string) ($settings['base_url'] ?? '')) ?: self::DEFAULT_BASE_URL,
            ],
            'secrets' => $mergedSecrets,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, data: array<string, mixed>, message: string}
     */
    public function get(string $path, array $query): array
    {
        $key = $this->apiKey();
        $base = $this->baseUrl();
        if ($key === '' || $base === '') {
            return [
                'ok' => false,
                'data' => [],
                'message' => __('駅すぱあとが未設定です。設定 → API設定 でアクセスキーを入れてください。'),
            ];
        }

        $query['key'] = $key;

        try {
            $res = Http::timeout($this->timeout())
                ->acceptJson()
                ->get($base.'/'.ltrim($path, '/'), $query);
        } catch (\Throwable $e) {
            return ['ok' => false, 'data' => [], 'message' => mb_substr($e->getMessage(), 0, 300)];
        }

        $payload = $res->json();
        $payload = is_array($payload) ? $payload : [];

        if (! $res->successful()) {
            return [
                'ok' => false,
                'data' => [],
                'message' => $this->errorMessage($res->status(), $payload, $res->body()),
            ];
        }

        $error = data_get($payload, 'ResultSet.Error');
        if (is_array($error) && $error !== []) {
            $message = (string) ($error['Message'] ?? $error['code'] ?? '');

            return [
                'ok' => false,
                'data' => $payload,
                'message' => $message !== '' ? $message : __('駅すぱあとがエラーを返しました。'),
            ];
        }

        return ['ok' => true, 'data' => $payload, 'message' => ''];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $result = $this->get('search/course/extreme', [
            'from' => '東京',
            'to' => '新宿',
            'answerCount' => 1,
        ]);

        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message']];
        }

        $courses = data_get($result['data'], 'ResultSet.Course');
        if ($courses === null || $courses === []) {
            return ['ok' => false, 'message' => __('接続できましたが経路が返りませんでした。契約プランを確認してください。')];
        }

        return ['ok' => true, 'message' => __('駅すぱあと に接続できました')];
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_EKISPERT);

        return [
            'enabled' => (bool) $row->enabled,
            'settings' => [
                'base_url' => (string) $row->setting('base_url', '') ?: self::DEFAULT_BASE_URL,
            ],
            'has_api_key' => $row->hasSecret('api_key'),
            'api_key_masked' => $row->maskedSecret('api_key'),
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
        ];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_EKISPERT);
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
        $hint = match (true) {
            $status === 401 || $status === 403 => __('駅すぱあとの認証に失敗しました。アクセスキーを確認してください。'),
            $status === 404 => __('駅すぱあとのエンドポイントが見つかりません。ベース URL を確認してください。'),
            $status === 429 => __('駅すぱあとの利用回数上限に達しました。プランの上限を確認してください。'),
            $status >= 500 => __('駅すぱあと側で一時的なエラーが発生しました。'),
            default => '',
        };

        if ($hint !== '') {
            return $hint;
        }

        $apiMessage = (string) data_get($payload, 'ResultSet.Error.Message', '');
        $detail = mb_substr(trim($apiMessage !== '' ? $apiMessage : $body), 0, 160);

        return __('駅すぱあとエラー（HTTP :status）:detail', [
            'status' => $status,
            'detail' => $detail !== '' ? ' '.$detail : '',
        ]);
    }
}
