<?php

namespace Tests\Unit;

use App\Services\DedicatedInstanceEstimateService;
use Tests\TestCase;

class DedicatedInstanceEstimateServiceTest extends TestCase
{
    public function test_default_estimate_matches_commercial_config(): void
    {
        config([
            'commercial.setup_fee_yen' => 50000,
            'commercial.monthly_base_yen' => 8000,
            'commercial.included_users' => 5,
            'commercial.extra_user_yen_monthly' => 1000,
        ]);

        $estimate = app(DedicatedInstanceEstimateService::class)->build([
            'users' => 5,
            'include_mailbox' => false,
            'maintenance_cycle' => 'monthly',
        ]);

        $this->assertSame(50000, $estimate['setupFee']);
        $this->assertSame(8000, $estimate['monthlyMaintenance']);
        $this->assertSame(8000, $estimate['recurringTotal']);
        $this->assertSame(0, $estimate['extraUsers']);
    }

    public function test_extra_users_and_mailbox_are_added(): void
    {
        config([
            'commercial.setup_fee_yen' => 50000,
            'commercial.monthly_base_yen' => 8000,
            'commercial.included_users' => 5,
            'commercial.extra_user_yen_monthly' => 1000,
            'commercial.mailbox_yen_monthly' => 300,
            'commercial.yearly_maintenance_months_charged' => 11,
            'commercial.mailbox_yen_yearly' => 3000,
        ]);

        $monthly = app(DedicatedInstanceEstimateService::class)->build([
            'users' => 7,
            'include_mailbox' => true,
            'maintenance_cycle' => 'monthly',
        ]);

        $this->assertSame(2, $monthly['extraUsers']);
        $this->assertSame(10000, $monthly['monthlyMaintenance']);
        $this->assertSame(10300, $monthly['recurringTotal']);

        $yearly = app(DedicatedInstanceEstimateService::class)->build([
            'users' => 7,
            'include_mailbox' => true,
            'maintenance_cycle' => 'yearly',
        ]);

        $this->assertSame(10000 * 11 + 3000, $yearly['recurringTotal']);
    }
}
