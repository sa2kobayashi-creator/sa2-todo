<?php

namespace App\Support;

use App\Models\MediaStorageSetting;

final class Registration
{
    /** @return list<string> */
    public static function applicationPlanKeys(): array
    {
        return ['light', 'standard', 'tenant', 'dedicated'];
    }

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

    /** @return list<string> /apply で申し込めるプラン */
    public static function applyablePlanKeys(): array
    {
        return ['light', 'standard', 'tenant'];
    }

    /**
     * /apply 受付（ライト／スタンダード／テナントのいずれかが開いているか）。
     * 専用インスタンスは相談導線のため含めない。プラン別は applicationsOpenFor()。
     */
    public static function applicationsOpen(): bool
    {
        foreach (self::applyablePlanKeys() as $plan) {
            if (self::applicationsOpenFor($plan)) {
                return true;
            }
        }

        return false;
    }

    /** 最初に開いている申請可能プラン（なければ null） */
    public static function firstOpenApplyablePlan(): ?string
    {
        foreach (self::applyablePlanKeys() as $plan) {
            if (self::applicationsOpenFor($plan)) {
                return $plan;
            }
        }

        return null;
    }

    /** 全プランが受付中か（バナー「一部準備中」判定の逆）。 */
    public static function applicationsFullyOpen(): bool
    {
        foreach (self::applicationPlanKeys() as $plan) {
            if (! self::applicationsOpenFor($plan)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, bool>
     */
    public static function applicationsOpenByPlan(): array
    {
        $out = [];
        foreach (self::applicationPlanKeys() as $plan) {
            $out[$plan] = self::applicationsOpenFor($plan);
        }

        return $out;
    }

    public static function applicationsOpenFor(string $plan): bool
    {
        $plan = strtolower(trim($plan));
        if (! in_array($plan, self::applicationPlanKeys(), true)) {
            return false;
        }

        $settings = self::registrationSettings();
        $planKey = 'applications_open_'.$plan;
        if (is_array($settings) && array_key_exists($planKey, $settings)) {
            return (bool) $settings[$planKey];
        }

        // 旧・一括フラグからの移行
        if (is_array($settings) && array_key_exists('applications_open', $settings)) {
            return (bool) $settings['applications_open'];
        }

        return (bool) config('registration.applications_open', true);
    }

    /** @deprecated 一括。内部では全プランに同じ値を書く */
    public static function setApplicationsOpen(bool $open): void
    {
        $flags = [];
        foreach (self::applicationPlanKeys() as $plan) {
            $flags[$plan] = $open;
        }
        self::setApplicationsOpenByPlan($flags);
    }

    /**
     * @param  array<string, bool>  $openByPlan
     */
    public static function setApplicationsOpenByPlan(array $openByPlan): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_REGISTRATION);
        $settings = $row->settingsArray();
        $any = false;
        foreach (self::applicationPlanKeys() as $plan) {
            $open = (bool) ($openByPlan[$plan] ?? false);
            $settings['applications_open_'.$plan] = $open;
            $any = $any || $open;
        }
        // 旧コード互換
        $settings['applications_open'] = $any;
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

    /** @return array<string, mixed>|null */
    private static function registrationSettings(): ?array
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

            $settings = $row->settingsArray();

            return is_array($settings) ? $settings : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
