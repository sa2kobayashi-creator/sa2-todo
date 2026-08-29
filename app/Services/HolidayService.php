<?php

namespace App\Services;

use App\Models\HolidayEntry;
use App\Models\User;
use App\Models\WeekdayRule;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class HolidayService
{
    /** @var list<int>|null */
    private ?array $memoYears = null;

    /** @var array<int|string, int|null> */
    private array $memoViewerTenantIds = [];

    /** @return array<string, array{name: string, source: string}> */
    public function getHolidayInfoMapForYear(int $year, ?int $viewerUserId = null): array
    {
        $cacheKey = 'holiday_map_'.$this->mapVersion().'_'.$year.'_'.($viewerUserId ?: 'i').'_'.($this->viewerTenantId($viewerUserId) ?: 'p');

        return Cache::remember($cacheKey, 300, function () use ($year, $viewerUserId) {
            $map = [];

            foreach ($this->weekdayRulesForMap($viewerUserId) as $rule) {
                foreach ($this->expandWeekdayRuleForYear($rule, $year) as $item) {
                    if (! isset($map[$item['date']])) {
                        $map[$item['date']] = ['name' => $item['name'], 'source' => 'weekday'];
                    }
                }
            }

            $this->holidayEntriesQuery($viewerUserId, true)
                ->whereYear('date', $year)
                ->orderBy('date')
                ->orderBy('id')
                ->get()
                ->each(function (HolidayEntry $entry) use (&$map) {
                    $date = $entry->date->format('Y-m-d');
                    $existing = $map[$date] ?? null;
                    $existingSource = $existing['source'] ?? '';
                    if (
                        $existing
                        && in_array($existingSource, ['national', 'national_ph', 'custom'], true)
                        && $existingSource !== $entry->source
                        && ! str_contains((string) $existing['name'], $entry->name)
                    ) {
                        $map[$date]['name'] = $existing['name'].' / '.$entry->name;

                        return;
                    }
                    $map[$date] = ['name' => $entry->name, 'source' => $entry->source];
                });

            return $map;
        });
    }

    public function clearCache(?int $year = null): void
    {
        $this->bumpMapVersion();
    }

    public function isJapaneseNationalHolidayDate(?string $date, ?int $viewerUserId = null): bool
    {
        if (! $date) {
            return false;
        }
        $year = (int) substr($date, 0, 4);
        $info = $this->getHolidayInfoMapForYear($year, $viewerUserId)[$date] ?? null;

        return ($info['source'] ?? null) === 'national';
    }

    public function isBusinessClosureDate(?string $date, ?int $viewerUserId = null): bool
    {
        if (! $date) {
            return false;
        }
        $year = (int) substr($date, 0, 4);
        $info = $this->getHolidayInfoMapForYear($year, $viewerUserId)[$date] ?? null;
        $source = $info['source'] ?? null;

        return $source === 'custom' || $source === 'weekday';
    }

    /** @return list<string> */
    public function listAllJapaneseNationalHolidayDateKeys(?int $viewerUserId = null): array
    {
        $query = HolidayEntry::query()->where('source', 'national');
        if ($viewerUserId) {
            $query->where(function ($q) use ($viewerUserId) {
                $q->whereNull('user_id')->orWhere('user_id', $viewerUserId);
            });
        } else {
            $query->whereNull('user_id');
        }

        return $query
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function listAllBusinessClosureDateKeys(?int $viewerUserId = null): array
    {
        $dates = [];
        foreach ($this->collectYears() as $year) {
            foreach ($this->getHolidayInfoMapForYear($year, $viewerUserId) as $date => $info) {
                if (($info['source'] ?? '') === 'custom' || ($info['source'] ?? '') === 'weekday') {
                    $dates[] = $date;
                }
            }
        }

        sort($dates);

        return array_values(array_unique($dates));
    }

    /** @return list<string> */
    public function listAllClosureDateKeys(?int $viewerUserId = null): array
    {
        $dates = [];
        foreach ($this->collectYears() as $year) {
            foreach (array_keys($this->getHolidayInfoMapForYear($year, $viewerUserId)) as $date) {
                $dates[] = $date;
            }
        }
        sort($dates);

        return array_values(array_unique($dates));
    }

    /** @return list<int> */
    private function collectYears(): array
    {
        if ($this->memoYears !== null) {
            return $this->memoYears;
        }

        $this->memoYears = Cache::remember('holiday_collect_years_'.$this->mapVersion(), 3600, function () {
            $years = [(int) date('Y'), (int) date('Y') + 1];

            foreach ([
                [HolidayEntry::query()->min('date'), HolidayEntry::query()->max('date')],
                [WeekdayRule::query()->min('start_date'), WeekdayRule::query()->max('end_date')],
            ] as [$min, $max]) {
                if ($min && $max) {
                    $from = (int) Carbon::parse($min)->format('Y');
                    $to = (int) Carbon::parse($max)->format('Y');
                    for ($y = $from; $y <= $to; $y++) {
                        $years[] = $y;
                    }
                }
            }

            return array_values(array_unique($years));
        });

        return $this->memoYears;
    }

    /** @return list<array{date: string, name: string}> */
    private function expandWeekdayRuleForYear(WeekdayRule $rule, int $year): array
    {
        $yearStart = "{$year}-01-01";
        $yearEnd = "{$year}-12-31";
        $from = max($rule->start_date->format('Y-m-d'), $yearStart);
        $to = min($rule->end_date->format('Y-m-d'), $yearEnd);
        if ($from > $to) {
            return [];
        }

        $weekdaySet = array_flip($rule->weekdays ?? []);
        $exceptions = array_flip($rule->exceptions ?? []);
        $items = [];
        $cur = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($cur->lte($end)) {
            $dateStr = $cur->format('Y-m-d');
            if (isset($weekdaySet[$cur->dayOfWeek]) && ! isset($exceptions[$dateStr])) {
                $items[] = ['date' => $dateStr, 'name' => $rule->name];
            }
            $cur->addDay();
        }

        return $items;
    }

    /** @return list<array{date: string, name: string}> */
    public function computeJapaneseNationalHolidays(int $year): array
    {
        $items = [];
        $add = function (int $month, int $day, string $name) use ($year, &$items) {
            $items[] = ['date' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'name' => $name];
        };

        $add(1, 1, '元日');
        $adultDay = $this->nthWeekdayOfMonth($year, 1, 1, 2);
        if ($adultDay) {
            $add(1, $adultDay, '成人の日');
        }
        $add(2, 11, '建国記念の日');
        $add(2, 23, '天皇誕生日');
        $add(3, $this->equinoxDay($year, true), '春分の日');
        $add(4, 29, '昭和の日');
        $add(5, 3, '憲法記念日');
        $add(5, 4, 'みどりの日');
        $add(5, 5, 'こどもの日');
        $marineDay = $this->nthWeekdayOfMonth($year, 7, 1, 3);
        if ($marineDay) {
            $add(7, $marineDay, '海の日');
        }
        $add(8, 11, '山の日');
        $respectDay = $this->nthWeekdayOfMonth($year, 9, 1, 3);
        if ($respectDay) {
            $add(9, $respectDay, '敬老の日');
        }
        $add(9, $this->equinoxDay($year, false), '秋分の日');
        $sportsDay = $this->nthWeekdayOfMonth($year, 10, 1, 2);
        if ($sportsDay) {
            $add(10, $sportsDay, 'スポーツの日');
        }
        $add(11, 3, '文化の日');
        $add(11, 23, '勤労感謝の日');

        return $items;
    }

    /**
     * フィリピンの定期祝日＋よく使う特別休業日。
     * Eid は大統領布告で前後することがある。
     *
     * @return list<array{date: string, name: string}>
     */
    public function computePhilippineHolidays(int $year): array
    {
        $items = [];
        $add = function (int $month, int $day, string $name) use ($year, &$items) {
            $items[] = ['date' => sprintf('%04d-%02d-%02d', $year, $month, $day), 'name' => $name];
        };

        $add(1, 1, '新年');
        $add(2, 25, 'EDSA革命記念日');
        $add(4, 9, '勇気の日');
        $add(5, 1, '労働の日');
        $add(6, 12, '独立記念日');
        $add(8, 21, 'ニノイ・アキノの日');
        $heroesDay = $this->lastWeekdayOfMonth($year, 8, Carbon::MONDAY);
        $add(8, $heroesDay, '国民英雄の日');
        $add(11, 1, '諸聖人の日');
        $add(11, 30, 'ボニファシオの日');
        $add(12, 8, '無原罪の聖母');
        $add(12, 25, 'クリスマス');
        $add(12, 30, 'リサールの日');
        $add(12, 31, '大晦日');

        $easter = $this->westernEasterDate($year);
        $add($easter->copy()->subDays(3)->month, $easter->copy()->subDays(3)->day, '聖木曜日');
        $add($easter->copy()->subDays(2)->month, $easter->copy()->subDays(2)->day, '聖金曜日');
        $add($easter->copy()->subDay()->month, $easter->copy()->subDay()->day, '聖土曜日');

        $cny = $this->chineseNewYearDate($year);
        if ($cny) {
            $add($cny->month, $cny->day, '旧正月');
        }

        foreach ($this->philippineIslamicHolidays($year) as $item) {
            $items[] = $item;
        }

        usort($items, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $items;
    }

    public function importNationalHolidays(int $year, string $country = 'jp', ?int $ownerUserId = null): int
    {
        $source = $country === 'ph' ? 'national_ph' : 'national';
        $national = $country === 'ph'
            ? $this->computePhilippineHolidays($year)
            : $this->computeJapaneseNationalHolidays($year);
        $added = 0;

        foreach ($national as $item) {
            $exists = $this->holidayEntriesQuery($ownerUserId, false)
                ->where('date', $item['date'])
                ->where('source', $source)
                ->exists();
            if ($exists) {
                continue;
            }
            HolidayEntry::create([
                'user_id' => $ownerUserId,
                'tenant_id' => $this->instanceTenantId($ownerUserId),
                'date' => $item['date'],
                'name' => $item['name'],
                'source' => $source,
            ]);
            $added++;
        }

        $this->clearCache($year);

        return $added;
    }

    private function lastWeekdayOfMonth(int $year, int $month, int $weekday): int
    {
        $day = Carbon::create($year, $month, 1)->endOfMonth();
        while ($day->dayOfWeek !== $weekday) {
            $day->subDay();
        }

        return (int) $day->day;
    }

    private function westernEasterDate(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day);
    }

    private function chineseNewYearDate(int $year): ?Carbon
    {
        $dates = [
            2020 => '01-25', 2021 => '02-12', 2022 => '02-01', 2023 => '01-22',
            2024 => '02-10', 2025 => '01-29', 2026 => '02-17', 2027 => '02-06',
            2028 => '01-26', 2029 => '02-13', 2030 => '02-03', 2031 => '01-23',
            2032 => '02-11', 2033 => '01-31', 2034 => '02-19', 2035 => '02-08',
        ];
        if (! isset($dates[$year])) {
            return null;
        }

        return Carbon::parse(sprintf('%04d-%s', $year, $dates[$year]));
    }

    /** @return list<array{date: string, name: string}> */
    private function philippineIslamicHolidays(int $year): array
    {
        $fitr = [
            2024 => '04-10', 2025 => '03-31', 2026 => '03-20',
            2027 => '03-10', 2028 => '02-27', 2029 => '02-15', 2030 => '02-05',
        ];
        $adha = [
            2024 => '06-17', 2025 => '06-06', 2026 => '05-27',
            2027 => '05-17', 2028 => '05-06', 2029 => '04-25', 2030 => '04-14',
        ];
        $items = [];
        if (isset($fitr[$year])) {
            $items[] = ['date' => sprintf('%04d-%s', $year, $fitr[$year]), 'name' => 'イド・フィトル'];
        }
        if (isset($adha[$year])) {
            $items[] = ['date' => sprintf('%04d-%s', $year, $adha[$year]), 'name' => 'イド・アドハ'];
        }

        return $items;
    }

    private function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $nth): ?int
    {
        $count = 0;
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
        for ($day = 1; $day <= $daysInMonth; $day++) {
            if (Carbon::create($year, $month, $day)->dayOfWeek === $weekday) {
                $count++;
                if ($count === $nth) {
                    return $day;
                }
            }
        }

        return null;
    }

    private function equinoxDay(int $year, bool $isSpring): int
    {
        $base = $isSpring ? 20.8431 : 23.2488;

        return (int) floor($base + 0.242194 * ($year - 1980) - floor(($year - 1980) / 4));
    }

    /** @return list<array{id: int, date: string, name: string, source: string}> */
    public function listByYear(int $year, ?int $ownerUserId = null): array
    {
        return $this->holidayEntriesQuery($ownerUserId, false)
            ->whereYear('date', $year)
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (HolidayEntry $e) => [
                'id' => $e->id,
                'date' => $e->date->format('Y-m-d'),
                'name' => $e->name,
                'source' => $e->source,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function listWeekdayRules(?int $ownerUserId = null): array
    {
        return $this->weekdayRulesQuery($ownerUserId, false)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->map(fn (WeekdayRule $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'startDate' => $r->start_date->format('Y-m-d'),
                'endDate' => $r->end_date->format('Y-m-d'),
                'weekdays' => $r->weekdays ?? [],
                'exceptions' => $r->exceptions ?? [],
            ])
            ->all();
    }

    public function addCustomHoliday(string $date, string $name, ?int $ownerUserId = null): ?HolidayEntry
    {
        $normalizedDate = $this->normalizeDate($date);
        $label = trim($name);
        if (! $normalizedDate || $label === '') {
            return null;
        }

        $entry = $this->findOwnerHoliday($normalizedDate, $ownerUserId);
        if ($entry) {
            $entry->update(['name' => $label, 'source' => 'custom']);
        } else {
            $entry = HolidayEntry::create([
                'user_id' => $ownerUserId,
                'tenant_id' => $this->instanceTenantId($ownerUserId),
                'date' => $normalizedDate,
                'name' => $label,
                'source' => 'custom',
            ]);
        }
        $this->clearCache();

        return $entry;
    }

    public function addCustomHolidayRange(string $startDate, string $endDate, string $name, ?int $ownerUserId = null): ?int
    {
        $start = $this->normalizeDate($startDate);
        $end = $this->normalizeDate($endDate);
        $label = trim($name);
        if (! $start || ! $end || $label === '' || $start > $end) {
            return null;
        }

        $added = 0;
        $cur = Carbon::parse($start);
        $last = Carbon::parse($end);
        while ($cur->lte($last)) {
            $dateStr = $cur->format('Y-m-d');
            $exists = $this->findOwnerHoliday($dateStr, $ownerUserId);
            if (! $exists) {
                HolidayEntry::create([
                    'user_id' => $ownerUserId,
                    'tenant_id' => $this->instanceTenantId($ownerUserId),
                    'date' => $dateStr,
                    'name' => $label,
                    'source' => 'custom',
                ]);
                $added++;
            } elseif ($exists->source === 'custom') {
                $exists->update(['name' => $label]);
                $added++;
            }
            $cur->addDay();
        }
        $this->clearCache();

        return $added;
    }

    public function removeHoliday(int $id, ?int $ownerUserId = null): bool
    {
        $entry = $this->holidayEntriesQuery($ownerUserId, false)->whereKey($id)->first();
        $deleted = $entry ? (bool) $entry->delete() : false;
        if ($deleted) {
            $this->clearCache();
        }

        return $deleted;
    }

    /** @param array<string, mixed> $input */
    public function addWeekdayRule(array $input, ?int $ownerUserId = null): ?WeekdayRule
    {
        $startDate = $this->normalizeDate($input['startDate'] ?? null);
        $endDate = $this->normalizeDate($input['endDate'] ?? null);
        $weekdays = $this->parseWeekdays($input['weekdays'] ?? []);
        $name = trim((string) ($input['name'] ?? '')) ?: '曜日休日';
        if (! $startDate || ! $endDate || $startDate > $endDate || count($weekdays) === 0) {
            return null;
        }

        $exceptions = array_values(array_filter(
            $this->normalizeExceptions($input['exceptions'] ?? []),
            fn ($d) => $d >= $startDate && $d <= $endDate
        ));

        $rule = WeekdayRule::create([
            'user_id' => $ownerUserId,
            'tenant_id' => $this->instanceTenantId($ownerUserId),
            'name' => $name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'weekdays' => $weekdays,
            'exceptions' => $exceptions,
        ]);
        $this->clearCache();

        return $rule;
    }

    public function removeWeekdayRule(int $id, ?int $ownerUserId = null): bool
    {
        $rule = $this->weekdayRulesQuery($ownerUserId, false)->whereKey($id)->first();
        $deleted = $rule ? (bool) $rule->delete() : false;
        if ($deleted) {
            $this->clearCache();
        }

        return $deleted;
    }

    public function addWeekdayException(int $ruleId, string $date, ?int $ownerUserId = null): bool
    {
        $rule = $this->weekdayRulesQuery($ownerUserId, false)->whereKey($ruleId)->first();
        $normalizedDate = $this->normalizeDate($date);
        if (! $rule || ! $normalizedDate) {
            return false;
        }
        if ($normalizedDate < $rule->start_date->format('Y-m-d') || $normalizedDate > $rule->end_date->format('Y-m-d')) {
            return false;
        }
        $exceptions = $rule->exceptions ?? [];
        if (in_array($normalizedDate, $exceptions, true)) {
            return true;
        }
        $exceptions[] = $normalizedDate;
        sort($exceptions);
        $rule->exceptions = $exceptions;
        $rule->save();
        $this->clearCache();

        return true;
    }

    public function removeWeekdayException(int $ruleId, string $date, ?int $ownerUserId = null): bool
    {
        $rule = $this->weekdayRulesQuery($ownerUserId, false)->whereKey($ruleId)->first();
        $normalizedDate = $this->normalizeDate($date);
        if (! $rule || ! $normalizedDate) {
            return false;
        }
        $exceptions = array_values(array_filter($rule->exceptions ?? [], fn ($d) => $d !== $normalizedDate));
        if (count($exceptions) === count($rule->exceptions ?? [])) {
            return false;
        }
        $rule->exceptions = $exceptions;
        $rule->save();
        $this->clearCache();

        return true;
    }

    /** @param mixed $value @return list<int> */
    public function parseWeekdays(mixed $value): array
    {
        if (! is_array($value)) {
            $value = ($value === null || $value === '') ? [] : [$value];
        }
        $weekdays = array_values(array_unique(array_filter(array_map('intval', $value), fn ($n) => $n >= 0 && $n <= 6)));
        sort($weekdays);

        return $weekdays;
    }

    /** @param mixed $value @return list<string> */
    public function normalizeExceptions(mixed $value): array
    {
        $dates = is_array($value) ? $value : (preg_split('/[\n,]+/', (string) $value) ?: []);
        $out = [];
        foreach ($dates as $d) {
            $n = $this->normalizeDate(is_string($d) ? trim($d) : null);
            if ($n) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public function listJapaneseNationalHolidayDateKeysForRange(string $startDate, string $endDate, ?int $viewerUserId = null): array
    {
        return $this->listDateKeysForRange($startDate, $endDate, fn ($info) => ($info['source'] ?? '') === 'national', $viewerUserId);
    }

    /** @return list<string> */
    public function listBusinessClosureDateKeysForRange(string $startDate, string $endDate, ?int $viewerUserId = null): array
    {
        return $this->listDateKeysForRange($startDate, $endDate, fn ($info) => in_array($info['source'] ?? '', ['custom', 'weekday'], true), $viewerUserId);
    }

    private function normalizeDate(?string $value): ?string
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    /** @param callable(array{name: string, source: string}): bool $filter */
    private function listDateKeysForRange(string $startDate, string $endDate, callable $filter, ?int $viewerUserId = null): array
    {
        $start = $this->normalizeDate($startDate);
        $end = $this->normalizeDate($endDate);
        if (! $start || ! $end) {
            return $this->listAllJapaneseNationalHolidayDateKeys();
        }
        $y1 = (int) substr($start, 0, 4);
        $y2 = (int) substr($end, 0, 4);
        $dates = [];
        for ($year = min($y1, $y2); $year <= max($y1, $y2); $year++) {
            foreach ($this->getHolidayInfoMapForYear($year, $viewerUserId) as $date => $info) {
                if ($filter($info)) {
                    $dates[] = $date;
                }
            }
        }
        sort($dates);

        return array_values(array_unique($dates));
    }

    private function mapVersion(): int
    {
        return max(1, (int) Cache::get('holiday_map_ver', 1));
    }

    private function bumpMapVersion(): void
    {
        if (! Cache::has('holiday_map_ver')) {
            Cache::forever('holiday_map_ver', 2);

            return;
        }
        Cache::increment('holiday_map_ver');
    }

    /**
     * @param  ?int  $ownerUserId  null = インスタンス全体（管理者設定）
     * @param  bool  $includeInstance  true なら個人カレンダー用に全体＋本人を合成
     */
    private function holidayEntriesQuery(?int $ownerUserId, bool $includeInstance)
    {
        $query = HolidayEntry::query();
        if ($includeInstance && $ownerUserId) {
            $tenantId = $this->viewerTenantId($ownerUserId);

            return $query->where(function ($q) use ($ownerUserId, $tenantId) {
                $q->where('user_id', $ownerUserId)
                    ->orWhere(function ($instance) use ($tenantId) {
                        $instance->whereNull('user_id');
                        $this->constrainInstanceTenant($instance, $tenantId);
                    });
            });
        }
        if ($ownerUserId) {
            return $query->where('user_id', $ownerUserId);
        }

        $query->whereNull('user_id');
        $this->constrainInstanceTenant($query, TenantContext::idOrNull());

        return $query;
    }

    private function weekdayRulesQuery(?int $ownerUserId, bool $includeInstance)
    {
        $query = WeekdayRule::query();
        if ($includeInstance && $ownerUserId) {
            $tenantId = $this->viewerTenantId($ownerUserId);

            return $query->where(function ($q) use ($ownerUserId, $tenantId) {
                $q->where('user_id', $ownerUserId)
                    ->orWhere(function ($instance) use ($tenantId) {
                        $instance->whereNull('user_id');
                        $this->constrainInstanceTenant($instance, $tenantId);
                    });
            });
        }
        if ($ownerUserId) {
            return $query->where('user_id', $ownerUserId);
        }

        $query->whereNull('user_id');
        $this->constrainInstanceTenant($query, TenantContext::idOrNull());

        return $query;
    }

    private function constrainInstanceTenant($query, ?int $tenantId): void
    {
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);

            return;
        }
        $query->whereNull('tenant_id');
    }

    private function instanceTenantId(?int $ownerUserId): ?int
    {
        if ($ownerUserId) {
            return null;
        }

        return TenantContext::idOrNull();
    }

    private function viewerTenantId(?int $viewerUserId): ?int
    {
        $key = $viewerUserId ?? 'ctx';
        if (array_key_exists($key, $this->memoViewerTenantIds)) {
            return $this->memoViewerTenantIds[$key];
        }

        if ($viewerUserId) {
            $value = User::query()->whereKey($viewerUserId)->value('tenant_id');

            return $this->memoViewerTenantIds[$key] = $value ? (int) $value : null;
        }

        return $this->memoViewerTenantIds[$key] = TenantContext::idOrNull();
    }

    /** @return Collection<int, WeekdayRule> */
    private function weekdayRulesForMap(?int $viewerUserId)
    {
        return $this->weekdayRulesQuery($viewerUserId, true)->get();
    }

    private function findOwnerHoliday(string $date, ?int $ownerUserId): ?HolidayEntry
    {
        return $this->holidayEntriesQuery($ownerUserId, false)->where('date', $date)->first();
    }
}
