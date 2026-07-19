<?php

namespace App\Services;

use App\Models\Todo;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TodoService
{
    public const TODO_PAGE_SIZE = 20;

    public const IMPORTANCE_LABELS = [
        'high' => '高',
        'medium' => '中',
        'low' => '低',
    ];

    public const CATEGORY_LABELS = [
        'task' => 'タスク',
        'personal' => '個人',
        'memo' => 'メモ',
        'other' => 'その他',
    ];

    public const REMINDER_LABELS = [
        'at9am' => '当日9時',
        '30min' => '30分前',
        '1hour' => '1時間前',
        '1day' => '1日前',
    ];

    public const REMINDER_OPTIONS = ['at9am', '30min', '1hour', '1day'];

    public const NOTIFY_VIA_LABELS = [
        'push' => 'プッシュ通知',
        'push_sound' => 'プッシュ（音あり）',
        'line' => 'LINE',
    ];

    public const NOTIFY_VIA_OPTIONS = ['push', 'push_sound', 'line'];

    private const CATEGORY_FILTER_VALUES = ['task', 'personal', 'memo', 'other'];

    public function __construct(private HolidayService $holidays) {}

    /** @return Collection<int, array<string, mixed>> */
    public function listTodos(): Collection
    {
        return Todo::query()->orderBy('id')->get()->map(fn (Todo $t) => $t->toArray());
    }

    public function getTodo(int $id): ?array
    {
        $todo = Todo::find($id);

        return $todo?->toArray();
    }

    /** @param array<string, mixed> $query */
    public function parseFilters(array $query): array
    {
        $now = Carbon::now(config('app.timezone', 'Asia/Tokyo'));
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $periodMode = ($query['periodMode'] ?? '') === 'year' ? 'year' : 'month';

        if (! empty($query['period']) && is_string($query['period'])) {
            if (preg_match('/^\d{4}-\d{2}$/', $query['period'])) {
                [$y, $m] = array_map('intval', explode('-', $query['period']));
                $year = $y;
                $month = $m;
            } elseif (preg_match('/^\d{4}$/', $query['period'])) {
                $year = (int) $query['period'];
            }
        } elseif (! empty($query['periodYear']) && preg_match('/^\d{4}$/', (string) $query['periodYear'])) {
            $year = (int) $query['periodYear'];
        } else {
            if (! empty($query['year'])) {
                $year = (int) $query['year'] ?: $year;
            }
            if (! empty($query['month'])) {
                $month = (int) $query['month'] ?: $month;
            }
        }

        $status = in_array($query['status'] ?? 'all', ['all', 'pending', 'done'], true)
            ? ($query['status'] ?? 'all') : 'all';
        $importance = in_array($query['importance'] ?? 'all', ['all', 'high', 'medium', 'low'], true)
            ? ($query['importance'] ?? 'all') : 'all';
        $categories = $this->parseCategoriesFromQuery($query);
        $category = count($categories) === 1 ? $categories[0] : 'all';
        $scopeRaw = is_array($query['scope'] ?? null) ? ($query['scope'][0] ?? null) : ($query['scope'] ?? null);
        $todayFlag = ($query['today'] ?? '') === '1' || ($query['today'] ?? '') === 'true' || $scopeRaw === 'today';
        $scope = 'month';
        if ($todayFlag) {
            $scope = 'today';
        } elseif ($periodMode === 'year' || $scopeRaw === 'year') {
            $scope = 'year';
        }
        $todayDate = $scope === 'today' ? $this->getTodayDateString() : null;
        $page = max(1, (int) ($query['page'] ?? 1));

        return compact('year', 'month', 'status', 'importance', 'category', 'categories', 'scope', 'periodMode', 'todayDate', 'page');
    }

    /** @param array<string, mixed> $query @return list<string> */
    public function parseCategoriesFromQuery(array $query): array
    {
        $values = $query['category'] ?? null;
        if ($values === null || $values === '' || $values === 'all') {
            return [];
        }
        if (! is_array($values)) {
            $values = [$values];
        }

        return array_values(array_unique(array_filter(
            array_map('strval', $values),
            fn ($v) => in_array($v, self::CATEGORY_FILTER_VALUES, true)
        )));
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $extra */
    public function buildTodosQuery(array $filters, array $extra = []): string
    {
        $params = [];
        if ($filters['scope'] === 'today') {
            $params['today'] = '1';
        } elseif ($filters['scope'] === 'year' || ($filters['periodMode'] ?? '') === 'year') {
            $params['periodMode'] = 'year';
            $params['periodYear'] = (string) $filters['year'];
        } else {
            $params['periodMode'] = 'month';
            $params['period'] = sprintf('%04d-%02d', $filters['year'], $filters['month']);
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $params['status'] = $filters['status'];
        }
        if (($filters['importance'] ?? 'all') !== 'all') {
            $params['importance'] = $filters['importance'];
        }
        if (! empty($filters['category']) && $filters['category'] !== 'all') {
            $params['category'] = $filters['category'];
        } else {
            foreach ($filters['categories'] ?? [] as $cat) {
                $params['category'][] = $cat;
            }
        }
        if (($filters['page'] ?? 1) > 1) {
            $params['page'] = (string) $filters['page'];
        }
        foreach ($extra as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if ($key === 'page') {
                if ((int) $value > 1) {
                    $params['page'] = (string) $value;
                }
                continue;
            }
            $params[$key] = (string) $value;
        }

        $qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $qs ? '?'.$qs : '';
    }

    /** @param array<string, mixed> $filters */
    public function buildTodayFilterHref(array $filters): string
    {
        return '/todos'.$this->buildTodosQuery([...$filters, 'scope' => 'today']).'#todo-list-panel';
    }

    public function buildClearFiltersHref(): string
    {
        return '/todos#todo-list-panel';
    }

    /** @param iterable<array<string, mixed>> $all @param array<string, mixed> $filters */
    public function filterTodosPage(iterable $all, array $filters, int $perPage = self::TODO_PAGE_SIZE): array
    {
        $filtered = $this->filterTodos($all, $filters);

        return [
            'dated' => $this->paginateList($filtered['dated'], $filters['page'], $perPage),
            'undated' => $filtered['undated'],
            'datedTotal' => count($filtered['dated']),
        ];
    }

    /** @param iterable<array<string, mixed>> $all @param array<string, mixed> $filters */
    public function filterTodos(iterable $all, array $filters): array
    {
        $dated = [];
        $undated = [];

        foreach ($all as $todo) {
            if (! $this->getTodoRange($todo)) {
                if ($filters['scope'] !== 'today' && $this->matchesFilters($todo, $filters)) {
                    $undated[] = $todo;
                }
                continue;
            }
            if ($filters['scope'] === 'today') {
                $today = $filters['todayDate'] ?? $this->getTodayDateString();
                if (! $this->dateInRange($today, $todo)) {
                    continue;
                }
            } elseif ($filters['scope'] === 'year') {
                if (! $this->overlapsYear($todo, $filters['year'])) {
                    continue;
                }
            } elseif (! $this->overlapsMonth($todo, $filters['year'], $filters['month'])) {
                continue;
            }
            if (! $this->matchesFilters($todo, $filters)) {
                continue;
            }
            $dated[] = $todo;
        }

        usort($dated, function ($a, $b) {
            $ra = $this->getTodoRange($a);
            $rb = $this->getTodoRange($b);
            if (! $ra || ! $rb) {
                return 0;
            }
            $dateCmp = strcmp($ra['start'], $rb['start']);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return $this->compareTodosByDayTime($a, $b);
        });

        return ['dated' => $dated, 'undated' => $undated];
    }

    /** @param list<array<string, mixed>> $list */
    public function paginateList(array $list, int $page, int $perPage = self::TODO_PAGE_SIZE): array
    {
        $total = count($list);
        $totalPages = max(1, (int) ceil($total / $perPage) ?: 1);
        $safePage = min(max(1, $page), $totalPages);
        $start = ($safePage - 1) * $perPage;

        return [
            'items' => array_slice($list, $start, $perPage),
            'page' => $safePage,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }

    /** @param array<string, mixed> $todo @param array<string, mixed> $options */
    public function mapTodoListRow(array $todo, array $options = []): array
    {
        $item = $this->enrichTodoForListView($todo, $options);

        return [
            ...$item,
            'dateLabel' => $item['listDateLabel'],
            'timeLabel' => $item['listTimeLabel'],
            'categoryLabel' => __(self::CATEGORY_LABELS[$item['category']] ?? $item['category']),
            'importanceLabel' => __(self::IMPORTANCE_LABELS[$item['importance']] ?? $item['importance']),
        ];
    }

    /** @param array<string, mixed> $todo */
    public function formatPeriodLabel(array $todo): string
    {
        $range = $this->getTodoRange($todo);
        if (! $range) {
            return '未設定';
        }
        if ($range['start'] === $range['end']) {
            return $range['start'];
        }

        return "{$range['start']} 〜 {$range['end']}";
    }

    /** @param array<string, mixed> $todo */
    public function formatTimeRangeLabel(array $todo): ?string
    {
        if (empty($todo['startTime'])) {
            return null;
        }
        if (! empty($todo['endTime']) && $todo['endTime'] !== $todo['startTime']) {
            return "{$todo['startTime']}～{$todo['endTime']}";
        }

        return $todo['startTime'];
    }

    /** @param list<string> $titles @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function addTodos(array $titles, array $options = []): array
    {
        $period = $this->normalizePeriod($options['startDate'] ?? null, $options['endDate'] ?? null);
        $importance = $this->normalizeImportance($options['importance'] ?? null);
        $category = $this->normalizeCategory($options['category'] ?? null);
        $timeRange = $this->normalizeTimeRange($options['startTime'] ?? null, $options['endTime'] ?? null);
        $reminders = $this->normalizeReminders($options['reminders'] ?? []);
        $notifyVia = $this->normalizeNotifyVia($options['notifyVia'] ?? null);
        $weekdays = $this->parseWeekdaysFromBody($options['weekdays'] ?? []);
        $expansionOptions = $this->resolveWeekdayExpansionOptions($weekdays, [
            'excludeHolidays' => $options['excludeHolidays'] ?? null,
            'excludeClosures' => $options['excludeClosures'] ?? null,
        ]);
        $expandedDates = count($weekdays) > 0 && $period['startDate'] && $period['endDate']
            ? $this->expandDatesByWeekdays($period['startDate'], $period['endDate'], $weekdays, $expansionOptions)
            : null;
        $added = [];

        foreach ($titles as $title) {
            $scheduleDates = ($expandedDates && count($expandedDates) > 0) ? $expandedDates : [null];
            foreach ($scheduleDates as $date) {
                $todo = Todo::create([
                    'title' => $title,
                    'completed' => false,
                    'start_date' => $date ?? $period['startDate'],
                    'end_date' => $date ?? $period['endDate'],
                    'start_time' => $timeRange['startTime'],
                    'end_time' => $timeRange['endTime'],
                    'importance' => $importance,
                    'category' => $category,
                    'reminders' => $reminders,
                    'notify_via' => $notifyVia,
                    'notified_at' => [],
                ]);
                $added[] = $todo->toArray();
            }
        }

        return $added;
    }

    /** @param array<string, mixed> $patch */
    public function updateTodo(int $id, array $patch): ?array
    {
        $todo = Todo::find($id);
        if (! $todo) {
            return null;
        }

        $scheduleChanged = false;

        if (isset($patch['title']) && is_string($patch['title']) && trim($patch['title'])) {
            $todo->title = trim($patch['title']);
        }
        if (array_key_exists('startDate', $patch) || array_key_exists('endDate', $patch)) {
            $period = $this->normalizePeriod(
                $patch['startDate'] ?? $todo->start_date?->format('Y-m-d'),
                $patch['endDate'] ?? $todo->end_date?->format('Y-m-d')
            );
            $todo->start_date = $period['startDate'];
            $todo->end_date = $period['endDate'];
            $scheduleChanged = true;
        }
        if (array_key_exists('startTime', $patch) || array_key_exists('endTime', $patch)) {
            $range = $this->normalizeTimeRange(
                $patch['startTime'] ?? $todo->start_time,
                $patch['endTime'] ?? $todo->end_time
            );
            $todo->start_time = $range['startTime'];
            $todo->end_time = $range['endTime'];
            $scheduleChanged = true;
        }
        if (array_key_exists('reminders', $patch)) {
            $todo->reminders = $this->normalizeReminders($patch['reminders']);
            $scheduleChanged = true;
        }
        if (array_key_exists('notifyVia', $patch)) {
            $todo->notify_via = $this->normalizeNotifyVia($patch['notifyVia']);
            $scheduleChanged = true;
        }
        if (array_key_exists('importance', $patch)) {
            $todo->importance = $this->normalizeImportance($patch['importance']);
        }
        if (array_key_exists('category', $patch)) {
            $todo->category = $this->normalizeCategory($patch['category']);
        }
        if (isset($patch['completed']) && is_bool($patch['completed'])) {
            $todo->completed = $patch['completed'];
        }
        if ($scheduleChanged) {
            $todo->notified_at = [];
        }
        $todo->save();

        return $todo->toArray();
    }

    public function toggleTodo(int $id): ?array
    {
        $todo = Todo::find($id);
        if (! $todo) {
            return null;
        }
        $todo->completed = ! $todo->completed;
        $todo->save();

        return $todo->toArray();
    }

    public function duplicateTodo(int $id): ?array
    {
        $source = Todo::find($id);
        if (! $source) {
            return null;
        }
        $copy = $source->replicate(['completed', 'notified_at']);
        $copy->completed = false;
        $copy->notified_at = [];
        $copy->save();

        return $copy->toArray();
    }

    /** 開始日を移動し、期間の長さは維持する。 */
    public function rescheduleTodo(int $id, string $newStartDate): ?array
    {
        $todo = Todo::find($id);
        if (! $todo) {
            return null;
        }
        $normalized = $this->normalizeDate($newStartDate);
        if (! $normalized) {
            return null;
        }

        $oldStart = $todo->start_date?->format('Y-m-d');
        $oldEnd = $todo->end_date?->format('Y-m-d') ?: $oldStart;

        if (! $oldStart) {
            return $this->updateTodo($id, [
                'startDate' => $normalized,
                'endDate' => $normalized,
            ]);
        }

        $span = Carbon::parse($oldStart, config('app.timezone', 'Asia/Tokyo'))
            ->diffInDays(Carbon::parse($oldEnd, config('app.timezone', 'Asia/Tokyo')));
        $newEnd = Carbon::parse($normalized, config('app.timezone', 'Asia/Tokyo'))
            ->addDays($span)
            ->format('Y-m-d');

        return $this->updateTodo($id, [
            'startDate' => $normalized,
            'endDate' => $newEnd,
        ]);
    }

    /** @param list<int> $ids */
    public function bulkDuplicate(array $ids): int
    {
        $count = 0;
        foreach (array_unique(array_filter($ids, fn ($id) => is_int($id) && $id > 0)) as $id) {
            if ($this->duplicateTodo($id)) {
                $count++;
            }
        }

        return $count;
    }

    public function deleteTodo(int $id): bool
    {
        return (bool) Todo::destroy($id);
    }

    /** @param list<int> $ids */
    public function bulkDelete(array $ids): int
    {
        $idSet = array_filter($ids, fn ($id) => is_int($id) && $id > 0);

        return Todo::destroy($idSet);
    }

    /** @param list<int> $ids */
    public function bulkSetCompleted(array $ids, bool $completed): int
    {
        $idSet = array_values(array_filter($ids, fn ($id) => is_int($id) && $id > 0));
        if ($idSet === []) {
            return 0;
        }

        return Todo::query()
            ->whereIn('id', $idSet)
            ->update(['completed' => $completed ? 1 : 0]);
    }

    /** @param mixed $raw @return list<int> */
    public function parseIdList(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $list = is_array($raw) ? $raw : [$raw];

        return array_values(array_unique(array_filter(
            array_map(fn ($v) => (int) $v, $list),
            fn ($id) => $id > 0
        )));
    }

    /** @param mixed $raw @param array<string, mixed> $options @return list<string> */
    public function parseInput(mixed $raw, array $options = []): array
    {
        $splitByLine = ($options['splitByLine'] ?? true) !== false;

        return $splitByLine ? $this->parseTitles($raw) : $this->parseSingleTitle($raw);
    }

    /** @param mixed $raw @return list<string> */
    public function parseTitles(mixed $raw): array
    {
        $lines = preg_split('/\R/u', (string) $raw) ?: [];

        return array_values(array_filter(array_map(function ($line) {
            $line = trim($line);
            $line = preg_replace('/^[-*・•]\s*/u', '', $line) ?? $line;
            $line = preg_replace('/^\d+[.)．]\s*/u', '', $line) ?? $line;
            if ($line === '') {
                return null;
            }
            if (preg_match('/^\[\/?(info|title|hr)\]$/i', $line)) {
                return null;
            }

            return $line;
        }, $lines)));
    }

    /** @param mixed $raw */
    public function parseRemindersFromBody(mixed $raw): array
    {
        return $this->normalizeReminders($raw);
    }

    /** @param mixed $raw */
    public function parseNotifyViaFromBody(mixed $raw): ?string
    {
        return $this->normalizeNotifyVia(is_string($raw) ? $raw : null);
    }

    /** @param mixed $raw @return list<int> */
    public function parseWeekdaysFromBody(mixed $raw): array
    {
        if (! is_array($raw)) {
            if ($raw === null || $raw === '') {
                return [];
            }
            $raw = [$raw];
        }
        $weekdays = array_values(array_unique(array_filter(
            array_map('intval', $raw),
            fn ($n) => $n >= 0 && $n <= 6
        )));
        sort($weekdays);

        return $weekdays;
    }

    public function parseExcludeHolidays(mixed $value): bool
    {
        return $this->parseTruthyBodyFlag($value);
    }

    public function parseExcludeClosures(mixed $value): bool
    {
        return $this->parseTruthyBodyFlag($value);
    }

    public function getTodayDateString(): string
    {
        return Carbon::now(config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d');
    }

    /** @param array<string, mixed> $todo */
    public function formatEventTooltip(array $todo): string
    {
        $dateLabel = $this->formatPeriodLabel($todo);
        $timeLabel = $this->formatTimeRangeLabel($todo) ?: '—';

        return "{$todo['title']}\n{$dateLabel}\n{$timeLabel}";
    }

    public function getTodoRange(array $todo): ?array
    {
        if (empty($todo['startDate']) && empty($todo['endDate'])) {
            return null;
        }
        $start = $todo['startDate'] ?? $todo['endDate'];
        $end = $todo['endDate'] ?? $todo['startDate'];

        return ['start' => $start, 'end' => $end];
    }

    /** @param array<string, mixed> $todo @param array<string, mixed> $options */
    private function enrichTodoForListView(array $todo, array $options = []): array
    {
        $undated = ($options['undated'] ?? false) === true;

        return [
            ...$todo,
            'listDateLabel' => $undated ? '未設定' : $this->formatPeriodLabel($todo),
            'listTimeLabel' => $undated ? '—' : ($this->formatTimeRangeLabel($todo) ?: '—'),
        ];
    }

    /** @param array<string, mixed> $todo @param array<string, mixed> $filters */
    private function matchesFilters(array $todo, array $filters): bool
    {
        if (($filters['importance'] ?? 'all') !== 'all' && $todo['importance'] !== $filters['importance']) {
            return false;
        }
        if (! empty($filters['category']) && $filters['category'] !== 'all' && $todo['category'] !== $filters['category']) {
            return false;
        }
        if ((empty($filters['category']) || $filters['category'] === 'all')
            && ! empty($filters['categories'])
            && ! in_array($todo['category'], $filters['categories'], true)) {
            return false;
        }
        if (($filters['status'] ?? 'all') === 'pending' && ! empty($todo['completed'])) {
            return false;
        }
        if (($filters['status'] ?? 'all') === 'done' && empty($todo['completed'])) {
            return false;
        }

        return true;
    }

    public function dateInRange(string $dateStr, array $todo): bool
    {
        $range = $this->getTodoRange($todo);
        if (! $range) {
            return false;
        }

        return $dateStr >= $range['start'] && $dateStr <= $range['end'];
    }

    private function overlapsMonth(array $todo, int $year, int $month): bool
    {
        $range = $this->getTodoRange($todo);
        if (! $range) {
            return false;
        }
        $lastDay = Carbon::create($year, $month, 1)->daysInMonth;
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);

        return $range['start'] <= $last && $range['end'] >= $first;
    }

    private function overlapsYear(array $todo, int $year): bool
    {
        $range = $this->getTodoRange($todo);
        if (! $range) {
            return false;
        }

        return $range['start'] <= "{$year}-12-31" && $range['end'] >= "{$year}-01-01";
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private function compareTodosByDayTime(array $a, array $b): int
    {
        $ta = $a['startTime'] ?? '99:99';
        $tb = $b['startTime'] ?? '99:99';
        $cmp = strcmp($ta, $tb);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp($a['title'] ?? '', $b['title'] ?? '');
    }

    /** @return array{startDate: ?string, endDate: ?string} */
    public function normalizePeriod(?string $startDate, ?string $endDate): array
    {
        $start = $this->normalizeDate($startDate);
        $end = $this->normalizeDate($endDate);
        if ($start && ! $end) {
            $end = $start;
        }
        if (! $start && $end) {
            $start = $end;
        }
        if ($start && $end && $start > $end) {
            [$start, $end] = [$end, $start];
        }

        return ['startDate' => $start, 'endDate' => $end];
    }

    public function normalizeDate(?string $value): ?string
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    public function normalizeImportance(?string $value): string
    {
        return in_array($value, ['high', 'medium', 'low'], true) ? $value : 'medium';
    }

    public function normalizeCategory(?string $value): string
    {
        return in_array($value, ['task', 'personal', 'memo', 'other'], true) ? $value : 'task';
    }

    /** @return array{startTime: ?string, endTime: ?string} */
    public function normalizeTimeRange(?string $startTime, ?string $endTime): array
    {
        $start = $this->normalizeTime($startTime);
        $end = $this->normalizeTime($endTime);
        if (! $start && $end) {
            $start = $end;
        }
        if ($start && ! $end) {
            $end = null;
        }
        if ($start && $end && $start > $end) {
            [$start, $end] = [$end, $start];
        }

        return ['startTime' => $start, 'endTime' => $end];
    }

    public function normalizeTime(?string $value): ?string
    {
        if (! $value || ! preg_match('/^\d{2}:\d{2}$/', trim($value))) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', trim($value)));
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $m);
    }

    /** @param mixed $value @return list<string> */
    public function normalizeReminders(mixed $value): array
    {
        $allowed = array_flip(self::REMINDER_OPTIONS);
        $list = is_array($value) ? $value : ($value !== null ? [$value] : []);

        return array_values(array_unique(array_filter($list, fn ($item) => isset($allowed[$item]))));
    }

    public function normalizeNotifyVia(?string $value): ?string
    {
        return in_array($value, self::NOTIFY_VIA_OPTIONS, true) ? $value : null;
    }

    /** @param list<int> $weekdays @param array<string, mixed> $options @return list<string> */
    public function expandDatesByWeekdays(string $startDate, string $endDate, array $weekdays, array $options = []): array
    {
        $start = $this->normalizeDate($startDate);
        $end = $this->normalizeDate($endDate);
        if (! $start || ! $end || count($weekdays) === 0) {
            return [];
        }

        $skipOptions = $this->resolveWeekdayExpansionOptions($weekdays, $options);
        $weekdaySet = array_flip($weekdays);
        $dates = [];
        $cur = Carbon::parse($start);
        $last = Carbon::parse($start <= $end ? $end : $start);

        while ($cur->lte($last)) {
            $dateStr = $cur->format('Y-m-d');
            if (isset($weekdaySet[$cur->dayOfWeek]) && ! $this->shouldSkipExpandedDate($dateStr, $skipOptions)) {
                $dates[] = $dateStr;
            }
            if ($dateStr === $last->format('Y-m-d')) {
                break;
            }
            $cur->addDay();
        }

        return $dates;
    }

    /** @param list<int> $weekdays @param array<string, mixed> $options */
    private function resolveWeekdayExpansionOptions(array $weekdays, array $options = []): array
    {
        if (count($weekdays) === 0) {
            return ['excludeHolidays' => false, 'excludeClosures' => false];
        }

        return [
            'excludeHolidays' => ! $this->isExplicitlyDisabled($options['excludeHolidays'] ?? null),
            'excludeClosures' => ! $this->isExplicitlyDisabled($options['excludeClosures'] ?? null),
        ];
    }

    /** @param array<string, bool> $options */
    private function shouldSkipExpandedDate(string $date, array $options = []): bool
    {
        if (($options['excludeHolidays'] ?? false) && $this->holidays->isJapaneseNationalHolidayDate($date)) {
            return true;
        }
        if (($options['excludeClosures'] ?? false) && $this->holidays->isBusinessClosureDate($date)) {
            return true;
        }

        return false;
    }

    private function parseTruthyBodyFlag(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_array($value)) {
            return array_reduce($value, fn ($carry, $item) => $carry || $this->parseTruthyBodyFlag($item), false);
        }

        return $value === true || $value === 'true' || $value === '1' || $value === 'on';
    }

    private function isExplicitlyDisabled(mixed $value): bool
    {
        if ($value === '0' || $value === 'false' || $value === false) {
            return true;
        }
        if (is_array($value)) {
            return array_reduce($value, fn ($carry, $item) => $carry && $this->isExplicitlyDisabled($item), true);
        }

        return false;
    }

    /** @param mixed $raw @return list<string> */
    private function parseSingleTitle(mixed $raw): array
    {
        $lines = preg_split('/\R/u', (string) $raw) ?: [];
        $title = trim(implode("\n", array_filter(array_map('trim', $lines))));

        return $title ? [$title] : [];
    }

    /**
     * 現在時刻を30分単位で切り上げた開始時刻と、その1時間後の終了時刻を返す。
     *
     * @return array{start: string, end: string}
     */
    public function defaultTimeRange(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $hours = (int) $now->format('G');
        $minutes = (int) (ceil(((int) $now->format('i')) / 30) * 30);
        if ($minutes >= 60) {
            $minutes = 0;
            $hours += 1;
        }

        return [
            'start' => sprintf('%02d:%02d', $hours % 24, $minutes),
            'end' => sprintf('%02d:%02d', ($hours + 1) % 24, $minutes),
        ];
    }
}
