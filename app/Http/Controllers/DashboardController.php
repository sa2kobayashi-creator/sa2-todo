<?php

namespace App\Http\Controllers;

use App\Services\CalendarService;
use App\Services\DisplayService;
use App\Services\HolidayService;
use App\Services\NoteService;
use App\Services\TodoService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private TodoService $todos,
        private NoteService $notes,
        private CalendarService $calendar,
        private HolidayService $holidays,
        private DisplayService $display,
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
            $holidayMap = array_merge($holidayMap, $this->holidays->getHolidayInfoMapForYear($holidayYear));
        }

        $userId = (int) $request->user()->id;
        $allTodos = $this->todos->listTodos($userId)->all();
        $activeNotes = $this->notes->listActiveNotesForCalendar();
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
            'todayUrl' => $this->calendar->buildDashboardQuery('day', $today),
            'buildViewUrl' => fn (string $targetView) => $this->calendar->buildDashboardQuery($targetView, $focusDate),
            'buildDashboardQuery' => fn (string $targetView, string $date) => $this->calendar->buildDashboardQuery($targetView, $date),
            'weekdayLabels' => CalendarService::translatedWeekdayLabels(),
            'weeks' => $weeks,
            'dayView' => $dayView,
            'weekView' => $weekView,
            'yearView' => $yearView,
            'undated' => $undated,
            'returnTo' => $returnTo,
            'monthAgenda' => $view === 'month' ? $this->listMonthAgenda($y, $m, $userId) : [],
            'truncateTitle' => fn ($title, $max = 24) => $this->display->truncateTitle((string) $title, $max),
            'limitTodosForCell' => fn ($todos, $limit = 4) => $this->display->limitTodosForCell($todos, $limit),
            'limitCellItems' => fn ($todos, $notes, $limit = 4) => $this->display->limitCellItems($todos, $notes, $limit),
            'formatPeriodLabel' => fn ($todo) => $this->todos->formatPeriodLabel($todo),
            'formatNoteTooltip' => fn ($note) => $this->notes->formatNoteTooltip($note),
            'getNoteDisplayTitle' => fn ($note) => $this->notes->getDisplayTitle($note),
            'getNoteRegisteredDate' => fn ($note) => $this->notes->getRegisteredDate($note),
            'noteColors' => NoteService::NOTE_COLORS,
            'todosForJs' => $allTodos,
            'notesForJs' => $activeNotes,
            'formatEventTooltip' => fn ($todo) => $this->todos->formatEventTooltip($todo),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function calendarRedirect(Request $request)
    {
        $query = http_build_query($request->query());

        return redirect('/dashboard'.($query ? '?'.$query : ''));
    }

    /** @return list<array<string, mixed>> */
    private function listMonthAgenda(int $year, int $month, int $userId): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));

        $items = [];
        foreach ($this->todos->listTodos($userId) as $todo) {
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
        foreach ($this->notes->listNotesForMonth($year, $month) as $note) {
            $items[] = [
                'kind' => 'note',
                'sortDate' => $this->notes->getRegisteredDate($note),
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
}
