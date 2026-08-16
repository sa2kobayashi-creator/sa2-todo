<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Minishlink\WebPush\VAPID;

class WebPushConfigService
{
    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_WEB_PUSH);
    }

    public function subject(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->setting('subject', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.web_push.subject', ''));
    }

    public function storedSubject(): string
    {
        $fromDb = trim((string) $this->configRow()->setting('subject', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('services.web_push.subject', ''));
    }

    public function publicKey(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('public_key', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.web_push.public_key', ''));
    }

    public function privateKey(): string
    {
        $row = $this->configRow();
        $fromDb = trim((string) $row->secret('private_key', ''));
        if ($fromDb !== '') {
            return $row->enabled ? $fromDb : '';
        }

        return trim((string) config('services.web_push.private_key', ''));
    }

    public function isReady(): bool
    {
        return $this->subject() !== ''
            && $this->publicKey() !== ''
            && $this->privateKey() !== '';
    }

    /**
     * @param  array{subject?: mixed, public_key?: mixed, private_key?: mixed}  $input
     */
    public function saveConfig(bool $enabled, array $input): MediaStorageSetting
    {
        $row = $this->configRow();
        $secrets = $row->secretsArray();
        $settings = $row->settingsArray();

        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject !== '') {
            $settings['subject'] = $subject;
        }

        foreach (['public_key', 'private_key'] as $key) {
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

    /** @return array{publicKey: string, privateKey: string} */
    public function generateKeys(): array
    {
        $keys = VAPID::createVapidKeys();

        return [
            'publicKey' => (string) ($keys['publicKey'] ?? ''),
            'privateKey' => (string) ($keys['privateKey'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = $this->configRow();

        return [
            'enabled' => (bool) $row->enabled,
            'subject' => $this->storedSubject(),
            'public_key_masked' => $row->maskedSecret('public_key'),
            'private_key_masked' => $row->maskedSecret('private_key'),
            'ready' => $this->isReady(),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function testConfig(): array
    {
        $subject = $this->storedSubject();
        $publicKey = trim((string) $this->configRow()->secret('public_key', ''))
            ?: trim((string) config('services.web_push.public_key', ''));
        $privateKey = trim((string) $this->configRow()->secret('private_key', ''))
            ?: trim((string) config('services.web_push.private_key', ''));

        if ($subject === '' || $publicKey === '' || $privateKey === '') {
            return ['ok' => false, 'message' => __('Subject / 公開鍵 / 秘密鍵を入力して保存してください。')];
        }

        if (! $this->configRow()->enabled && trim((string) $this->configRow()->secret('public_key', '')) !== '') {
            return ['ok' => false, 'message' => __('認証情報は保存されていますが「有効にする」がオフです。チェックを入れて保存してください。')];
        }

        if (! preg_match('#^(mailto:|https?://).+#i', $subject)) {
            return ['ok' => false, 'message' => __('Subject は mailto: または https:// で始めてください。')];
        }

        try {
            VAPID::validate([
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => __('VAPID 鍵の検証に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 200)]),
            ];
        }

        return ['ok' => true, 'message' => __('Web Push（VAPID）設定は有効です。')];
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
