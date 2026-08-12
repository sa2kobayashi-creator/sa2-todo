<?php

namespace App\Http\Controllers;

use App\Enums\AppContext;
use App\Exceptions\UsageLimitExceededException;
use App\Services\AppContextService;
use App\Services\CalendarService;
use App\Services\DisplayService;
use App\Services\GoogleCalendarService;
use App\Services\GroupService;
use App\Services\HolidayService;
use App\Services\NoteService;
use App\Services\TodoService;
use App\Services\TodoVoiceParseService;
use App\Services\UserUsageLimitService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    use Concerns\RedirectsWithFlash;
    use Concerns\ParsesVoiceTranscript;

    public function __construct(
        private TodoService $todos,
        private CalendarService $calendar,
        private HolidayService $holidays,
        private NoteService $notes,
        private DisplayService $display,
        private GroupService $groups,
        private TodoVoiceParseService $voiceParse,
        private AppContextService $contexts,
        private GoogleCalendarService $googleCalendar,
        private UserUsageLimitService $usageLimits,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $userId = (int) $user->id;
        $context = $this->contexts->current($user, $request);
        $filters = $this->todos->parseFilters($request->query());
        // 既定はカレンダー。一覧は display=list のときだけ
        $displayMode = $request->query('display') === 'list' ? 'list' : 'calendar';
        $calState = $this->calendar->resolveCalendarState([
            'view' => $request->query('view'),
            'date' => $request->query('date'),
            'year' => $request->query('year') ?? $filters['year'],
            'month' => $request->query('month') ?? $filters['month'],
        ]);
        $view = $calState['view'];
        $focusDate = $calState['focusDate'];
        $calendarYear = $calState['year'];
        $calendarMonth = $calState['month'];
        $listed = $this->todos->listTodos($userId, $context);
        $listedTodos = $listed->all();

        // 仕事モードは Google 予定をマージ（Meet 等をローカルにも反映）
        $mergedTodos = $listedTodos;
        if ($context === AppContext::Work) {
            $mergedTodos = $this->mergeGoogleEvents($user, $listedTodos, $view, $focusDate, $calendarYear, $calendarMonth);
        }

        $googleExtrasById = [];
        foreach ($mergedTodos as $todo) {
            $id = $todo['id'] ?? null;
            if (! is_numeric($id)) {
                continue;
            }
            $googleExtrasById[(int) $id] = [
                'googleMeetLink' => $todo['googleMeetLink'] ?? null,
                'htmlLink' => $todo['htmlLink'] ?? null,
            ];
        }

        $pageResult = $this->todos->filterTodosPage($listed, $filters);
        $editId = (int) $request->query('edit');
        $calNavExtra = $this->todosCalendarQueryExtra($view, $focusDate);
        $listQuery = $this->todos->buildTodosQuery($filters, array_merge(
            ['display' => $displayMode === 'list' ? 'list' : null],
            $displayMode === 'calendar' ? $calNavExtra : []
        ));
        $defaultStart = is_string($request->query('due')) ? $request->query('due') : '';

        $weeks = [];
        $dayView = null;
        $weekView = null;
        $yearView = null;
        $todosForJs = [];
        $monthAgenda = [];
        $activeNotes = [];
        $prev = $this->calendar->shiftFocus($view, $focusDate, -1);
        $next = $this->calendar->shiftFocus($view, $focusDate, 1);
        $today = Carbon::now(config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d');
        $buildTodosCalUrl = fn (string $targetView, string $date) => $this->buildTodosCalendarUrl($filters, $targetView, $date);

        if ($displayMode === 'calendar') {
            $holidayYears = [$calendarYear];
            if ($view === 'month') {
                $holidayYears[] = $this->calendar->shiftMonth($calendarYear, $calendarMonth, -1)['year'];
                $holidayYears[] = $this->calendar->shiftMonth($calendarYear, $calendarMonth, 1)['year'];
            } elseif ($view === 'week') {
                $weekStart = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'))->startOfWeek(Carbon::SUNDAY);
                $weekEnd = $weekStart->copy()->addDays(6);
                $holidayYears[] = (int) $weekStart->format('Y');
                $holidayYears[] = (int) $weekEnd->format('Y');
            }
            $holidayMap = [];
            foreach (array_values(array_unique($holidayYears)) as $holidayYear) {
                $holidayMap = array_merge($holidayMap, $this->holidays->getHolidayInfoMapForYear($holidayYear));
            }

            $todosForJs = $mergedTodos;
            $activeNotes = $this->notes->listActiveNotesForCalendar($userId);

            if ($view === 'day') {
                $dayView = $this->calendar->buildDayView($focusDate, $mergedTodos, $holidayMap, $activeNotes);
            } elseif ($view === 'week') {
                $weekView = $this->calendar->buildWeekView($focusDate, $mergedTodos, $holidayMap, $activeNotes);
            } elseif ($view === 'year') {
                $yearView = $this->calendar->buildYearView($calendarYear, $mergedTodos, $holidayMap);
            } else {
                $grid = $this->calendar->buildMonthGrid($calendarYear, $calendarMonth, $mergedTodos, $holidayMap);
                $grid = $this->calendar->attachNotesToGrid($grid, $activeNotes, fn ($note) => $this->notes->getRegisteredDate($note));
                $weeks = $grid['weeks'];
                $monthAgenda = $this->buildMonthAgenda($calendarYear, $calendarMonth, $mergedTodos, $activeNotes);
            }
        }

        $applyGoogleExtras = function (array $row) use ($googleExtrasById): array {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && isset($googleExtrasById[$id])) {
                if (! empty($googleExtrasById[$id]['googleMeetLink'])) {
                    $row['googleMeetLink'] = $googleExtrasById[$id]['googleMeetLink'];
                }
                if (! empty($googleExtrasById[$id]['htmlLink'])) {
                    $row['htmlLink'] = $googleExtrasById[$id]['htmlLink'];
                }
            }

            return $row;
        };

        $datedTodos = collect($pageResult['dated']['items'])
            ->map(fn ($t) => $applyGoogleExtras($this->todos->mapTodoListRow($t)))
            ->all();
        $undatedTodos = collect($pageResult['undated'])
            ->map(fn ($t) => $applyGoogleExtras($this->todos->mapTodoListRow($t, ['undated' => true])))
            ->all();
        // カレンダーから開いた編集対象がページ外でも、同じ ToDo 画面で編集行を出せるようにする
        if ($editId > 0) {
            $inList = collect($datedTodos)->contains(fn ($t) => (int) ($t['id'] ?? 0) === $editId)
                || collect($undatedTodos)->contains(fn ($t) => (int) ($t['id'] ?? 0) === $editId);
            if (! $inList) {
                $editTodo = collect($listedTodos)->first(fn ($t) => (int) ($t['id'] ?? 0) === $editId);
                if (is_array($editTodo) && $this->todos->userCanAccessTodo($userId, $editTodo)) {
                    array_unshift($datedTodos, $applyGoogleExtras($this->todos->mapTodoListRow($editTodo)));
                }
            }
        }

        return view('todos.index', [
            'todos' => $datedTodos,
            'undatedTodos' => $undatedTodos,
            'pagination' => $pageResult['dated'],
            'datedTotal' => $pageResult['datedTotal'],
            'filters' => $filters,
            'displayMode' => $displayMode,
            'listQuery' => $listQuery,
            'listReturnTo' => '/todos'.$listQuery.'#todo-list-panel',
            'buildTodosQuery' => fn (array $extra = []) => $this->todos->buildTodosQuery(
                $filters,
                array_merge(
                    ['display' => $displayMode === 'list' ? 'list' : null],
                    $displayMode === 'calendar' ? $calNavExtra : [],
                    $extra
                )
            ),
            'todayFilterHref' => '/todos'.$this->todos->buildTodosQuery(
                [...$filters, 'scope' => 'today'],
                ['display' => $displayMode === 'list' ? 'list' : null]
            ).'#todo-list-panel',
            'clearFiltersHref' => '/todos'.($displayMode === 'list' ? '?display=list' : '').'#todo-list-panel',
            'periodValue' => $filters['scope'] === 'today' ? '' : sprintf('%04d-%02d', $filters['year'], $filters['month']),
            'periodYearValue' => (string) $filters['year'],
            'periodMode' => $filters['periodMode'] ?? 'month',
            'editId' => $editId > 0 ? $editId : null,
            'defaultStartDate' => $defaultStart,
            'defaultEndDate' => $defaultStart,
            'view' => $view,
            'viewLabels' => CalendarService::translatedViewLabels(),
            'focusDate' => $focusDate,
            'periodLabel' => $this->calendar->formatPeriodLabel($view, $focusDate),
            'prevUrl' => $buildTodosCalUrl($view, $prev['focusDate']),
            'nextUrl' => $buildTodosCalUrl($view, $next['focusDate']),
            'todayUrl' => $buildTodosCalUrl($view, $today),
            'buildViewUrl' => fn (string $targetView) => $buildTodosCalUrl($targetView, $focusDate),
            'buildDashboardQuery' => $buildTodosCalUrl,
            'weeks' => $weeks,
            'dayView' => $dayView,
            'weekView' => $weekView,
            'yearView' => $yearView,
            'todosForJs' => $todosForJs,
            'monthAgenda' => $monthAgenda,
            'calendarYear' => $calendarYear,
            'calendarMonth' => $calendarMonth,
            'weekdayLabels' => CalendarService::translatedWeekdayLabels(),
            'truncateTitle' => fn ($title, $max = 24) => $this->display->truncateTitle((string) $title, $max),
            'limitTodosForCell' => fn ($todos, $limit = 6) => $this->display->limitTodosForCell($todos, $limit),
            'formatPeriodLabel' => fn ($todo) => $this->todos->formatPeriodLabel($todo),
            'formatNoteTooltip' => fn ($note) => $this->notes->formatNoteTooltip($note),
            'getNoteDisplayTitle' => fn ($note) => $this->notes->getDisplayTitle($note),
            'getNoteRegisteredDate' => fn ($note) => $this->notes->getRegisteredDate($note),
            'noteColors' => NoteService::NOTE_COLORS,
            'dashboardMonthUrl' => $this->calendar->buildDashboardQuery(
                'month',
                sprintf('%04d-%02d-01', $calendarYear, $calendarMonth)
            ),
            'approvedGroups' => $context === AppContext::Work ? [] : $this->groups->listApprovedForUser($userId),
            'voiceAiReady' => $request->user()?->isSuperAdmin() && $this->voiceParse->isReady(),
            'voiceAiProvider' => ($request->user()?->isSuperAdmin() && $this->voiceParse->isReady()) ? $this->voiceParse->activeProviderLabel() : null,
            'googleCalendarConnected' => $context === AppContext::Work
                && $this->googleCalendar->connectionFor($user) !== null,
            ...$this->flashFromQuery($request),
        ]);
    }

    public function parseVoice(Request $request): JsonResponse
    {
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['ok' => false, 'message' => __('この機能はスーパー管理者のみ利用できます。')], 403);
        }

        $userId = (int) $request->user()->id;
        $transcript = trim((string) $request->input('transcript', ''));
        if ($transcript === '') {
            return response()->json(['ok' => false, 'message' => __('音声テキストが空です。')], 422);
        }

        try {
            $this->usageLimits->consume($request->user(), UserUsageLimitService::FEATURE_LLM_VOICE, 1);
        } catch (UsageLimitExceededException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 429);
        }

        return $this->voiceParseJsonResponse(fn () => $this->voiceParse->parse(
            $transcript,
            $this->voiceGroups($userId, $this->groups),
            now()->toDateString()
        ));
    }

    public function store(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $titles = $this->todos->parseInput(
            $request->input('titles') ?: $request->input('title'),
            [
                'splitByLine' => $request->boolean('splitByLine', true),
            ]
        );

        if (count($titles) === 0) {
            return $this->redirectWithMessage($returnTo, __('ToDo の内容を入力してください'), 'error');
        }

        $dateMode = $request->input('dateMode', 'single');
        $startDate = $request->input('startDate');
        $endDate = $dateMode === 'range' ? $request->input('endDate') : $startDate;

        try {
            $this->todos->addTodos($titles, [
                'userId' => (int) $request->user()->id,
                'groupId' => $request->input('groupId'),
                'startDate' => $startDate,
                'endDate' => $endDate,
                'importance' => $request->input('importance'),
                'category' => $request->input('category'),
                'startTime' => $request->input('startTime'),
                'endTime' => $request->input('endTime'),
                'memo' => $request->input('memo'),
                'reminders' => $request->input('reminders', []),
                'reminderTime' => $request->input('reminderTime'),
                'notifyVia' => $this->todos->parseNotifyViaFromBody($request->input('notifyVia')),
                'weekdays' => $request->input('weekdays', []),
                'excludeHolidays' => $request->input('excludeHolidays'),
                'excludeClosures' => $request->input('excludeClosures'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $count = count($titles);

        return $this->redirectWithMessage($returnTo, __('ToDo を :count 件追加しました', ['count' => $count]));
    }

    public function update(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        if (! $this->canAccessTodo($request, $id)) {
            return $this->redirectWithMessage($returnTo, __('この ToDo を操作する権限がありません。'), 'error');
        }

        $dateMode = $request->input('dateMode', 'range');
        $startDate = $request->input('startDate');
        $endDate = $dateMode === 'single' ? $startDate : $request->input('endDate');

        $updated = $this->todos->updateTodo($id, [
            'title' => $request->input('title'),
            'memo' => $request->input('memo'),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'startTime' => $request->input('startTime'),
            'endTime' => $request->input('endTime'),
            'importance' => $request->input('importance'),
            'category' => $request->input('category'),
            'reminders' => $this->todos->parseRemindersFromBody($request->input('reminders'), $request->input('reminderTime')),
            'notifyVia' => $this->todos->parseNotifyViaFromBody($request->input('notifyVia')),
            'completed' => $request->has('completed') ? $request->boolean('completed') : null,
        ]);

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, __('ToDo が見つかりません'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('ToDo を更新しました'));
    }

    public function toggle(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        if (! $this->canAccessTodo($request, $id)) {
            return $this->redirectWithMessage($returnTo, __('この ToDo を操作する権限がありません。'), 'error');
        }
        $this->todos->toggleTodo($id);

        return redirect($returnTo);
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        if (! $this->canAccessTodo($request, $id)) {
            return $this->redirectWithMessage($returnTo, __('この ToDo を操作する権限がありません。'), 'error');
        }
        $this->todos->deleteTodo($id);

        return $this->redirectWithMessage($returnTo, __('ToDo を削除しました'));
    }

    public function duplicate(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        if (! $this->canAccessTodo($request, $id)) {
            return $this->redirectWithMessage($returnTo, __('この ToDo を操作する権限がありません。'), 'error');
        }
        $this->todos->duplicateTodo($id);

        return $this->redirectWithMessage($returnTo, __('ToDo を複製しました'));
    }

    public function reschedule(Request $request, int $id)
    {
        if (! $this->canAccessTodo($request, $id)) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => __('この ToDo を操作する権限がありません。')], 403);
            }

            return $this->redirectWithMessage(
                $this->safeReturnTo($request->input('returnTo')),
                __('この ToDo を操作する権限がありません。'),
                'error'
            );
        }

        $date = (string) ($request->input('date') ?: $request->json('date') ?: '');
        $updated = $this->todos->rescheduleTodo($id, $date);
        if ($request->expectsJson() || $request->ajax()) {
            if (! $updated) {
                return response()->json(['ok' => false, 'message' => 'ToDo を移動できませんでした'], 422);
            }

            return response()->json(['ok' => true, 'todo' => $updated]);
        }

        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        if (! $updated) {
            return $this->redirectWithMessage($returnTo, __('ToDo を移動できませんでした'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('ToDo の日付を変更しました'));
    }

    public function bulkComplete(Request $request)
    {
        return $this->bulkSetCompleted($request, true, __('一括で完了にしました'));
    }

    public function bulkUncomplete(Request $request)
    {
        return $this->bulkSetCompleted($request, false, __('一括で未完了にしました'));
    }

    public function bulkDelete(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $ids = $this->accessibleTodoIds($request);
        $count = $this->todos->bulkDelete($ids);

        return $this->redirectWithMessage($returnTo, __(':count 件削除しました', ['count' => $count]));
    }

    public function bulkDuplicate(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $ids = $this->accessibleTodoIds($request);
        $count = $this->todos->bulkDuplicate($ids);

        return $this->redirectWithMessage($returnTo, __(':count 件複製しました', ['count' => $count]));
    }

    private function bulkSetCompleted(Request $request, bool $completed, string $message)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $ids = $this->accessibleTodoIds($request);
        $count = $this->todos->bulkSetCompleted($ids, $completed);

        return $this->redirectWithMessage($returnTo, __(':count 件:message', ['count' => $count, 'message' => $message]));
    }

    private function canAccessTodo(Request $request, int $id): bool
    {
        return $this->todos->userCanAccessTodo(
            (int) $request->user()->id,
            $this->todos->getTodo($id)
        );
    }

    /** @return list<int> */
    private function accessibleTodoIds(Request $request): array
    {
        $userId = (int) $request->user()->id;

        return array_values(array_filter(
            $this->todos->parseIdList($request->input('ids')),
            fn (int $id) => $this->todos->userCanAccessTodo($userId, $this->todos->getTodo($id))
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $localTodos
     * @return list<array<string, mixed>>
     */
    private function mergeGoogleEvents($user, array $localTodos, string $view, string $focusDate, int $year, int $month): array
    {
        if ($view === 'day') {
            $timeMin = $focusDate.' 00:00:00';
            $timeMax = $focusDate.' 23:59:59';
        } elseif ($view === 'week') {
            $start = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'))->startOfWeek(Carbon::SUNDAY);
            $end = $start->copy()->addDays(6);
            $timeMin = $start->format('Y-m-d').' 00:00:00';
            $timeMax = $end->format('Y-m-d').' 23:59:59';
        } elseif ($view === 'year') {
            $timeMin = sprintf('%04d-01-01 00:00:00', $year);
            $timeMax = sprintf('%04d-12-31 23:59:59', $year);
        } else {
            $prev = $this->calendar->shiftMonth($year, $month, -1);
            $next = $this->calendar->shiftMonth($year, $month, 1);
            $timeMin = sprintf('%04d-%02d-01 00:00:00', $prev['year'], $prev['month']);
            $lastDay = cal_days_in_month(CAL_GREGORIAN, $next['month'], $next['year']);
            $timeMax = sprintf('%04d-%02d-%02d 23:59:59', $next['year'], $next['month'], $lastDay);
        }

        return $this->googleCalendar->mergeEventsIntoTodos($user, $localTodos, $timeMin, $timeMax);
    }

    /** @return array<string, string> */
    private function todosCalendarQueryExtra(string $view, string $focusDate): array
    {
        $focus = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'));
        $extra = [];
        if ($view !== 'month') {
            $extra['view'] = $view;
        }
        if (in_array($view, ['day', 'week'], true)) {
            $extra['date'] = $focus->format('Y-m-d');
        }

        return $extra;
    }

    /** @param array<string, mixed> $filters */
    private function buildTodosCalendarUrl(array $filters, string $view, string $focusDate): string
    {
        $focus = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'));
        $year = (int) $focus->format('Y');
        $month = (int) $focus->format('n');
        $isYear = $view === 'year';
        $calFilters = [
            ...$filters,
            'scope' => $isYear ? 'year' : 'month',
            'periodMode' => $isYear ? 'year' : 'month',
            'year' => $year,
            'month' => $month,
            'page' => 1,
        ];

        return '/todos'.$this->todos->buildTodosQuery(
            $calFilters,
            array_merge(['display' => null], $this->todosCalendarQueryExtra($view, $focusDate))
        ).'#todo-list-panel';
    }

    /**
     * @param  list<array<string, mixed>>  $allTodos
     * @param  list<array<string, mixed>>  $activeNotes
     * @return list<array<string, mixed>>
     */
    private function buildMonthAgenda(int $year, int $month, array $allTodos, array $activeNotes): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));

        $items = [];
        foreach ($allTodos as $todo) {
            $range = $this->todos->getTodoRange($todo);
            if (! $range || $range['start'] > $monthEnd || $range['end'] < $monthStart) {
                continue;
            }
            $items[] = [
                'kind' => 'todo',
                'sortDate' => $todo['startDate'] ?? $todo['endDate'] ?? '',
                'sortTime' => $todo['startTime'] ?? '',
                'todo' => $todo,
            ];
        }
        foreach ($activeNotes as $note) {
            $d = $this->notes->getRegisteredDate($note);
            if (! $d || $d < $monthStart || $d > $monthEnd) {
                continue;
            }
            $items[] = [
                'kind' => 'note',
                'sortDate' => $d,
                'sortTime' => '',
                'note' => $note,
            ];
        }
        usort($items, function ($a, $b) {
            $dateCmp = strcmp((string) $a['sortDate'], (string) $b['sortDate']);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
            if ($a['kind'] !== $b['kind']) {
                return $a['kind'] === 'todo' ? -1 : 1;
            }
            if ($a['kind'] === 'todo') {
                return $this->display->compareTodosByDayTime($a['todo'], $b['todo']);
            }

            return 0;
        });

        return $items;
    }
}
