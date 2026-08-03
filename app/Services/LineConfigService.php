<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class LineConfigService
{
    private const QR_DIR = 'line';

    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_LINE);
    }

    public function webhookUrl(): string
    {
        return url('/webhooks/line');
    }

    public function channelAccessToken(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('channel_access_token', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.line.channel_access_token', ''));
    }

    public function channelSecret(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('channel_secret', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.line.channel_secret', ''));
    }

    public function botBasicId(): string
    {
        $fromDb = trim((string) $this->configRow()->setting('bot_basic_id', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('services.line.bot_basic_id', ''));
    }

    public function qrCodePath(): string
    {
        return trim((string) $this->configRow()->setting('qr_code_path', ''));
    }

    public function qrCodeUrl(): ?string
    {
        $path = $this->qrCodePath();
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function qrCodeFilename(): string
    {
        $path = $this->qrCodePath();

        return $path !== '' ? basename($path) : '';
    }

    public function isReady(): bool
    {
        return $this->channelAccessToken() !== '' && $this->channelSecret() !== '';
    }

    /**
     * @param  array{channel_access_token?: mixed, channel_secret?: mixed, bot_basic_id?: mixed}  $input
     */
    public function saveConfig(bool $enabled, array $input, ?UploadedFile $qrCode = null): MediaStorageSetting
    {
        $row = $this->configRow();
        $secrets = $row->secretsArray();
        $settings = $row->settingsArray();

        foreach (['channel_access_token', 'channel_secret'] as $key) {
            $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if ($value !== '' && $value !== '••••••••' && ! str_starts_with($value, '••••')) {
                $secrets[$key] = $value;
            }
        }

        $settings['bot_basic_id'] = trim((string) ($input['bot_basic_id'] ?? $settings['bot_basic_id'] ?? ''));
        // 表示用。受信処理は常に APP_URL + /webhooks/line を使う
        $settings['webhook_url_note'] = $this->webhookUrl();

        if ($qrCode instanceof UploadedFile) {
            $settings['qr_code_path'] = $this->storeQrCode($qrCode, $settings['qr_code_path'] ?? null);
        }

        $row->fill([
            'enabled' => $enabled,
            'settings' => $settings,
            'secrets' => $secrets,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    public function disable(): MediaStorageSetting
    {
        $row = $this->configRow();
        $row->fill(['enabled' => false]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    public function deleteQrCode(): void
    {
        $row = $this->configRow();
        $settings = $row->settingsArray();
        $path = trim((string) ($settings['qr_code_path'] ?? ''));
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
        unset($settings['qr_code_path']);
        $row->fill(['settings' => $settings]);
        $row->save();
    }

    private function storeQrCode(UploadedFile $file, ?string $previousPath = null): string
    {
        if (is_string($previousPath) && $previousPath !== '' && Storage::disk('public')->exists($previousPath)) {
            Storage::disk('public')->delete($previousPath);
        }

        $name = 'line_qr_code_'.time().'.'.$file->getClientOriginalExtension();

        return $file->storeAs(self::QR_DIR, $name, 'public');
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = $this->configRow();
        $hasSecrets = $row->hasSecret('channel_access_token') && $row->hasSecret('channel_secret');

        return [
            'enabled' => (bool) $row->enabled,
            'channel_access_token_masked' => $row->maskedSecret('channel_access_token'),
            'channel_secret_masked' => $row->maskedSecret('channel_secret'),
            'has_secrets' => $hasSecrets,
            'bot_basic_id' => $this->botBasicId(),
            'webhook_url' => $this->webhookUrl(),
            'qr_code_url' => $this->qrCodeUrl(),
            'qr_code_filename' => $this->qrCodeFilename(),
            'ready' => $this->isReady(),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $token = $this->channelAccessToken();
        if ($token === '') {
            return ['ok' => false, 'message' => __('Channel Access Token を入力してください。')];
        }

        try {
            $response = Http::timeout(15)
                ->withToken($token)
                ->acceptJson()
                ->get('https://api.line.me/v2/bot/info');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('接続に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 160)])];
        }

        if ($response->successful()) {
            $name = (string) ($response->json('displayName') ?? '');

            return [
                'ok' => true,
                'message' => $name !== ''
                    ? __('LINE Bot に接続できました（:name）。', ['name' => $name])
                    : __('LINE Bot に接続できました。'),
            ];
        }

        return [
            'ok' => false,
            'message' => __('LINE API エラー: :msg', ['msg' => mb_substr($response->body(), 0, 200)]),
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
