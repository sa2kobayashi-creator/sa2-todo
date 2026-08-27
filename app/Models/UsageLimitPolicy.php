<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageLimitPolicy extends Model
{
    public const PLAN_LIGHT = 'light';

    public const PLAN_STANDARD = 'standard';

    public const PLAN_TENANT = 'tenant';

    public const PLAN_SPECIAL = 'special';

    public const PLAN_PLATFORM = 'platform';

    /** @return list<string> */
    public static function templatePlans(): array
    {
        return [self::PLAN_LIGHT, self::PLAN_STANDARD, self::PLAN_SPECIAL, self::PLAN_TENANT];
    }

    public static function templatePlanLabel(string $plan): string
    {
        return match ($plan) {
            self::PLAN_LIGHT => 'Light',
            self::PLAN_STANDARD => 'Standard',
            self::PLAN_SPECIAL => '特別枠',
            self::PLAN_TENANT => 'テナント（契約プール）',
            default => $plan,
        };
    }

    public static function accountPlanLabel(string $plan): string
    {
        return match ($plan) {
            self::PLAN_STANDARD => 'スタンダード',
            self::PLAN_SPECIAL => '特別枠',
            self::PLAN_TENANT => 'テナント契約',
            default => 'ライト',
        };
    }

    protected $fillable = [
        'plan',
        'limits',
    ];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
        ];
    }
}
