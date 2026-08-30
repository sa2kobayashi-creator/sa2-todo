<?php

namespace App\Http\Controllers;

use App\Enums\AppContext;
use App\Services\AppContextService;
use App\Services\CalendarService;
use App\Services\DashboardHomeService;
use App\Services\DisplayService;
use App\Services\GoogleCalendarService;
use App\Services\GroupService;
use App\Services\HolidayService;
use App\Services\NoteService;
use App\Services\TodoService;
use App\Services\TodoShortcutService;
use App\Services\UsageLimitPolicyService;
use App\Services\UserUsageLimitService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private TodoService $todos,
        private TodoShortcutService $todoShortcuts,
        private NoteService $notes,
        private CalendarService $calendar,
        private HolidayService $holidays,
        private DisplayService $display,
        private DashboardHomeService $home,
        private AppContextService $contexts,
        private GoogleCalendarService $googleCalendar,
        private GroupService $groups,
        private UsageLimitPolicyService $usageLimits,
        private UserUsageLimitService $userUsageLimits,
    ) {}

    public function index(Request $request)
    {
        $state = $this->calendar->resolveCalendarState($request->query());
        $view = $state['view'];
        $focusDate = $state['focusDate'];
        $y = $state['year'];
        $m = $state['month'];

        $holidayYears = [$y];
        if ($view === 'month') {
            $holidayYears[] = $this->calendar->shiftMonth($y, $m, -1)['year'];
            $holidayYears[] = $this->calendar->shiftMonth($y, $m, 1)['year'];
        } elseif ($view === 'week') {
            $weekStart = Carbon::parse($focusDate, config('app.timezone', 'Asia/Tokyo'))->startOfWeek(Carbon::SUNDAY);
            $weekEnd = $weekStart->copy()->addDays(6);
            $holidayYears[] = (int) $weekStart->format('Y');
            $holidayYears[] = (int) $weekEnd->format('Y');
        }
        $holidayYears = array_values(array_unique($holidayYears));

        $holidayMap = [];
        foreach ($holidayYears as $holidayYear) {
            $holidayMap = array_merge($holidayMap, $this->holidays->getHolidayInfoMapForYear($holidayYear, (int) $request->user()->id));
        }

        $user = $request->user();
        $userId = (int) $user->id;
        $context = $this->contexts->current($user, $request);
        $allTodos = $this->todos->listTodos($userId, $context)->all();
        $home = $this->home->build($user, $allTodos, $context);
        $usageRemaining = $this->usageLimits->remainingSummary($user, $this->userUsageLimits);
        $activeNotes = $this->notes->listActiveNotesForCalendar($userId);

        if ($context === AppContext::Work) {
            $allTodos = $this->mergeGoogleEvents($user, $allTodos, $view, $focusDate, $y, $m);
        }

        $undated = array_values(array_filter(
            $allTodos,
            fn (array $todo) => empty($todo['startDate']) && empty($todo['endDate'])
        ));

        $weeks = [];
        $dayView = null;
        $weekView = null;
        $yearView = null;

        if ($view === 'day') {
            $dayView = $this->calendar->buildDayView($focusDate, $allTodos, $holidayMap, $activeNotes);
        } elseif ($view === 'week') {
            $weekView = $this->calendar->buildWeekView($focusDate, $allTodos, $holidayMap, $activeNotes);
        } elseif ($view === 'year') {
            $yearView = $this->calendar->buildYearView($y, $allTodos, $holidayMap);
        } else {
            $grid = $this->calendar->buildMonthGrid($y, $m, $allTodos, $holidayMap);
            $grid = $this->calendar->attachNotesToGrid($grid, $activeNotes, fn ($note) => $this->notes->getRegisteredDate($note));
            $weeks = $grid['weeks'];
            $undated = $grid['undated'];
        }

        $prev = $this->calendar->shiftFocus($view, $focusDate, -1);
        $next = $this->calendar->shiftFocus($view, $focusDate, 1);
        $today = Carbon::now(config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d');
        $returnTo = $this->calendar->buildDashboardQuery($view, $focusDate);

        return view('dashboard', [
            'view' => $view,
            'viewLabels' => CalendarService::translatedViewLabels(),
            'focusDate' => $focusDate,
            'periodLabel' => $this->calendar->formatPeriodLabel($view, $focusDate),
            'year' => $y,
            'month' => $m,
            'prevUrl' => $this->calendar->buildDashboardQuery($view, $prev['focusDate']),
            'nextUrl' => $this->calendar->buildDashboardQuery($view, $next['focusDate']),
            // 「今日」は表示モードを変えず、今のビューのまま今日へ（既定の月カレンダーを維持）
            'todayUrl' => $this->calendar->buildDashboardQuery($view, $today),
            'buildViewUrl' => fn (string $targetView) => $this->calendar->buildDashboardQuery($targetView, $focusDate),
            'buildDashboardQuery' => fn (string $targetView, string $date) => $this->calendar->buildDashboardQuery($targetView, $date),
            'weekdayLabels' => CalendarService::translatedWeekdayLabels(),
            'weeks' => $weeks,
            'dayView' => $dayView,
            'weekView' => $weekView,
            'yearView' => $yearView,
            'undated' => $undated,
            'returnTo' => $returnTo,
            'monthAgenda' => $view === 'month' ? $this->listMonthAgenda($y, $m, $userId, $allTodos, $activeNotes) : [],
            'truncateTitle' => fn ($title, $max = 24) => $this->display->truncateTitle((string) $title, $max),
            'limitTodosForCell' => fn ($todos, $limit = 6) => $this->display->limitTodosForCell($todos, $limit),
            'limitCellItems' => fn ($todos, $notes, $limit = 6) => $this->display->limitCellItems($todos, $notes, $limit),
            'formatPeriodLabel' => fn ($todo) => $this->todos->formatPeriodLabel($todo),
            'formatNoteTooltip' => fn ($note) => $this->notes->formatNoteTooltip($note),
            'getNoteDisplayTitle' => fn ($note) => $this->notes->getDisplayTitle($note),
            'getNoteRegisteredDate' => fn ($note) => $this->notes->getRegisteredDate($note),
            'noteColors' => NoteService::NOTE_COLORS,
            'todosForJs' => $allTodos,
            'notesForJs' => $activeNotes,
            'formatEventTooltip' => fn ($todo) => $this->todos->formatEventTooltip($todo),
            'home' => $home,
            'usageWarnings' => $usageRemaining['warnings'] ?? [],
            'showLightFeedback' => $user->roleEnum() === \App\Enums\UserRole::Light,
            'googleCalendarConnected' => $this->googleCalendar->connectionFor($user) !== null,
            'approvedGroups' => $context === AppContext::Work ? [] : $this->groups->listApprovedForUser($userId),
            'pendingGroupInvitations' => $this->groups->listPendingInvitationsForUser($userId),
            'todoShortcuts' => $this->todoShortcuts->listForUser($userId),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function calendarRedirect(Request $request)
    {
        $query = http_build_query($request->query());

        return redirect('/dashboard'.($query ? '?'.$query : ''));
    }

    /** @return list<array<string, mixed>> */
    private function listMonthAgenda(int $year, int $month, int $userId, array $allTodos, array $activeNotes): array
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
            $dateCmp = strcmp($a['sortDate'], $b['sortDate']);
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
            $start = Carbon::parse($focusDate, config('app.timezone'))->startOfWeek(Carbon::SUNDAY);
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
}
