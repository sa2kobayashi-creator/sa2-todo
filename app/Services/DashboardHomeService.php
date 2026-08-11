<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\User;
use Carbon\Carbon;

class DashboardHomeService
{
    public function __construct(
        private TodoService $todos,
        private NoteService $notes,
        private PhotoService $photos,
        private GoogleCalendarService $googleCalendar,
    ) {}

    /**
     * ダッシュボード「今日ハブ」用データ。
     *
     * @param  list<array<string, mixed>>  $localTodos
     * @return array<string, mixed>
     */
    public function build(User $user, array $localTodos = []): array
    {
        $tz = config('app.timezone', 'Asia/Tokyo');
        $now = Carbon::now($tz);
        $today = $now->toDateString();
        $userId = (int) $user->id;

        if ($localTodos === []) {
            $localTodos = $this->todos->listTodos($userId)->all();
        }

        $greeting = $this->greetingForHour((int) $now->format('G'));
        $displayName = trim((string) ($user->display_name ?: ''));

        $nextActions = $this->nextActions($localTodos, $now, 5);
        $todayTodoCount = $this->todayPendingCount($localTodos, $today);

        $gcal = $this->todayGoogleEvents($user, $now);
        $photos = $this->photosMemories($userId, $now);
        $pinnedNotes = $this->pinnedNotes($userId, 3);

        $locale = app()->getLocale();
        $dateLabel = $locale === 'en'
            ? $now->locale('en')->isoFormat('MMM D (ddd)')
            : $now->locale('ja')->isoFormat('M月D日（ddd）');

        return [
            'dateLabel' => $dateLabel,
            'dateIso' => $today,
            'greeting' => $greeting,
            'displayName' => $displayName,
            'greetingLine' => $displayName !== ''
                ? __(':greeting、:nameさん', ['greeting' => $greeting, 'name' => $displayName])
                : $greeting,
            'counts' => [
                'events' => count($gcal['events']),
                'todos' => $todayTodoCount,
            ],
            'nextActions' => $nextActions,
            'calendar' => $gcal,
            'photos' => $photos,
            'pinnedNotes' => $pinnedNotes,
            'links' => [
                'todosToday' => '/todos?today=1#todo-list-panel',
                'todosNew' => '/todos',
                'notes' => '/notes',
                'notesNew' => '/notes',
                'photos' => '/photos',
                'map' => '/map',
                'transit' => '/transit',
                'aiSettings' => '/settings?section=ai',
                'googleCalendarConnect' => '/settings?section=integration#google-calendar',
                'googleCalendar' => 'https://calendar.google.com/calendar/r/day',
            ],
        ];
    }

    private function greetingForHour(int $hour): string
    {
        if ($hour < 5) {
            return __('こんばんは');
        }
        if ($hour < 11) {
            return __('おはようございます');
        }
        if ($hour < 18) {
            return __('こんにちは');
        }

        return __('こんばんは');
    }

