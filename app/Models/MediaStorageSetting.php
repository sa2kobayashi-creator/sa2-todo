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

    protected $fillable = [
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

    public static function forProvider(string $provider): self
    {
        // 起動時・テスト migrate 前にテーブルが無いのは想定内。report しない。
        if (! static::tableExists()) {
            return static::unavailable($provider);
        }

        try {
            return static::query()->firstOrCreate(
                ['provider' => $provider],
                ['enabled' => false, 'settings' => [], 'secrets' => []]
            );
        } catch (\Throwable $e) {
            // 復号失敗などは報告。テーブル欠如は再度黙ってフォールバック。
            if (! static::isMissingTableError($e)) {
                report($e);
            }

            return static::unavailable($provider);
        }
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
