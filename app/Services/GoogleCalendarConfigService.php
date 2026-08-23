<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Support\Facades\Http;

class GoogleCalendarConfigService
{
    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_GOOGLE_CALENDAR);
    }

    public function clientId(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->setting('client_id', ''));
        if ($fromDb === '') {
            $fromDb = trim((string) $row->secret('client_id', ''));
        }
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.google.client_id', ''));
    }

    public function clientSecret(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('client_secret', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.google.client_secret', ''));
    }

    public function redirectUri(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->setting('redirect_uri', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        $configured = trim((string) config('services.google.redirect', ''));
        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/auth/google/calendar/callback';
    }

    public function isReady(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->redirectUri() !== '';
    }

    public function usesEnvFallback(): bool
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->setting('client_id', ''));
        if ($fromDb === '') {
            $fromDb = trim((string) $row->secret('client_id', ''));
        }

        return $fromDb === '' && trim((string) config('services.google.client_id', '')) !== '';
    }

    /**
     * @param  array{client_id?: mixed, client_secret?: mixed, redirect_uri?: mixed}  $input
     */
    public function saveConfig(bool $enabled, array $input): MediaStorageSetting
    {
        $row = $this->configRow();
        $settings = $row->settingsArray();
        $secrets = $row->secretsArray();

        $clientId = is_string($input['client_id'] ?? null) ? trim($input['client_id']) : '';
        if ($clientId !== '' && $clientId !== '••••••••' && ! str_starts_with($clientId, '••••')) {
            $settings['client_id'] = $clientId;
        }

        $secret = is_string($input['client_secret'] ?? null) ? trim($input['client_secret']) : '';
        if ($secret !== '' && $secret !== '••••••••' && ! str_starts_with($secret, '••••')) {
            $secrets['client_secret'] = $secret;
        }

        $redirect = is_string($input['redirect_uri'] ?? null) ? trim($input['redirect_uri']) : '';
        if ($redirect !== '') {
            $settings['redirect_uri'] = $redirect;
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
        $storedId = trim((string) $row->setting('client_id', ''));

        return [
            'enabled' => (bool) $row->enabled || $this->usesEnvFallback() || $storedId === '',
            'client_id' => $storedId !== '' ? $storedId : ($this->usesEnvFallback() ? '' : $this->clientId()),
            'client_secret_masked' => $row->maskedSecret('client_secret'),
            'redirect_uri' => $this->redirectUri(),
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
        $clientId = $this->clientId();
        $secret = $this->clientSecret();
        if ($clientId === '' || $secret === '') {
            return ['ok' => false, 'message' => __('Google Calendar の Client ID と Client Secret を入力してください。')];
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post('https://oauth2.googleapis.com/token', [
                    'code' => 'sa2-settings-test',
                    'client_id' => $clientId,
                    'client_secret' => $secret,
                    'redirect_uri' => $this->redirectUri(),
                    'grant_type' => 'authorization_code',
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('接続に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 160)])];
        }

        $error = (string) $response->json('error', '');
        if ($error === 'invalid_client') {
            return ['ok' => false, 'message' => __('Client ID または Client Secret が正しくありません。')];
        }

        if (in_array($error, ['invalid_grant', 'redirect_uri_mismatch'], true) || $response->successful()) {
            $message = $error === 'redirect_uri_mismatch'
                ? __('認証情報は受け付けられました。リダイレクト URI を Google Cloud の承認済みリダイレクト URI に追加してください。')
                : __('Google Calendar OAuth の認証情報は受け付けられました。マイページから連携して確認してください。');

            return ['ok' => true, 'message' => $message];
        }

        $detail = $error !== '' ? $error : mb_substr((string) $response->body(), 0, 200);

        return [
            'ok' => false,
            'message' => __('Google Calendar OAuth エラー: :msg', ['msg' => $detail]),
        ];
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
