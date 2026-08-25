<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Http;

/**
 * NAVITIME API の接続設定。
 *
 * 契約形態が2通りあるため mode で切り替える。
 * - rapidapi: APIマーケット（RapidAPI / API Hub）。ホスト名 + X-RapidAPI-Key
 * - direct  : ナビタイムジャパンとの直接契約。https://{HOST}/{CID}/v1 と任意の認証ヘッダ
 */
class NavitimeConfigService
{
    public const MODE_RAPIDAPI = 'rapidapi';

    public const MODE_DIRECT = 'direct';

    public const DEFAULT_ROUTE_HOST = 'navitime-route-totalnavi.p.rapidapi.com';

    public const DEFAULT_AUTH_HEADER = 'x-api-key';

    public function row(): MediaStorageSetting
    {
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_NAVITIME);
    }

    public function isReady(): bool
    {
        return $this->routeBaseUrl() !== '' && ($this->mode() === self::MODE_DIRECT || $this->apiKey() !== '');
    }

    public function mode(): string
    {
        $row = $this->row();
        $fromDb = self::normalizeMode((string) $row->setting('mode', ''));
        if ($row->enabled && (string) $row->setting('mode', '') !== '') {
            return $fromDb;
        }

        return self::normalizeMode((string) config('services.navitime.mode', self::MODE_RAPIDAPI));
    }

    public function apiKey(): string
    {
        $row = $this->row();
        $fromDb = trim((string) $row->secret('api_key', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.navitime.api_key', ''));
    }

    public function routeHost(): string
    {
        $row = $this->row();
        $fromDb = self::normalizeHost((string) $row->setting('route_host', ''));
        if ($fromDb !== '' && $row->enabled) {
            return $fromDb;
        }

        $fromEnv = self::normalizeHost((string) config('services.navitime.route_host', ''));

        return $fromEnv !== '' ? $fromEnv : self::DEFAULT_ROUTE_HOST;
    }

    /** 地点名 → 緯度経度の解決に使うホスト（未設定なら Google Maps で解決する） */
    public function nodeHost(): string
    {
        $row = $this->row();
        $fromDb = self::normalizeHost((string) $row->setting('node_host', ''));
        if ($fromDb !== '' && $row->enabled) {
            return $fromDb;
        }

        return self::normalizeHost((string) config('services.navitime.node_host', ''));
    }

    /** 直接契約のベース URL（https://{HOST}/{CID}/v1） */
    public function directBaseUrl(): string
    {
        $row = $this->row();
        $fromDb = self::normalizeBaseUrl((string) $row->setting('base_url', ''));
        if ($fromDb !== '' && $row->enabled) {
            return $fromDb;
        }

        return self::normalizeBaseUrl((string) config('services.navitime.base_url', ''));
    }

    public function authHeader(): string
    {
        $row = $this->row();
        $fromDb = trim((string) $row->setting('auth_header', ''));
        if ($fromDb !== '' && $row->enabled) {
            return $fromDb;
        }

        return trim((string) config('services.navitime.auth_header', self::DEFAULT_AUTH_HEADER));
    }

    public function routeBaseUrl(): string
    {
        if ($this->mode() === self::MODE_DIRECT) {
            return $this->directBaseUrl();
        }

        $host = $this->routeHost();

        return $host !== '' ? 'https://'.$host : '';
    }

    public function nodeBaseUrl(): string
    {
        if ($this->mode() === self::MODE_DIRECT) {
            return $this->directBaseUrl();
        }

        $host = $this->nodeHost();

        return $host !== '' ? 'https://'.$host : '';
    }

    public function timeout(): int
    {
        return max(5, min(60, (int) config('services.navitime.timeout', 20)));
    }

    public static function normalizeMode(string $raw): string
    {
        return trim(strtolower($raw)) === self::MODE_DIRECT ? self::MODE_DIRECT : self::MODE_RAPIDAPI;
    }

    /** URL や curl 例を貼られてもホスト名だけ拾う */
    public static function normalizeHost(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('#([a-z0-9.-]+\.p\.rapidapi\.com)#i', $raw, $m)) {
            return strtolower($m[1]);
        }
        $raw = preg_replace('#^https?://#i', '', $raw) ?? $raw;
        $raw = explode('/', $raw)[0];

        return strtolower(trim($raw));
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
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_NAVITIME);
        $mergedSecrets = $row->secretsArray();
        $key = trim((string) ($secrets['api_key'] ?? ''));
        if ($key !== '' && ! str_starts_with($key, '••••')) {
            $mergedSecrets['api_key'] = $key;
        }

        $row->fill([
            'enabled' => $enabled,
            'settings' => [
                'mode' => self::normalizeMode((string) ($settings['mode'] ?? '')),
                'route_host' => self::normalizeHost((string) ($settings['route_host'] ?? '')),
                'node_host' => self::normalizeHost((string) ($settings['node_host'] ?? '')),
                'base_url' => self::normalizeBaseUrl((string) ($settings['base_url'] ?? '')),
                'auth_header' => trim((string) ($settings['auth_header'] ?? '')) ?: self::DEFAULT_AUTH_HEADER,
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
    public function get(string $path, array $query, bool $useNodeEndpoint = false): array
    {
        $base = $useNodeEndpoint ? $this->nodeBaseUrl() : $this->routeBaseUrl();
        if ($base === '') {
            return [
                'ok' => false,
                'data' => [],
                'message' => __('NAVITIME が未設定です。設定 → API設定 で契約情報を入れてください。'),
            ];
        }
        if ($this->mode() === self::MODE_RAPIDAPI && $this->apiKey() === '') {
            return [
                'ok' => false,
                'data' => [],
                'message' => __('NAVITIME の API キーが未設定です。'),
            ];
        }

        try {
            $res = Http::withHeaders($this->headers($useNodeEndpoint))
                ->timeout($this->timeout())
                ->acceptJson()
                ->get($base.'/'.ltrim($path, '/'), $query);
        } catch (\Throwable $e) {
            return ['ok' => false, 'data' => [], 'message' => mb_substr($e->getMessage(), 0, 300)];
        }

        if (! $res->successful()) {
            return [
                'ok' => false,
                'data' => [],
                'message' => $this->errorMessage($res->status(), $res->body()),
            ];
        }

        $payload = $res->json();
        if (! is_array($payload)) {
            return ['ok' => false, 'data' => [], 'message' => __('NAVITIME の応答を解析できませんでした。')];
        }

        return ['ok' => true, 'data' => $payload, 'message' => ''];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        // 東京駅 → 新宿駅。1件だけ取得して疎通と権限を確認する
        $result = $this->get('route_transit', [
            'start' => '35.681236,139.767125',
            'goal' => '35.690921,139.700258',
            'start_time' => now()->format('Y-m-d\TH:i:s'),
            'limit' => 1,
            'datum' => 'wgs84',
            'coord_unit' => 'degree',
        ]);

        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message']];
        }

        $count = is_array($result['data']['items'] ?? null) ? count($result['data']['items']) : 0;
        if ($count === 0) {
            return ['ok' => false, 'message' => __('接続できましたが経路が返りませんでした。契約プランを確認してください。')];
        }

        return ['ok' => true, 'message' => __('NAVITIME に接続できました')];
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_NAVITIME);

        return [
            'enabled' => (bool) $row->enabled,
            'settings' => [
                'mode' => self::normalizeMode((string) $row->setting('mode', config('services.navitime.mode', self::MODE_RAPIDAPI))),
                'route_host' => (string) $row->setting('route_host', '') ?: self::DEFAULT_ROUTE_HOST,
                'node_host' => (string) $row->setting('node_host', ''),
                'base_url' => (string) $row->setting('base_url', ''),
                'auth_header' => (string) $row->setting('auth_header', '') ?: self::DEFAULT_AUTH_HEADER,
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
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_NAVITIME);
        $row->fill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'fail',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
        $row->save();
    }

    /** @return array<string, string> */
    private function headers(bool $useNodeEndpoint): array
    {
        $key = $this->apiKey();
        if ($this->mode() === self::MODE_DIRECT) {
            $header = $this->authHeader();

            return ($header !== '' && $key !== '') ? [$header => $key] : [];
        }

        $host = $useNodeEndpoint ? $this->nodeHost() : $this->routeHost();

        return [
            'X-RapidAPI-Key' => $key,
            'X-RapidAPI-Host' => $host,
        ];
    }

    private function errorMessage(int $status, string $body): string
    {
        $hint = match (true) {
            $status === 401 || $status === 403 => __('NAVITIME の認証に失敗しました。API キーと、そのキーで契約中の API か確認してください。'),
            $status === 404 => __('NAVITIME のエンドポイントが見つかりません。ホスト名（またはベース URL）を確認してください。'),
            $status === 429 => __('NAVITIME の利用回数上限に達しました。プランの上限を確認してください。'),
            $status >= 500 => __('NAVITIME 側で一時的なエラーが発生しました。'),
            default => '',
        };

        $detail = mb_substr(trim($body), 0, 160);
        if ($hint !== '' && $detail !== '') {
            return $hint.'（HTTP '.$status.'）';
        }

        return $hint !== '' ? $hint : __('NAVITIME エラー（HTTP :status）:detail', [
            'status' => $status,
            'detail' => $detail !== '' ? ' '.$detail : '',
        ]);
    }
}
