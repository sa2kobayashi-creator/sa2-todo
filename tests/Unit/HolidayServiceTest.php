<?php

namespace Tests\Unit;

use App\Services\HolidayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_philippine_holidays_include_independence_and_heroes_day_2026(): void
    {
        $items = app(HolidayService::class)->computePhilippineHolidays(2026);
        $byDate = [];
        foreach ($items as $item) {
            $byDate[$item['date']] = $item['name'];
        }

        $this->assertSame('独立記念日', $byDate['2026-06-12']);
        $this->assertSame('国民英雄の日', $byDate['2026-08-31']);
        $this->assertSame('聖木曜日', $byDate['2026-04-02']);
        $this->assertSame('聖金曜日', $byDate['2026-04-03']);
        $this->assertSame('旧正月', $byDate['2026-02-17']);
    }

    public function test_import_philippine_holidays_registers_entries_for_calendar(): void
    {
        $service = app(HolidayService::class);
        $added = $service->importNationalHolidays(2026, 'ph');

        $this->assertGreaterThan(10, $added);

        $map = $service->getHolidayInfoMapForYear(2026);
        $this->assertSame('独立記念日', $map['2026-06-12']['name']);
        $this->assertSame('national_ph', $map['2026-06-12']['source']);
        $this->assertSame('国民英雄の日', $map['2026-08-31']['name']);
    }
}
