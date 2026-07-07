<?php

namespace App\Services;

use Carbon\Carbon;

class CalendarService
{
    public const WEEKDAY_LABELS = ['日', '月', '火', '水', '木', '金', '土'];

    public function __construct(
        private TodoService $todos,
        private DisplayService $display,
    ) {}

    public function resolveMonth(mixed $yearRaw, mixed $monthRaw): array
    {
        $now = Carbon::now(config('app.timezone', 'Asia/Tokyo'));
        $year = (int) $yearRaw;
        $month = (int) $monthRaw;
        if ($year < 1970 || $year > 2100) {
            $year = (int) $now->format('Y');
        }
        if ($month < 1 || $month > 12) {
            $month = (int) $now->format('n');
        }

        return ['year' => $year, 'month' => $month];
    }

    public function shiftMonth(int $year, int $month, int $delta): array
    {
        $date = Carbon::create($year, $month, 1)->addMonths($delta);

        return ['year' => (int) $date->format('Y'), 'month' => (int) $date->format('n')];
    }

    /** @param list<array<string, mixed>> $todos @param array<string, array{name: string, source: string}> $holidayMap */
    public function buildMonthGrid(int $year, int $month, array $todos, array $holidayMap = []): array
    {
        $todayStr = Carbon::now(config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d');
        $undated = [];
        foreach ($todos as $raw) {
            $todo = is_array($raw) ? $raw : $raw;
            if (empty($todo['startDate']) && empty($todo['endDate'])) {
                $undated[] = $todo;
            }
        }

        $first = Carbon::create($year, $month, 1);
        $daysInMonth = $first->daysInMonth;
        $startPad = $first->dayOfWeek;
        $weeks = [];
        $week = [];

        $prev = $this->shiftMonth($year, $month, -1);
        $prevDays = Carbon::create($prev['year'], $prev['month'], 1)->daysInMonth;
        for ($i = $startPad - 1; $i >= 0; $i--) {
            $day = $prevDays - $i;
            $date = sprintf('%04d-%02d-%02d', $prev['year'], $prev['month'], $day);
            $week[] = $this->makeCell($date, $day, false, $todayStr, $this->todosForDate($date, $todos), $holidayMap[$date] ?? null);
        }

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $week[] = $this->makeCell($date, $day, true, $todayStr, $this->todosForDate($date, $todos), $holidayMap[$date] ?? null);
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        if (count($week) > 0) {
            $next = $this->shiftMonth($year, $month, 1);
            $d = 1;
            while (count($week) < 7) {
                $date = sprintf('%04d-%02d-%02d', $next['year'], $next['month'], $d);
                $week[] = $this->makeCell($date, $d, false, $todayStr, $this->todosForDate($date, $todos), $holidayMap[$date] ?? null);
                $d++;
            }
            $weeks[] = $week;
        }

        return ['weeks' => $weeks, 'undated' => $undated, 'todayStr' => $todayStr];
    }

    /** @param list<array<string, mixed>> $activeNotes */
    public function attachNotesToGrid(array $grid, array $activeNotes, callable $getDate): array
    {
        $byDate = [];
        foreach ($activeNotes as $note) {
            $date = $getDate($note);
            $byDate[$date][] = $note;
        }
        foreach ($grid['weeks'] as &$week) {
            foreach ($week as &$cell) {
                $cell['notes'] = $byDate[$cell['date']] ?? [];
            }
        }

        return $grid;
    }

    /** @param list<array<string, mixed>> $todos */
    private function todosForDate(string $date, array $todos): array
    {
        $filtered = [];
        foreach ($todos as $todo) {
            if ($this->todos->dateInRange($date, $todo)) {
                $filtered[] = $todo;
            }
        }
        usort($filtered, fn ($a, $b) => $this->display->compareTodosByDayTime($a, $b));

        return $filtered;
    }

    /** @param array{name: string, source: string}|null $holidayInfo */
    private function makeCell(string $date, int $day, bool $inMonth, string $todayStr, array $cellTodos, ?array $holidayInfo): array
    {
        return [
            'date' => $date,
            'day' => $day,
            'inMonth' => $inMonth,
            'isToday' => $date === $todayStr,
            'isHoliday' => $holidayInfo !== null,
            'holidayName' => $holidayInfo['name'] ?? null,
            'holidaySource' => $holidayInfo['source'] ?? null,
            'todos' => $cellTodos,
            'notes' => [],
        ];
    }
}
