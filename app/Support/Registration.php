<?php

namespace App\Support;

use App\Models\MediaStorageSetting;

final class Registration
{
    public static function isOpen(): bool
    {
        return self::inviteCode() !== '';
    }

    /**
     * 招待コード。管理画面（DB）が優先。未設定なら .env の REGISTRATION_INVITE_CODE。
     * 空文字は登録閉鎖。
     */
    public static function inviteCode(): string
    {
        $fromDb = self::inviteCodeFromDatabase();
        if ($fromDb !== null) {
            return $fromDb;
        }

        return trim((string) config('registration.invite_code', ''));
    }

    /**
     * DB に管理画面から保存済みならその値（空も含む）。未保存なら null。
     */
    public static function inviteCodeFromDatabase(): ?string
    {
        try {
            $row = MediaStorageSetting::query()
                ->where('provider', MediaStorageSetting::PROVIDER_REGISTRATION)
                ->first();
            if (! $row) {
                return null;
            }

            return trim((string) data_get($row->settingsArray(), 'invite_code', ''));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isConfiguredInDatabase(): bool
    {
        return self::inviteCodeFromDatabase() !== null;
    }

    public static function setInviteCode(?string $code): void
    {
        $code = trim((string) $code);
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_REGISTRATION);
        $settings = $row->settingsArray();
        $settings['invite_code'] = $code;
        $row->enabled = $code !== '';
        $row->settings = $settings;
        $row->save();
    }

    public static function codeMatches(?string $given): bool
    {
        $expected = self::inviteCode();
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, trim((string) $given));
    }
}
