<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class TravelpayoutsConfigService
{
    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_TRAVELPAYOUTS);
    }

    public function token(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('token', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.travelpayouts.token', ''));
    }

    public function projectId(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('project_id', ''));
        if ($fromDb === '') {
            // 旧キー互換
            $fromDb = trim((string) $row->secret('marker', ''));
        }
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.travelpayouts.project_id', ''));
    }

    public function preferAirline(): string
    {
        $fromDb = strtoupper(trim((string) $this->configRow()->setting('prefer_airline', '')));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return strtoupper(trim((string) config('services.travelpayouts.prefer_airline', '')));
    }

    public function directOnly(): bool
    {
        $row = $this->configRow();
        if (array_key_exists('direct_only', $row->settingsArray())) {
            return (bool) $row->setting('direct_only', true);
        }

        return (bool) config('services.travelpayouts.direct_only', true);
    }

    public function marketPhp(): string
    {
        $fromDb = trim((string) $this->configRow()->setting('market_php', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('services.travelpayouts.market_php', 'ph')) ?: 'ph';
    }

    public function marketJpy(): string
    {
        $fromDb = trim((string) $this->configRow()->setting('market_jpy', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('services.travelpayouts.market_jpy', 'jp')) ?: 'jp';
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('services.travelpayouts.base_url', 'https://api.travelpayouts.com'), '/');
    }

    public function isReady(): bool
    {
        $row = $this->configRow();

        return $row->enabled && $this->token() !== '';
    }

    /**
     * @param  array{
     *   token?: mixed,
     *   project_id?: mixed,
     *   prefer_airline?: mixed,
     *   direct_only?: mixed,
     *   market_php?: mixed,
     *   market_jpy?: mixed
     * }  $input
     */
    public function saveConfig(bool $enabled, array $input): MediaStorageSetting
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_TRAVELPAYOUTS);
        $secrets = $row->secretsArray();
        $settings = $row->settingsArray();

        foreach (['token', 'project_id'] as $key) {
            $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if ($value !== '' && $value !== '••••••••' && ! str_starts_with($value, '••••')) {
                $secrets[$key] = $value;
            }
        }

        $settings['prefer_airline'] = strtoupper(trim((string) ($input['prefer_airline'] ?? '')));

        $settings['direct_only'] = filter_var($input['direct_only'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $marketPhp = trim((string) ($input['market_php'] ?? ''));
        if ($marketPhp !== '') {
            $settings['market_php'] = $marketPhp;
        }

        $marketJpy = trim((string) ($input['market_jpy'] ?? ''));
        if ($marketJpy !== '') {
            $settings['market_jpy'] = $marketJpy;
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
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_TRAVELPAYOUTS);
        $settings = $row->settingsArray();

        return [
            'enabled' => (bool) $row->enabled,
            'token_masked' => $row->maskedSecret('token'),
            'project_id_masked' => $row->hasSecret('project_id')
                ? $row->maskedSecret('project_id')
                : $row->maskedSecret('marker'),
            'prefer_airline' => $this->preferAirline(),
            'direct_only' => $this->directOnly(),
            'market_php' => $this->marketPhp(),
            'market_jpy' => $this->marketJpy(),
            'ready' => $this->isReady(),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $token = $this->token();
        if ($token === '') {
            return ['ok' => false, 'message' => __('Travelpayouts API トークンを入力してください。')];
        }

        $departOn = Carbon::now()->addDays(30)->format('Y-m-d');

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Access-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->get($this->baseUrl().'/aviasales/v3/prices_for_dates', [
                    'origin' => 'HND',
                    'destination' => 'FUK',
                    'departure_at' => $departOn,
                    'one_way' => 'true',
                    'direct' => $this->directOnly() ? 'true' : 'false',
                    'sorting' => 'price',
                    'unique' => 'false',
                    'cy' => 'jpy',
                    'currency' => 'jpy',
                    'market' => $this->marketJpy(),
                    'limit' => 1,
                    'page' => 1,
                    'token' => $token,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('接続に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 160)])];
        }

        if ($response->successful()) {
            $json = $response->json();
            if (is_array($json) && ! empty($json['success'])) {
                return ['ok' => true, 'message' => __('Travelpayouts API への接続に成功しました。')];
            }

            $error = is_array($json) ? ($json['error'] ?? $json['message'] ?? null) : null;

            return [
                'ok' => false,
                'message' => __('Travelpayouts API エラー: :msg', ['msg' => mb_substr((string) ($error ?: $response->body()), 0, 200)]),
            ];
        }

        return [
            'ok' => false,
            'message' => __('Travelpayouts API エラー: :msg', ['msg' => mb_substr($response->body(), 0, 200)]),
        ];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_TRAVELPAYOUTS);
        $row->fill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'fail',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
        $row->save();
    }
}
