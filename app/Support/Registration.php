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
            $query = MediaStorageSetting::query()
                ->where('provider', MediaStorageSetting::PROVIDER_REGISTRATION);
            if (MediaStorageSetting::hasTenantScopeColumn()) {
                $query->where('tenant_scope', 0);
            }
            $row = $query->first();
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
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_REGISTRATION);
        $settings = $row->settingsArray();
        $settings['invite_code'] = $code;
        $row->enabled = $code !== '';
        $row->settings = $settings;
        $row->save();
    }

    /**
     * TOP／利用申請（/apply）の受付。公開販売のチェックで保存。
     * DB 未設定時は config（既定 true）＝既存公開を壊さない。
     */
    public static function applicationsOpen(): bool
    {
        try {
            $query = MediaStorageSetting::query()
                ->where('provider', MediaStorageSetting::PROVIDER_REGISTRATION);
            if (MediaStorageSetting::hasTenantScopeColumn()) {
                $query->where('tenant_scope', 0);
            }
            $row = $query->first();
            if ($row) {
                $settings = $row->settingsArray();
                if (array_key_exists('applications_open', $settings)) {
                    return (bool) $settings['applications_open'];
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return (bool) config('registration.applications_open', true);
    }

    public static function setApplicationsOpen(bool $open): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_REGISTRATION);
        $settings = $row->settingsArray();
        $settings['applications_open'] = $open;
        $row->settings = $settings;
        // enabled は招待コード用。ここでは触らない
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

    /** 読みやすく、紛らわしい文字（0/O, 1/I/L）を除いたランダムコード */
    public static function generateInviteCode(): string
    {
        $chars = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $parts = [];
        for ($p = 0; $p < 3; $p++) {
            $part = '';
            for ($i = 0; $i < 4; $i++) {
                $part .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $parts[] = $part;
        }

        return implode('-', $parts);
    }

    /** 相手に送る案内文。コードが空なら空文字 */
    public static function invitationMessage(?string $code = null): string
    {
        $code = trim($code ?? self::inviteCode());
        if ($code === '') {
            return '';
        }

        return __(':app への招待です。\n登録ページ: :url\n招待コード: :code\n\n上記ページでコードを入力して登録してください。', [
            'app' => (string) config('app.name'),
            'url' => url('/register'),
            'code' => $code,
        ]);
    }
}
