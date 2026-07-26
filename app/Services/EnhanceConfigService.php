<?php

namespace App\Services;

use App\Models\MediaStorageSetting;

class EnhanceConfigService
{
    public const PROVIDER_STABILITY = 'stability';

    public const PROVIDER_REALESRGAN = 'realesrgan';

    public const PROVIDER_SWINIR = 'swinir';

    /**
     * 当面利用停止（実装は残す）。選択・有効化不可。
     *
     * @var list<string>
     */
    public const TEMPORARILY_DISABLED_PROVIDERS = [
        self::PROVIDER_REALESRGAN,
        self::PROVIDER_SWINIR,
    ];

    /** @return list<string> */
    public function providers(): array
    {
        return [
            self::PROVIDER_STABILITY,
            self::PROVIDER_REALESRGAN,
            self::PROVIDER_SWINIR,
        ];
    }

    public function isTemporarilyDisabled(string $provider): bool
    {
        return in_array($provider, self::TEMPORARILY_DISABLED_PROVIDERS, true);
    }

    public function enhanceRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_ENHANCE);
    }

    public function activeProvider(): string
    {
        $provider = (string) $this->enhanceRow()->setting('active_provider', self::PROVIDER_STABILITY);

        if (! in_array($provider, $this->providers(), true) || $this->isTemporarilyDisabled($provider)) {
            return self::PROVIDER_STABILITY;
        }

        return $provider;
    }

    public function providerRow(string $provider): MediaStorageSetting
    {
        $map = [
            self::PROVIDER_STABILITY => MediaStorageSetting::PROVIDER_STABILITY,
            self::PROVIDER_REALESRGAN => MediaStorageSetting::PROVIDER_REALESRGAN,
            self::PROVIDER_SWINIR => MediaStorageSetting::PROVIDER_SWINIR,
        ];

        return MediaStorageSetting::forProvider($map[$provider] ?? MediaStorageSetting::PROVIDER_STABILITY);
    }

    public function isReady(?string $provider = null): bool
    {
        $provider = $provider ?: $this->activeProvider();
        if ($this->isTemporarilyDisabled($provider)) {
            return false;
        }

        $row = $this->providerRow($provider);
        if (! $row->enabled) {
            return false;
        }

        return match ($provider) {
            self::PROVIDER_STABILITY => $row->hasSecret('api_key'),
            self::PROVIDER_REALESRGAN => $this->realesrganBinaryConfigured($row),
            self::PROVIDER_SWINIR => $this->swinirEndpointConfigured($row),
            default => false,
        };
    }

    public function isImplemented(string $provider): bool
    {
        return in_array($provider, [
            self::PROVIDER_STABILITY,
            self::PROVIDER_REALESRGAN,
            self::PROVIDER_SWINIR,
        ], true);
    }

    /**
     * @return array{
     *   active_provider: string,
     *   ready: bool,
     *   providers: array<string, array<string, mixed>>
     * }
     */
    public function formState(): array
    {
        $providers = [];
        foreach ($this->providers() as $provider) {
            $providers[$provider] = $this->providerFormState($provider);
        }

        return [
            'active_provider' => $this->activeProvider(),
            'ready' => $this->isReady(),
            'providers' => $providers,
        ];
    }

    /** @return array<string, mixed> */
    public function providerFormState(string $provider): array
    {
        $row = $this->providerRow($provider);
        $settings = $row->settingsArray();
        $secrets = $row->secretsArray();
        $has = [];
        foreach (array_keys($secrets) as $key) {
            $has[$key] = $row->hasSecret((string) $key);
        }

        return [
            'enabled' => $this->isTemporarilyDisabled($provider) ? false : (bool) $row->enabled,
            'settings' => $settings,
            'hasSecrets' => $has,
            'implemented' => $this->isImplemented($provider),
            'temporarily_disabled' => $this->isTemporarilyDisabled($provider),
            'ready' => $this->isReady($provider),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
        ];
    }

    public function saveActiveProvider(string $provider): MediaStorageSetting
    {
        if (! in_array($provider, $this->providers(), true) || $this->isTemporarilyDisabled($provider)) {
            $provider = self::PROVIDER_STABILITY;
        }
        $row = $this->enhanceRow();
        $row->fill([
            'enabled' => true,
            'settings' => ['active_provider' => $provider],
            'secrets' => $row->secretsArray(),
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $secrets
     */
    public function saveProvider(string $provider, bool $enabled, array $settings, array $secrets = []): MediaStorageSetting
    {
        if (! in_array($provider, $this->providers(), true)) {
            throw new \InvalidArgumentException(__('不正なプロバイダです'));
        }

        if ($this->isTemporarilyDisabled($provider)) {
            $enabled = false;
        }

        return app(MediaStorageConfigService::class)->save(
            $this->storageProviderKey($provider),
            $enabled,
            $settings,
            $secrets
        );
    }

    /** @return array{ok: bool, message: string} */
    public function testProvider(string $provider): array
    {
        if (! in_array($provider, $this->providers(), true)) {
            return ['ok' => false, 'message' => __('不正なプロバイダです')];
        }

        if ($this->isTemporarilyDisabled($provider)) {
            return ['ok' => false, 'message' => __(':name は当面利用停止中です。', ['name' => $this->providerLabel($provider)])];
        }

        $result = match ($provider) {
            self::PROVIDER_STABILITY => app(StabilityAiService::class)->testConnection(),
            self::PROVIDER_REALESRGAN => app(RealEsrganService::class)->testConnection(),
            self::PROVIDER_SWINIR => app(SwinIrService::class)->testConnection(),
            default => ['ok' => false, 'message' => __('不正なプロバイダです')],
        };
        $this->recordTest($provider, $result['ok'], $result['message']);

        return $result;
    }

    public function providerLabel(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_STABILITY => 'Stability AI',
            self::PROVIDER_REALESRGAN => 'Real-ESRGAN',
            self::PROVIDER_SWINIR => 'SwinIR',
            default => $provider,
        };
    }

    private function storageProviderKey(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_STABILITY => MediaStorageSetting::PROVIDER_STABILITY,
            self::PROVIDER_REALESRGAN => MediaStorageSetting::PROVIDER_REALESRGAN,
            self::PROVIDER_SWINIR => MediaStorageSetting::PROVIDER_SWINIR,
            default => MediaStorageSetting::PROVIDER_STABILITY,
        };
    }

    private function realesrganBinaryConfigured(MediaStorageSetting $row): bool
    {
        $candidates = [
            trim((string) $row->setting('binary_path', '')),
            (string) config('photos.realesrgan_binary', ''),
            storage_path('app/bin/realesrgan-ncnn-vulkan.exe'),
            storage_path('app/bin/realesrgan-ncnn-vulkan'),
        ];

        foreach ($candidates as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            if (! preg_match('/^(?:[a-zA-Z]:[\\\\\\/]|\\\\\\\\|\\/)/', $path)) {
                $path = base_path($path);
            }
            $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    private function swinirEndpointConfigured(MediaStorageSetting $row): bool
    {
        $endpoint = trim((string) $row->setting('endpoint', ''));

        return $endpoint !== '' && (bool) preg_match('#^https?://#i', $endpoint);
    }

    private function recordTest(string $provider, bool $ok, string $message): void
    {
        $row = $this->providerRow($provider);
        $row->fill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'fail',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
        $row->save();
    }
}
