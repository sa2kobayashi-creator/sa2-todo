<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class MediaStorageSetting extends Model
{
    public const PROVIDER_R2 = 'r2';

    public const PROVIDER_CLOUDINARY = 'cloudinary';

    public const PROVIDER_BACKBLAZE = 'backblaze';

    public const PROVIDER_STABILITY = 'stability';

    public const PROVIDER_PIPELINE = 'pipeline';

    /** 鮮明化の使用プロバイダ切替（Stability / Real-ESRGAN / SwinIR） */
    public const PROVIDER_ENHANCE = 'enhance';

    public const PROVIDER_REMINI = 'remini';

    public const PROVIDER_SWINIR = 'swinir';

    public const PROVIDER_REALESRGAN = 'realesrgan';

    /** ChatGPT / Gemini（入出金音声入力など） */
    public const PROVIDER_LLM = 'llm';

    /** DeepL 使用量・料金表示の設定 */
    public const PROVIDER_DEEPL = 'deepl';

    /** YouTube Data API（検索） */
    public const PROVIDER_YOUTUBE = 'youtube';

    /** Travelpayouts（航空運賃） */
    public const PROVIDER_TRAVELPAYOUTS = 'travelpayouts';

    /** Google Maps JavaScript / Places（Map・Transit） */
    public const PROVIDER_GOOGLE_MAPS = 'google_maps';

    /** Google Calendar OAuth（アプリの Client ID / Secret） */
    public const PROVIDER_GOOGLE_CALENDAR = 'google_calendar';

    /** LINE Messaging API（ToDo 通知） */
    public const PROVIDER_LINE = 'line';

    /** Facebook Page / Messenger（ToDo 通知） */
    public const PROVIDER_FACEBOOK = 'facebook';

    /** LiveKit Cloud / self-hosted（メッセージDM通話） */
    public const PROVIDER_LIVEKIT = 'livekit';

    /** Web Push / Android TWA 着信通知（VAPID） */
    public const PROVIDER_WEB_PUSH = 'web_push';

    /** 新規登録の招待コード（管理画面で設定） */
    public const PROVIDER_REGISTRATION = 'registration';

    /** 契約者には渡さない（ドメイン・Webhook・試作） */
    public const PLATFORM_ONLY_PROVIDERS = [
        self::PROVIDER_WEB_PUSH,
        self::PROVIDER_REGISTRATION,
        self::PROVIDER_LINE,
        self::PROVIDER_FACEBOOK,
        self::PROVIDER_ENHANCE,
        self::PROVIDER_STABILITY,
        self::PROVIDER_REMINI,
        self::PROVIDER_SWINIR,
        self::PROVIDER_REALESRGAN,
    ];

    protected $fillable = [
        'tenant_id',
        'tenant_scope',
        'provider',
        'enabled',
        'settings',
        'secrets',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
            'secrets' => 'encrypted:array',
            'last_tested_at' => 'datetime',
        ];
    }

    public static function tableExists(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    /** マイグレーション前など、DB 行が使えないときのメモリ上フォールバック */
    public static function unavailable(string $provider): self
    {
        $row = new static([
            'provider' => $provider,
            'enabled' => false,
            'settings' => [],
            'secrets' => [],
        ]);
        $row->exists = false;

        return $row;
    }

    public static function isPlatformOnly(string $provider): bool
    {
        return in_array($provider, self::PLATFORM_ONLY_PROVIDERS, true);
    }

    public static function currentTenantIdForProvider(string $provider): ?int
    {
        if (self::isPlatformOnly($provider)) {
            return null;
        }

        return \App\Support\TenantContext::idOrNull();
    }

    public static function currentScopeForProvider(string $provider): int
    {
        return self::currentTenantIdForProvider($provider) ?? 0;
    }

    /** 設定画面用。テナント未保存なら空行（運営キーは見せない） */
    public static function forProvider(string $provider): self
    {
        if (! static::tableExists()) {
            return static::unavailable($provider);
        }

        $scope = self::currentScopeForProvider($provider);
        $tenantId = self::currentTenantIdForProvider($provider);

        try {
            $row = static::query()
                ->where('tenant_scope', $scope)
                ->where('provider', $provider)
                ->first();
            if ($row) {
                return $row;
            }
            if ($scope === 0) {
                return static::query()->firstOrCreate(
                    ['tenant_scope' => 0, 'provider' => $provider],
                    [
                        'tenant_id' => null,
                        'enabled' => false,
                        'settings' => [],
                        'secrets' => [],
                    ]
                );
            }

            return static::unavailable($provider);
        } catch (\Throwable $e) {
            if (! static::isMissingTableError($e)) {
                report($e);
            }

            return static::unavailable($provider);
        }
    }

    /** 保存用。現在の契約（または運営）の行を必ず作る */
    public static function writeForProvider(string $provider): self
    {
        if (! static::tableExists()) {
            return static::unavailable($provider);
        }

        $tenantId = self::currentTenantIdForProvider($provider);
        $scope = $tenantId ?? 0;

        try {
            return static::query()->firstOrCreate(
                ['tenant_scope' => $scope, 'provider' => $provider],
                [
                    'tenant_id' => $tenantId,
                    'enabled' => false,
                    'settings' => [],
                    'secrets' => [],
                ]
            );
        } catch (\Throwable $e) {
            if (! static::isMissingTableError($e)) {
                report($e);
            }

            return static::unavailable($provider);
        }
    }

    /** 実行時。テナント未設定なら運営キーへフォールバック */
    public static function forUse(string $provider): self
    {
        $row = static::forProvider($provider);
        $tenantId = self::currentTenantIdForProvider($provider);
        if ($tenantId === null) {
            return $row;
        }
        if ($row->exists && ($row->enabled || $row->hasAnySecret())) {
            return $row;
        }

        try {
            return static::query()->firstOrCreate(
                ['tenant_scope' => 0, 'provider' => $provider],
                [
                    'tenant_id' => null,
                    'enabled' => false,
                    'settings' => [],
                    'secrets' => [],
                ]
            );
        } catch (\Throwable $e) {
            if (! static::isMissingTableError($e)) {
                report($e);
            }

            return static::unavailable($provider);
        }
    }

    public function hasAnySecret(): bool
    {
        foreach ($this->secretsArray() as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    protected static function booted(): void
    {
        static::saving(function (self $row) {
            $user = auth()->user();
            if (! $user instanceof \App\Models\User || ! $user->isTenantAdmin()) {
                return;
            }
            $provider = (string) $row->provider;
            if (self::isPlatformOnly($provider)) {
                abort(403, __('この設定は運営のみが変更できます。'));
            }
            if (! $user->canManageOwnKeys()) {
                abort(403, __('この契約では外部サービスの鍵を設定できません。'));
            }
        });
    }

    private static function isMissingTableError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'no such table')
            || (str_contains($message, 'media_storage_settings')
                && str_contains(strtolower($message), 'does not exist'));
    }

    /** @return array<string, mixed> */
    public function settingsArray(): array
    {
        try {
            return is_array($this->settings) ? $this->settings : [];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return array<string, mixed> */
    public function secretsArray(): array
    {
        try {
            $value = $this->secrets;

            return is_array($value) ? $value : [];
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            report($e);
            $this->attributes['secrets'] = null;
            if ($this->exists) {
                try {
                    static::query()->whereKey($this->getKey())->update(['secrets' => null]);
                } catch (\Throwable) {
                    // ignore cleanup failure
                }
            }

            return [];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settingsArray(), $key, $default);
    }

    public function secret(string $key, mixed $default = null): mixed
    {
        return data_get($this->secretsArray(), $key, $default);
    }

    public function hasSecret(string $key): bool
    {
        $value = $this->secret($key);

        return is_string($value) && $value !== '';
    }

    public function maskedSecret(string $key): string
    {
        if (! $this->hasSecret($key)) {
            return '';
        }

        return '••••••••';
    }
}
