<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Stripe の鍵と price ID。設定 → 公開販売 が正。未保存のときは .env を使う。
 */
class StripeConfigService
{
    /** @var list<string> */
    public const SECRET_KEYS = ['stripe_key', 'stripe_secret', 'webhook_secret'];

    /** @var array<string, string> settings キー => config パス */
    public const PRICE_MAP = [
        'price_standard_monthly' => 'billing.plans.standard_monthly.price_id',
        'price_standard_yearly' => 'billing.plans.standard_yearly.price_id',
        'price_tenant_monthly' => 'billing.plans.tenant_monthly.price_id',
        'price_tenant_yearly' => 'billing.plans.tenant_yearly.price_id',
        'price_mailbox_monthly' => 'billing.addons.mailbox_monthly.price_id',
        'price_storage_overage' => 'billing.addons.storage_overage.price_id',
    ];

    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_STRIPE);
    }

    /**
     * DB に保存済みなら config を上書きする。空の行では env の値を残す。
     */
    public function applyRuntime(): void
    {
        $row = $this->configRow();
        if (! $row->exists) {
            return;
        }

        $overlay = [];
        if ($this->rowHasOwnConfig($row)) {
            $overlay['billing.enabled'] = (bool) $row->enabled;
        }

        $publishable = trim((string) $row->secret('stripe_key', ''));
        if ($publishable !== '') {
            $overlay['cashier.key'] = $publishable;
        }
        $secret = trim((string) $row->secret('stripe_secret', ''));
        if ($secret !== '') {
            $overlay['cashier.secret'] = $secret;
        }
        $webhook = trim((string) $row->secret('webhook_secret', ''));
        if ($webhook !== '') {
            $overlay['cashier.webhook.secret'] = $webhook;
        }

        foreach (self::PRICE_MAP as $settingKey => $configPath) {
            $priceId = trim((string) $row->setting($settingKey, ''));
            if ($priceId !== '') {
                $overlay[$configPath] = $priceId;
            }
        }

        if ($overlay !== []) {
            config($overlay);
        }
    }

    /** @param array<string, mixed> $input */
    public function save(bool $enabled, array $input): MediaStorageSetting
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_STRIPE);
        $secrets = $row->secretsArray();
        foreach (self::SECRET_KEYS as $key) {
            $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if ($value !== '' && $value !== '••••••••' && ! str_starts_with($value, '••••')) {
                $secrets[$key] = $value;
            }
        }

        $settings = $row->settingsArray();
        foreach (array_keys(self::PRICE_MAP) as $key) {
            $settings[$key] = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        }
        $settings['configured'] = true;

        $row->fill([
            'enabled' => $enabled,
            'settings' => $settings,
            'secrets' => $secrets,
        ]);
        $row->save();
        $this->applyRuntime();

        return $row->fresh() ?? $row;
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_STRIPE);
        $this->applyRuntime();

        $prices = [];
        foreach (array_keys(self::PRICE_MAP) as $key) {
            $fromDb = trim((string) $row->setting($key, ''));
            $prices[$key] = $fromDb !== '' ? $fromDb : (string) config(self::PRICE_MAP[$key], '');
        }

        return [
            'enabled' => (bool) config('billing.enabled', false),
            'stripe_key_masked' => $row->maskedSecret('stripe_key') ?: $this->maskIfPresent((string) config('cashier.key', '')),
            'stripe_secret_masked' => $row->maskedSecret('stripe_secret') ?: $this->maskIfPresent((string) config('cashier.secret', '')),
            'webhook_secret_masked' => $row->maskedSecret('webhook_secret') ?: $this->maskIfPresent((string) config('cashier.webhook.secret', '')),
            'has_publishable' => trim((string) config('cashier.key', '')) !== '',
            'has_secret' => trim((string) config('cashier.secret', '')) !== '',
            'has_webhook_secret' => trim((string) config('cashier.webhook.secret', '')) !== '',
            'uses_env_fallback' => $row->exists && ! $this->rowHasOwnConfig($row)
                && trim((string) config('cashier.secret', '')) !== '',
            'webhook_url' => url('/webhooks/stripe'),
            'webhook_events' => [
                'checkout.session.completed',
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted',
                'invoice.paid',
                'invoice.payment_failed',
            ],
            'prices' => $prices,
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $this->applyRuntime();
        $secret = trim((string) config('cashier.secret', ''));
        if ($secret === '') {
            return ['ok' => false, 'message' => __('シークレットキー（sk_ で始まる値）を保存してください。')];
        }

        try {
            $stripe = new StripeClient($secret);
            $stripe->balance->retrieve();
        } catch (ApiErrorException $e) {
            return ['ok' => false, 'message' => __('Stripe エラー: :msg', ['msg' => mb_substr($e->getMessage(), 0, 200)])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('接続に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 160)])];
        }

        return ['ok' => true, 'message' => __('Stripe への接続に成功しました。')];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_STRIPE);
        $row->fill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'fail',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
        $row->save();
    }

    private function rowHasOwnConfig(MediaStorageSetting $row): bool
    {
        return (bool) $row->setting('configured', false)
            || $row->hasAnySecret()
            || (bool) $row->enabled;
    }

    private function maskIfPresent(string $value): string
    {
        return trim($value) === '' ? '' : '••••••••';
    }
}
