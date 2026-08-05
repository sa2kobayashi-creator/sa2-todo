<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Http;

class FacebookMessagingConfigService
{
    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_FACEBOOK);
    }

    public function storedPageAccessToken(): string
    {
        $fromDb = $this->sanitizeSecret((string) $this->configRow()->secret('page_access_token', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return $this->sanitizeSecret((string) config('services.facebook.page_access_token', ''));
    }

    public function pageAccessToken(): string
    {
        $row = $this->configRow();
        $fromDb = $this->sanitizeSecret((string) $row->secret('page_access_token', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return $this->sanitizeSecret((string) config('services.facebook.page_access_token', ''));
    }

    public function appSecret(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('app_secret', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.facebook.app_secret', ''));
    }

    public function verifyToken(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('verify_token', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.facebook.verify_token', ''));
    }

    public function appId(): string
    {
        $fromDb = trim((string) $this->configRow()->setting('app_id', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('services.facebook.app_id', ''));
    }

    public function pageName(): string
    {
        $fromDb = trim((string) $this->configRow()->setting('page_name', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('services.facebook.page_name', ''));
    }

    public function isReady(): bool
    {
        return $this->pageAccessToken() !== ''
            && $this->appSecret() !== ''
            && $this->verifyToken() !== '';
    }

    /**
     * @param  array{
     *   page_access_token?: mixed,
     *   app_secret?: mixed,
     *   verify_token?: mixed,
     *   app_id?: mixed,
     *   page_name?: mixed
     * }  $input
     */
    public function saveConfig(bool $enabled, array $input): MediaStorageSetting
    {
        $row = $this->configRow();
        $secrets = $row->secretsArray();
        $settings = $row->settingsArray();

        foreach (['page_access_token', 'app_secret', 'verify_token'] as $key) {
            $raw = is_string($input[$key] ?? null) ? $input[$key] : '';
            $value = $key === 'verify_token' ? trim($raw) : $this->sanitizeSecret($raw);
            if ($value !== '' && $value !== '••••••••' && ! str_starts_with($value, '••••')) {
                $secrets[$key] = $value;
            }
        }

        $settings['app_id'] = trim((string) ($input['app_id'] ?? ''));
        $settings['page_name'] = trim((string) ($input['page_name'] ?? ''));

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
            'page_access_token_masked' => $row->maskedSecret('page_access_token'),
            'app_secret_masked' => $row->maskedSecret('app_secret'),
            'verify_token_masked' => $row->maskedSecret('verify_token'),
            'app_id' => $this->appId(),
            'page_name' => $this->pageName(),
            'webhook_url' => url('/webhooks/messenger'),
            'ready' => $this->isReady(),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $token = $this->storedPageAccessToken();
        if ($token === '') {
            return ['ok' => false, 'message' => __('Page Access Token を入力して保存してください。')];
        }

        if (! $this->configRow()->enabled) {
            return ['ok' => false, 'message' => __('トークンは保存されていますが「有効にする」がオフです。チェックを入れて保存してください。')];
        }

        if (! str_starts_with($token, 'EAA') && strlen($token) < 50) {
            return ['ok' => false, 'message' => __('Page Access Token の形式が正しくありません。Messenger の「トークンを生成」で出した長いトークン（通常 EAA で始まる）を貼り付けてください。App Secret や Client Token ではありません。')];
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get('https://graph.facebook.com/v21.0/me', [
                    'access_token' => $token,
                    'fields' => 'id,name',
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('接続に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 160)])];
        }

        if ($response->successful()) {
            $name = (string) ($response->json('name') ?? '');
            if ($name !== '') {
                $row = $this->configRow();
                $settings = $row->settingsArray();
                if (trim((string) ($settings['page_name'] ?? '')) === '') {
                    $settings['page_name'] = $name;
                    $row->fill(['settings' => $settings]);
                    $row->save();
                }
            }

            return [
                'ok' => true,
                'message' => $name !== ''
                    ? __('Facebook ページに接続できました（:name）。', ['name' => $name])
                    : __('Facebook ページに接続できました。'),
            ];
        }

        $body = (string) $response->body();
        $hint = '';
        if (str_contains($body, 'Cannot parse access token') || str_contains($body, '"code":190')) {
            $hint = ' '. __('App Secret / Client Token / ユーザーアクセストークンではなく、ページの Page Access Token を貼ってください。');
        }

        return [
            'ok' => false,
            'message' => __('Facebook API エラー: :msg', ['msg' => mb_substr($body, 0, 200)]).$hint,
        ];
    }

    private function sanitizeSecret(string $value): string
    {
        $value = trim($value);
        $value = trim($value, "\"'`");
        if (str_starts_with($value, 'Bearer ') || str_starts_with($value, 'bearer ')) {
            $value = trim(substr($value, 7));
        }

        return preg_replace('/\s+/u', '', $value) ?? $value;
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
