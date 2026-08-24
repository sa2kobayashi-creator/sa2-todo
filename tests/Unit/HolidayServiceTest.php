<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\HolidayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_personal_holidays_merge_with_instance_but_stay_private(): void
    {
        $service = app(HolidayService::class);
        $owner = User::create([
            'email' => 'holiday-owner@example.com',
            'display_name' => 'Owner',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);
        $other = User::create([
            'email' => 'holiday-other@example.com',
            'display_name' => 'Other',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $service->addCustomHoliday('2026-01-02', '会社休み');
        $service->addCustomHoliday('2026-12-31', '私の休み', (int) $owner->id);

        $forOwner = $service->getHolidayInfoMapForYear(2026, (int) $owner->id);
        $this->assertSame('会社休み', $forOwner['2026-01-02']['name'] ?? null);
        $this->assertSame('私の休み', $forOwner['2026-12-31']['name'] ?? null);

        $instance = $service->getHolidayInfoMapForYear(2026);
        $this->assertSame('会社休み', $instance['2026-01-02']['name'] ?? null);
        $this->assertArrayNotHasKey('2026-12-31', $instance);

        $forOther = $service->getHolidayInfoMapForYear(2026, (int) $other->id);
        $this->assertSame('会社休み', $forOther['2026-01-02']['name'] ?? null);
        $this->assertArrayNotHasKey('2026-12-31', $forOther);
    }
}
