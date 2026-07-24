<?php

namespace App\Http\Controllers;

use App\Services\CalendarService;
use App\Services\DisplayService;
use App\Services\GroupService;
use App\Services\HolidayService;
use App\Services\NoteService;
use App\Services\TodoService;
use App\Services\TodoVoiceParseService;
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
    ) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $filters = $this->todos->parseFilters($request->query());
        $displayMode = ($request->query('display') === 'calendar') ? 'calendar' : 'list';
        $pageResult = $this->todos->filterTodosPage($this->todos->listTodos($userId), $filters);
        $editId = (int) $request->query('edit');
        $listQuery = $this->todos->buildTodosQuery($filters, [
            'display' => $displayMode === 'calendar' ? 'calendar' : null,
        ]);
        $defaultStart = is_string($request->query('due')) ? $request->query('due') : '';

        $weeks = [];
        $calendarYear = $filters['year'];
        $calendarMonth = $filters['month'];
        if ($displayMode === 'calendar') {
            $holidayMap = $this->holidays->getHolidayInfoMapForYear($calendarYear);
            $neighbor = $this->calendar->shiftMonth($calendarYear, $calendarMonth, -1);
            $holidayMap = array_merge($holidayMap, $this->holidays->getHolidayInfoMapForYear($neighbor['year']));
            $neighbor = $this->calendar->shiftMonth($calendarYear, $calendarMonth, 1);
            $holidayMap = array_merge($holidayMap, $this->holidays->getHolidayInfoMapForYear($neighbor['year']));

            $allTodos = $this->todos->listTodos($userId)->all();
            $activeNotes = $this->notes->listActiveNotesForCalendar($userId);
            $grid = $this->calendar->buildMonthGrid($calendarYear, $calendarMonth, $allTodos, $holidayMap);
            $grid = $this->calendar->attachNotesToGrid($grid, $activeNotes, fn ($note) => $this->notes->getRegisteredDate($note));
            $weeks = $grid['weeks'];
        }

        return view('todos.index', [
            'todos' => collect($pageResult['dated']['items'])->map(fn ($t) => $this->todos->mapTodoListRow($t))->all(),
            'undatedTodos' => collect($pageResult['undated'])->map(fn ($t) => $this->todos->mapTodoListRow($t, ['undated' => true]))->all(),
            'pagination' => $pageResult['dated'],
            'datedTotal' => $pageResult['datedTotal'],
            'filters' => $filters,
            'displayMode' => $displayMode,
            'listQuery' => $listQuery,
            'listReturnTo' => '/todos'.$listQuery.'#todo-list-panel',
            'buildTodosQuery' => fn (array $extra = []) => $this->todos->buildTodosQuery($filters, $extra),
            'todayFilterHref' => $this->todos->buildTodayFilterHref($filters),
            'clearFiltersHref' => $this->todos->buildClearFiltersHref(),
            'periodValue' => $filters['scope'] === 'today' ? '' : sprintf('%04d-%02d', $filters['year'], $filters['month']),
            'periodYearValue' => (string) $filters['year'],
            'periodMode' => $filters['periodMode'] ?? 'month',
            'editId' => $editId > 0 ? $editId : null,
            'defaultStartDate' => $defaultStart,
            'defaultEndDate' => $defaultStart,
            'weeks' => $weeks,
            'calendarYear' => $calendarYear,
            'calendarMonth' => $calendarMonth,
            'weekdayLabels' => CalendarService::translatedWeekdayLabels(),
            'truncateTitle' => fn ($title, $max = 24) => $this->display->truncateTitle((string) $title, $max),
            'limitTodosForCell' => fn ($todos, $limit = 4) => $this->display->limitTodosForCell($todos, $limit),
            'formatPeriodLabel' => fn ($todo) => $this->todos->formatPeriodLabel($todo),
            'formatNoteTooltip' => fn ($note) => $this->notes->formatNoteTooltip($note),
            'getNoteDisplayTitle' => fn ($note) => $this->notes->getDisplayTitle($note),
            'noteColors' => NoteService::NOTE_COLORS,
            'dashboardMonthUrl' => $this->calendar->buildDashboardQuery(
                'month',
                sprintf('%04d-%02d-01', $calendarYear, $calendarMonth)
            ),
            'approvedGroups' => $this->groups->listApprovedForUser($userId),
            'voiceAiReady' => $this->voiceParse->isReady(),
            'voiceAiProvider' => $this->voiceParse->isReady() ? $this->voiceParse->activeProviderLabel() : null,
            ...$this->flashFromQuery($request),
        ]);
    }

    public function parseVoice(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $transcript = trim((string) $request->input('transcript', ''));
        if ($transcript === '') {
            return response()->json(['ok' => false, 'message' => __('音声テキストが空です。')], 422);
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
            return $this->redirectWithMessage($returnTo, 'ToDo の内容を入力してください', 'error');
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
                'reminders' => $request->input('reminders', []),
                'notifyVia' => $this->todos->parseNotifyViaFromBody($request->input('notifyVia')),
                'weekdays' => $request->input('weekdays', []),
                'excludeHolidays' => $request->input('excludeHolidays'),
                'excludeClosures' => $request->input('excludeClosures'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $count = count($titles);

        return $this->redirectWithMessage($returnTo, "ToDo を {$count} 件追加しました");
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
            'startDate' => $startDate,
            'endDate' => $endDate,
            'startTime' => $request->input('startTime'),
            'endTime' => $request->input('endTime'),
            'importance' => $request->input('importance'),
            'category' => $request->input('category'),
            'reminders' => $this->todos->parseRemindersFromBody($request->input('reminders')),
            'notifyVia' => $this->todos->parseNotifyViaFromBody($request->input('notifyVia')),
            'completed' => $request->has('completed') ? $request->boolean('completed') : null,
        ]);

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, 'ToDo が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, 'ToDo を更新しました');
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

        return $this->redirectWithMessage($returnTo, 'ToDo を削除しました');
    }

    public function duplicate(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        if (! $this->canAccessTodo($request, $id)) {
            return $this->redirectWithMessage($returnTo, __('この ToDo を操作する権限がありません。'), 'error');
        }
        $this->todos->duplicateTodo($id);

        return $this->redirectWithMessage($returnTo, 'ToDo を複製しました');
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
            return $this->redirectWithMessage($returnTo, 'ToDo を移動できませんでした', 'error');
        }

        return $this->redirectWithMessage($returnTo, 'ToDo の日付を変更しました');
    }

    public function bulkComplete(Request $request)
    {
        return $this->bulkSetCompleted($request, true, '一括で完了にしました');
    }

    public function bulkUncomplete(Request $request)
    {
        return $this->bulkSetCompleted($request, false, '一括で未完了にしました');
    }

    public function bulkDelete(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $ids = $this->accessibleTodoIds($request);
        $count = $this->todos->bulkDelete($ids);

        return $this->redirectWithMessage($returnTo, "{$count} 件削除しました");
    }

    public function bulkDuplicate(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $ids = $this->accessibleTodoIds($request);
        $count = $this->todos->bulkDuplicate($ids);

        return $this->redirectWithMessage($returnTo, "{$count} 件複製しました");
    }

    private function bulkSetCompleted(Request $request, bool $completed, string $message)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $ids = $this->accessibleTodoIds($request);
        $count = $this->todos->bulkSetCompleted($ids, $completed);

        return $this->redirectWithMessage($returnTo, "{$count} 件{$message}");
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
}
