<?php

namespace Tests\Unit;

use App\Support\CommercialOffer;
use Tests\TestCase;

class CommercialOfferTest extends TestCase
{
    public function test_tenant_public_price_is_cheaper_than_dedicated_and_has_a_trial(): void
    {
        $this->assertSame(3980, CommercialOffer::tenantMonthlyYen());
        $this->assertSame(30, CommercialOffer::tenantTrialDays());
        $this->assertSame(43780, CommercialOffer::tenantYearlyYen());
        $this->assertSame('¥3,980', CommercialOffer::yenLabel(3980));
        $this->assertLessThan((int) config('commercial.monthly_base_yen'), CommercialOffer::tenantMonthlyYen());
        $this->assertNotNull(CommercialOffer::defaultTrialEndsAt());
    }
}
