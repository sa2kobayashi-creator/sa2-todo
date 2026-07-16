<?php

namespace App\Services;

use Carbon\Carbon;

class CalendarService
{
    public const WEEKDAY_LABELS = ['日', '月', '火', '水', '木', '金', '土'];

    public const VIEWS = ['day', 'week', 'month', 'year'];

    public const VIEW_LABELS = [
        'day' => '日',
        'week' => '週',
        'month' => '月',
        'year' => '年',
    ];

    /** Hour slots for day/week timed grid (0-23) */
    public const DAY_HOURS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23];

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

    /** @param array<string, mixed> $query */
    /** @return array{view: string, focusDate: string, year: int, month: int} */
    public function resolveCalendarState(array $query): array
    {
        $now = Carbon::now(config('app.timezone', 'Asia/Tokyo'));
        $view = (string) ($query['view'] ?? 'month');
        if (! in_array($view, self::VIEWS, true)) {
            $view = 'month';
        }

        $focusDate = $this->normalizeDate($query['date'] ?? null);
        ['year' => $year, 'month' => $month] = $this->resolveMonth($query['year'] ?? null, $query['month'] ?? null);

        if ($focusDate === null) {
            if ($view === 'year') {
                $focusDate = ((int) $now->format('Y') === $year)
                    ? $now->format('Y-m-d')
                    : sprintf('%04d-01-01', $year);
            } elseif ($view === 'month') {
                $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
                $focusDate = ((int) $now->format('Y') === $year && (int) $now->format('n') === $month)
                    ? $now->format('Y-m-d')
                    : sprintf('%04d-%02d-01', $year, $month);
                unset($daysInMonth);
            } else {
                $focusDate = $now->format('Y-m-d');
            }
        }

        $focus = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'));

        return [
            'view' => $view,
            'focusDate' => $focus->format('Y-m-d'),
            'year' => (int) $focus->format('Y'),
            'month' => (int) $focus->format('n'),
        ];
    }

    public function shiftMonth(int $year, int $month, int $delta): array
    {
        $date = Carbon::create($year, $month, 1)->addMonths($delta);

        return ['year' => (int) $date->format('Y'), 'month' => (int) $date->format('n')];
    }

    /** @return array{focusDate: string, year: int, month: int} */
    public function shiftFocus(string $view, string $focusDate, int $delta): array
    {
        $date = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'));
        $shifted = match ($view) {
            'day' => $date->copy()->addDays($delta),
            'week' => $date->copy()->addWeeks($delta),
            'year' => $date->copy()->addYears($delta),
            default => $date->copy()->addMonths($delta),
        };

        return [
            'focusDate' => $shifted->format('Y-m-d'),
            'year' => (int) $shifted->format('Y'),
            'month' => (int) $shifted->format('n'),
        ];
    }

    public function buildDashboardQuery(string $view, string $focusDate, array $extra = []): string
    {
        $focus = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'));
        $params = [];

        if ($view !== 'month') {
            $params['view'] = $view;
        }

        if (in_array($view, ['day', 'week'], true)) {
            $params['date'] = $focus->format('Y-m-d');
        } elseif ($view === 'year') {
            $params['year'] = (int) $focus->format('Y');
        } else {
            $params['year'] = (int) $focus->format('Y');
            $params['month'] = (int) $focus->format('n');
        }

        foreach ($extra as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }

        if ($params === []) {
            return '/dashboard';
        }

        return '/dashboard?'.http_build_query($params);
    }

    public function formatPeriodLabel(string $view, string $focusDate): string
    {
        $focus = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'));

        return match ($view) {
            'day' => $focus->format('Y年n月j日').'（'.self::WEEKDAY_LABELS[$focus->dayOfWeek].'）',
            'week' => $this->formatWeekLabel($focus),
            'year' => $focus->format('Y年'),
            default => $focus->format('Y年n月'),
        };
    }

    private function formatWeekLabel(Carbon $focus): string
    {
        $start = $focus->copy()->startOfWeek(Carbon::SUNDAY);
        $end = $focus->copy()->endOfWeek(Carbon::SATURDAY);
        if ($start->format('Y-m') === $end->format('Y-m')) {
            return $start->format('Y年n月j日').'〜'.$end->format('j日');
        }
        if ($start->format('Y') === $end->format('Y')) {
            return $start->format('Y年n月j日').'〜'.$end->format('n月j日');
        }

        return $start->format('Y年n月j日').'〜'.$end->format('Y年n月j日');
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

    /**
     * @param list<array<string, mixed>> $todos
     * @param array<string, array{name: string, source: string}> $holidayMap
     * @param list<array<string, mixed>> $notes
     * @return array{date: string, cell: array<string, mixed>, allDay: list<array<string, mixed>>, timed: list<array<string, mixed>>, hours: list<int>}
     */
    public function buildDayView(string $focusDate, array $todos, array $holidayMap = [], array $notes = []): array
    {
        $todayStr = Carbon::now(config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d');
        $focus = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'));
        $date = $focus->format('Y-m-d');
        $dayTodos = $this->todosForDate($date, $todos);
        $dayNotes = array_values(array_filter($notes, function (array $note) use ($date) {
            return ($note['registeredDate'] ?? null) === $date;
        }));
        $cell = $this->makeCell(
            $date,
            (int) $focus->format('j'),
            true,
            $todayStr,
            $dayTodos,
            $holidayMap[$date] ?? null
        );
        $cell['notes'] = $dayNotes;
        ['allDay' => $allDay, 'timed' => $timed] = $this->splitTodosByTime($dayTodos);

        return [
            'date' => $date,
            'cell' => $cell,
            'allDay' => $allDay,
            'timed' => $timed,
            'hours' => self::DAY_HOURS,
        ];
    }

    /**
     * @param list<array<string, mixed>> $todos
     * @param array<string, array{name: string, source: string}> $holidayMap
     * @param list<array<string, mixed>> $notes
     * @return array{startDate: string, endDate: string, days: list<array<string, mixed>>, hours: list<int>}
     */
    public function buildWeekView(string $focusDate, array $todos, array $holidayMap = [], array $notes = []): array
    {
        $todayStr = Carbon::now(config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d');
        $focus = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'));
        $start = $focus->copy()->startOfWeek(Carbon::SUNDAY);
        $notesByDate = [];
        foreach ($notes as $note) {
            $noteDate = $note['registeredDate'] ?? null;
            if ($noteDate) {
                $notesByDate[$noteDate][] = $note;
            }
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $start->copy()->addDays($i);
            $date = $day->format('Y-m-d');
            $dayTodos = $this->todosForDate($date, $todos);
            ['allDay' => $allDay, 'timed' => $timed] = $this->splitTodosByTime($dayTodos);
            $cell = $this->makeCell(
                $date,
                (int) $day->format('j'),
                true,
                $todayStr,
                $dayTodos,
                $holidayMap[$date] ?? null
            );
            $cell['notes'] = $notesByDate[$date] ?? [];
            $cell['weekdayLabel'] = self::WEEKDAY_LABELS[$day->dayOfWeek];
            $cell['allDay'] = $allDay;
            $cell['timed'] = $timed;
            $days[] = $cell;
        }

        return [
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $start->copy()->addDays(6)->format('Y-m-d'),
            'days' => $days,
            'hours' => self::DAY_HOURS,
        ];
    }

    /**
     * @param list<array<string, mixed>> $todos
     * @param array<string, array{name: string, source: string}> $holidayMap
     * @return array{year: int, months: list<array<string, mixed>>}
     */
    public function buildYearView(int $year, array $todos, array $holidayMap = []): array
    {
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $grid = $this->buildMonthGrid($year, $month, $todos, $holidayMap);
            $months[] = [
                'year' => $year,
                'month' => $month,
                'label' => $month.'月',
                'weeks' => $grid['weeks'],
            ];
        }

        return ['year' => $year, 'months' => $months];
    }

    /**
     * @param list<array<string, mixed>> $todos
     * @return array{allDay: list<array<string, mixed>>, timed: list<array<string, mixed>>}
     */
    public function splitTodosByTime(array $todos): array
    {
        $allDay = [];
        $timed = [];
        foreach ($todos as $todo) {
            if (empty($todo['startTime'])) {
                $allDay[] = $todo;
                continue;
            }
            $timed[] = $this->withTimeLayout($todo);
        }

        return ['allDay' => $allDay, 'timed' => $timed];
    }

    /** @param array<string, mixed> $todo @return array<string, mixed> */
    public function withTimeLayout(array $todo): array
    {
        $startMinutes = $this->timeToMinutes((string) ($todo['startTime'] ?? '00:00')) ?? 0;
        $endMinutes = $this->timeToMinutes((string) ($todo['endTime'] ?? ''));
        if ($endMinutes === null || $endMinutes <= $startMinutes) {
            $endMinutes = $startMinutes + 60;
        }
        $duration = max(30, $endMinutes - $startMinutes);
        $todo['layoutTop'] = round(($startMinutes / (24 * 60)) * 100, 4);
        $todo['layoutHeight'] = round(($duration / (24 * 60)) * 100, 4);
        $todo['layoutStartLabel'] = $todo['startTime'];
        $todo['layoutEndLabel'] = $todo['endTime'] ?? null;

        return $todo;
    }

    private function timeToMinutes(string $value): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
            return null;
        }
        $hours = (int) $m[1];
        $minutes = (int) $m[2];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        try {
            return Carbon::parse($raw, config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
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