    /**
     * これからやる ToDo のみ（期限超過・時刻経過済みは出さない）。
     *
     * @param  list<array<string, mixed>>  $todos
     * @return list<array<string, mixed>>
     */
    private function nextActions(array $todos, Carbon $now, int $limit): array
    {
        $today = $now->toDateString();
        $pending = array_values(array_filter(
            $todos,
            static fn (array $t) => empty($t['completed']) && empty($t['googleEventId'])
        ));

        $scored = [];
        foreach ($pending as $todo) {
            $range = $this->todos->getTodoRange($todo);
            $isToday = false;
            $sortDate = '9999-12-31';
            $sortTime = '99:99';

            if ($range) {
                $end = $range['end'] ?? $range['start'];
                // 過去（期限超過）は出さない
                if ($end < $today) {
                    continue;
                }

                $isToday = $this->todos->dateInRange($today, $todo);
                $startTime = $this->normalizeTime($todo['startTime'] ?? null);

                if ($isToday) {
                    // 今日の分で開始時刻を過ぎたものは出さない
                    if ($startTime !== null) {
                        $startAt = Carbon::parse($today.' '.$startTime, $now->getTimezone());
                        if ($startAt->lt($now)) {
                            continue;
                        }
                        $sortTime = $startTime;
                    }
                    $sortDate = $today;
                } elseif ($range['start'] > $today) {
                    $days = (int) Carbon::parse($today)->diffInDays(Carbon::parse($range['start']));
                    if ($days > 7) {
                        continue;
                    }
                    $sortDate = $range['start'];
                    $sortTime = $startTime ?? '99:99';
                } else {
                    // 開始日は過去だが終了日は今日以降で、かつ今日に含まれないケースは除外
                    continue;
                }
            } else {
                // 日付未設定は後ろめに残す
                $sortDate = '9999-12-30';
            }

            $priority = 2;
            if ($isToday) {
                $priority = 0;
            } elseif (! $range) {
                $priority = 1;
            }

            $scored[] = [
                'todo' => $todo,
                'priority' => $priority,
                'sortDate' => $sortDate,
                'sortTime' => $sortTime,
                'isToday' => $isToday,
                'timeLabel' => $this->formatTodoTimeLabel($todo, $today),
            ];
        }

        usort($scored, static function (array $a, array $b) {
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }
            $dateCmp = strcmp($a['sortDate'], $b['sortDate']);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return strcmp($a['sortTime'], $b['sortTime']);
        });

