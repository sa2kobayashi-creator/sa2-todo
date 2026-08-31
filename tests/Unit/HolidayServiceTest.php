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

    public function test_japanese_holidays_include_citizen_holiday_between_aged_and_equinox_2026(): void
    {
        $items = app(HolidayService::class)->computeJapaneseNationalHolidays(2026);
        $byDate = [];
        foreach ($items as $item) {
            $byDate[$item['date']] = $item['name'];
        }

        $this->assertSame('敬老の日', $byDate['2026-09-21']);
        $this->assertSame('国民の休日', $byDate['2026-09-22']);
        $this->assertSame('秋分の日', $byDate['2026-09-23']);
    }

    public function test_japanese_holidays_include_substitute_when_holiday_falls_on_sunday(): void
    {
        // 2026-02-11 建国記念の日は水曜日。2023-02-11 は土曜、2024-02-11 は日曜 → 振替 2/12
        $items = app(HolidayService::class)->computeJapaneseNationalHolidays(2024);
        $byDate = [];
        foreach ($items as $item) {
            $byDate[$item['date']] = $item['name'];
        }

        $this->assertSame('建国記念の日', $byDate['2024-02-11']);
        $this->assertSame('振替休日', $byDate['2024-02-12']);
    }

    public function test_import_japanese_holidays_adds_missing_citizen_holiday(): void
    {
        $service = app(HolidayService::class);
        $service->importNationalHolidays(2026, 'jp');

        $map = $service->getHolidayInfoMapForYear(2026);
        $this->assertSame('国民の休日', $map['2026-09-22']['name']);
        $this->assertSame('national', $map['2026-09-22']['source']);
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
