<?php

namespace App\Support;

class CommercialOffer
{
    public static function includedUsers(): int
    {
        return max(1, (int) config('commercial.included_users', 5));
    }

    public static function extraUserYen(): int
    {
        return max(0, (int) config('commercial.extra_user_yen_monthly', 1000));
    }

    public static function tenantMonthlyYen(): int
    {
        return max(0, (int) config('commercial.tenant_monthly_yen', 3980));
    }

    public static function tenantTrialDays(): int
    {
        return max(0, (int) config('commercial.tenant_trial_days', 30));
    }

    public static function yearlyMonthsCharged(): int
    {
        return max(1, min(12, (int) config('commercial.yearly_maintenance_months_charged', 11)));
    }

    public static function tenantYearlyYen(): int
    {
        $override = (int) config('commercial.tenant_yearly_yen', 0);
        if ($override > 0) {
            return $override;
        }

        return self::tenantMonthlyYen() * self::yearlyMonthsCharged();
    }

    public static function defaultTrialEndsAt(): ?string
    {
        $days = self::tenantTrialDays();
        if ($days < 1) {
            return null;
        }

        return now()->addDays($days)->toDateString();
    }

    public static function yenLabel(int $yen): string
    {
        return '¥'.number_format(max(0, $yen));
    }
}