        $out = [];
        foreach (array_slice($scored, 0, $limit) as $row) {
            $todo = $row['todo'];
            $out[] = [
                'id' => $todo['id'],
                'title' => $todo['title'] ?? '',
                'timeLabel' => $row['timeLabel'],
                'isOverdue' => false,
                'isToday' => $row['isToday'],
                'importance' => $todo['importance'] ?? 'medium',
                'url' => '/todos?today=1#todo-list-panel',
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $todos
     */
    private function todayPendingCount(array $todos, string $today): int
    {
        $count = 0;
        foreach ($todos as $todo) {
            if (! empty($todo['completed']) || ! empty($todo['googleEventId'])) {
                continue;
            }
            if (! $this->todos->getTodoRange($todo)) {
                continue;
            }
            if ($this->todos->dateInRange($today, $todo)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{
     *   connected: bool,
     *   events: list<array<string, mixed>>,
     *   next: ?array<string, mixed>,
     *   nextInLabel: ?string
     * }
     */
    private function todayGoogleEvents(User $user, Carbon $now): array
    {
        $connected = $this->googleCalendar->connectionFor($user) !== null;
        if (! $connected) {
            return [
                'connected' => false,
                'events' => [],
                'next' => null,
                'nextInLabel' => null,
            ];
        }

        $dayStart = $now->copy()->startOfDay();
        $dayEnd = $now->copy()->endOfDay();
        try {
            $raw = $this->googleCalendar->listEventsAsTodos(
                $user,
                $dayStart->toIso8601String(),
                $dayEnd->toIso8601String()
            );
        } catch (\Throwable $e) {
            report($e);
            $raw = [];
        }

        $events = [];
        foreach ($raw as $event) {
            $events[] = [
                'id' => $event['id'] ?? null,
                'title' => $event['title'] ?? __('予定'),
                'startTime' => $event['startTime'] ?? null,
                'endTime' => $event['endTime'] ?? null,
                'allDay' => empty($event['startTime']) && empty($event['endTime']),
                'timeLabel' => $this->formatEventTimeLabel($event),
                'htmlLink' => $event['htmlLink'] ?? null,
            ];
        }

        usort($events, static function (array $a, array $b) {
            if (($a['allDay'] ?? false) !== ($b['allDay'] ?? false)) {
                return ($a['allDay'] ?? false) ? 1 : -1;
            }

            return strcmp((string) ($a['startTime'] ?? '99:99'), (string) ($b['startTime'] ?? '99:99'));
        });

        $next = null;
        $nextInLabel = null;
        foreach ($events as $event) {
            if (! empty($event['allDay']) || empty($event['startTime'])) {
                continue;
            }
            $start = Carbon::parse($now->toDateString().' '.$event['startTime'], $now->getTimezone());
            if ($start->greaterThanOrEqualTo($now)) {
                $next = $event;
                $nextInLabel = $this->formatCountdown($now, $start);
                break;
            }
        }

        return [
            'connected' => true,
            'events' => $events,
            'next' => $next,
            'nextInLabel' => $nextInLabel,
        ];
    }

    /**
     * @return array{
     *   mode: string,
     *   title: string,
     *   subtitle: ?string,
     *   addedToday: int,
     *   items: list<array<string, mixed>>
     * }
     */
    private function photosMemories(int $userId, Carbon $now): array
    {
        $addedToday = (int) Photo::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->whereDate('created_at', $now->toDateString())
            ->count();

        $onThisDay = Photo::query()
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->whereNotNull('taken_at')
            ->whereMonth('taken_at', (int) $now->format('n'))
            ->whereDay('taken_at', (int) $now->format('j'))
            ->whereYear('taken_at', '<', (int) $now->format('Y'))
            ->orderByDesc('taken_at')
            ->limit(4)
            ->get();

        if ($onThisDay->isNotEmpty()) {
            $yearsAgo = (int) $now->format('Y') - (int) $onThisDay->first()->taken_at->format('Y');
            $items = $onThisDay
                ->map(fn (Photo $photo) => $this->photos->photoToArray($photo, $userId))
                ->all();

            return [
                'mode' => 'on_this_day',
                'title' => __(':years年前の今日', ['years' => $yearsAgo]),
                'subtitle' => __('思い出'),
                'addedToday' => $addedToday,
                'items' => $items,
            ];
        }

        $recent = $this->photos->listPhotos($userId, null, 'taken_desc', null, 4, 'active', 'loose');

        return [
            'mode' => 'recent',
            'title' => __('最近の写真'),
            'subtitle' => $addedToday > 0
                ? __('今日追加 :count枚', ['count' => $addedToday])
                : null,
            'addedToday' => $addedToday,
            'items' => $recent,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pinnedNotes(int $userId, int $limit): array
    {
        $notes = $this->notes->listNotes([
            'userId' => $userId,
            'archived' => false,
        ]);

        $pinned = array_values(array_filter(
            $notes,
            static fn (array $n) => ! empty($n['pinned'])
        ));

        $out = [];
        foreach (array_slice($pinned, 0, $limit) as $note) {
            $out[] = [
                'id' => $note['id'],
                'title' => $this->notes->getDisplayTitle($note),
                'url' => '/notes',
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $todo */
    private function formatTodoTimeLabel(array $todo, ?string $today = null): string
    {
        $start = $this->normalizeTime($todo['startTime'] ?? null);
        if ($start) {
            return $start;
        }
        $range = $this->todos->getTodoRange($todo);
        if ($range) {
            $today ??= $this->todos->getTodayDateString();
            if ($this->todos->dateInRange($today, $todo)) {
                return __('今日');
            }

            return Carbon::parse($range['start'])->format('n/j');
        }

        return __('いつでも');
    }

    /** @param array<string, mixed> $event */
    private function formatEventTimeLabel(array $event): string
    {
        $start = $this->normalizeTime($event['startTime'] ?? null);
        if ($start) {
            return $start;
        }

        return __('終日');
    }

    private function formatCountdown(Carbon $now, Carbon $start): string
    {
        $minutes = max(0, (int) ceil(($start->getTimestamp() - $now->getTimestamp()) / 60));
        if ($minutes < 1) {
            return __('まもなく開始');
        }
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        if ($hours <= 0) {
            return __('あと :mins分', ['mins' => $mins]);
        }
        if ($mins === 0) {
            return __('あと :hours時間', ['hours' => $hours]);
        }

        return __('あと :hours時間:mins分', ['hours' => $hours, 'mins' => $mins]);
    }

    private function normalizeTime(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return null;
    }
}
