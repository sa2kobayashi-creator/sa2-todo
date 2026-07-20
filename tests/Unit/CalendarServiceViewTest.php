<?php

namespace Tests\Unit;

use App\Services\CalendarService;
use App\Services\DisplayService;
use App\Services\GroupService;
use App\Services\HolidayService;
use App\Services\TodoService;
use Tests\TestCase;

class CalendarServiceViewTest extends TestCase
{
    private CalendarService $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = new CalendarService(
            new TodoService(new HolidayService, new GroupService),
            new DisplayService
        );
    }

    public function test_default_view_is_month(): void
    {
        $state = $this->calendar->resolveCalendarState([]);
        $this->assertSame('month', $state['view']);
    }

    public function test_build_day_view_includes_hours_and_splits_timed_todos(): void
    {
        $todos = [[
            'id' => 1,
            'title' => '午前会議',
            'completed' => false,
            'startDate' => '2026-07-16',
            'endDate' => '2026-07-16',
            'startTime' => '09:00',
            'endTime' => '10:00',
            'importance' => 'medium',
            'category' => 'task',
        ], [
            'id' => 2,
            'title' => '終日タスク',
            'completed' => false,
            'startDate' => '2026-07-16',
            'endDate' => '2026-07-16',
            'startTime' => null,
            'endTime' => null,
            'importance' => 'medium',
            'category' => 'task',
        ]];

        $day = $this->calendar->buildDayView('2026-07-16', $todos);

        $this->assertCount(24, $day['hours']);
        $this->assertCount(1, $day['allDay']);
        $this->assertCount(1, $day['timed']);
        $this->assertSame('午前会議', $day['timed'][0]['title']);
        $this->assertArrayHasKey('layoutTop', $day['timed'][0]);
    }

    public function test_build_week_view_has_seven_days(): void
    {
        $week = $this->calendar->buildWeekView('2026-07-16', []);
        $this->assertCount(7, $week['days']);
        $this->assertSame('2026-07-12', $week['startDate']);
        $this->assertSame('2026-07-18', $week['endDate']);
    }

    public function test_build_year_view_has_twelve_months(): void
    {
        $year = $this->calendar->buildYearView(2026, []);
        $this->assertCount(12, $year['months']);
        $this->assertSame(2026, $year['year']);
    }

    public function test_dashboard_query_defaults_omit_month_view_param(): void
    {
        $this->assertSame('/dashboard?year=2026&month=7', $this->calendar->buildDashboardQuery('month', '2026-07-16'));
        $this->assertSame('/dashboard?view=day&date=2026-07-16', $this->calendar->buildDashboardQuery('day', '2026-07-16'));
    }
}
