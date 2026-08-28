<?php

namespace App\Enums;

enum RegistrationApplicationPlan: string
{
    case Light = 'light';
    case Standard = 'standard';
    case Tenant = 'tenant';

    public function label(): string
    {
        return match ($this) {
            self::Light => 'ライト（無料）',
            self::Standard => 'スタンダード',
            self::Tenant => 'テナント契約',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Light => 'お試しのみ。約20GB。目的必須・週50人上限・自動審査。',
            self::Standard => '本利用向け。約200GB。運営承認後にカード登録（最初の14日は無料）。',
            self::Tenant => '家族・小組織向け。運営承認後に環境を用意します。',
        };
    }

    /** @return list<self> */
    public static function applyable(): array
    {
        return [self::Standard, self::Tenant, self::Light];
    }
}
