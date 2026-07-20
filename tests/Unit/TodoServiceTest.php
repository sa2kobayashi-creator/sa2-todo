<?php

namespace Tests\Unit;

use App\Services\GroupService;
use App\Services\HolidayService;
use App\Services\TodoService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class TodoServiceTest extends TestCase
{
    private TodoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TodoService(
            $this->createMock(HolidayService::class),
            $this->createMock(GroupService::class),
        );
    }

    public function test_default_time_range_rounds_up_to_next_30_minutes(): void
    {
        $cases = [
            ['12:55', '13:00', '14:00'],
            ['13:01', '13:30', '14:30'],
            ['13:31', '14:00', '15:00'],
            ['13:00', '13:00', '14:00'],
            ['13:30', '13:30', '14:30'],
        ];

        foreach ($cases as [$input, $expectedStart, $expectedEnd]) {
            [$h, $m] = array_map('intval', explode(':', $input));
            $now = Carbon::create(2026, 7, 8, $h, $m, 0);
            $range = $this->service->defaultTimeRange($now);

            $this->assertSame($expectedStart, $range['start'], "start for {$input}");
            $this->assertSame($expectedEnd, $range['end'], "end for {$input}");
        }
    }

    public function test_normalize_time_accepts_valid_hh_mm(): void
    {
        $this->assertSame('09:05', $this->service->normalizeTime('09:05'));
        $this->assertSame('23:59', $this->service->normalizeTime('23:59'));
    }

    public function test_normalize_time_rejects_invalid_values(): void
    {
        $this->assertNull($this->service->normalizeTime(''));
        $this->assertNull($this->service->normalizeTime('24:00'));
        $this->assertNull($this->service->normalizeTime('9:5'));
        $this->assertNull($this->service->normalizeTime('invalid'));
    }

    public function test_normalize_time_range_swaps_when_start_is_after_end(): void
    {
        $range = $this->service->normalizeTimeRange('15:00', '10:00');

        $this->assertSame('10:00', $range['startTime']);
        $this->assertSame('15:00', $range['endTime']);
    }

    public function test_normalize_time_range_clears_end_when_only_start_given(): void
    {
        $range = $this->service->normalizeTimeRange('10:00', null);

        $this->assertSame('10:00', $range['startTime']);
        $this->assertNull($range['endTime']);
    }

    public function test_expand_dates_by_weekdays_returns_matching_weekdays_in_range(): void
    {
        // 2026-07-06 is Monday (1), 2026-07-12 is Sunday (0)
        $dates = $this->service->expandDatesByWeekdays('2026-07-06', '2026-07-12', [1, 3, 5]);

        $this->assertSame([
            '2026-07-06',
            '2026-07-08',
            '2026-07-10',
        ], $dates);
    }
}
