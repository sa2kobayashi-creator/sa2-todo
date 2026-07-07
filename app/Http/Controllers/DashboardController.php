<?php

namespace App\Http\Controllers;

use App\Services\CalendarService;
use App\Services\DisplayService;
use App\Services\HolidayService;
use App\Services\NoteService;
use App\Services\TodoService;
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
        ['year' => $y, 'month' => $m] = $this->calendar->resolveMonth($request->query('year'), $request->query('month'));

        $holidayMap = array_merge(
            $this->holidays->getHolidayInfoMapForYear($y),
            $this->holidays->getHolidayInfoMapForYear($this->calendar->shiftMonth($y, $m, -1)['year']),
            $this->holidays->getHolidayInfoMapForYear($this->calendar->shiftMonth($y, $m, 1)['year']),
        );

        $allTodos = $this->todos->listTodos()->all();
        $grid = $this->calendar->buildMonthGrid($y, $m, $allTodos, $holidayMap);
        $activeNotes = $this->notes->listActiveNotesForCalendar();
        $grid = $this->calendar->attachNotesToGrid($grid, $activeNotes, fn ($note) => $this->notes->getRegisteredDate($note));

        return view('dashboard', [
            'year' => $y,
            'month' => $m,
            'prev' => $this->calendar->shiftMonth($y, $m, -1),
            'next' => $this->calendar->shiftMonth($y, $m, 1),
            'weekdayLabels' => CalendarService::WEEKDAY_LABELS,
            'weeks' => $grid['weeks'],
            'undated' => $grid['undated'],
            'returnTo' => "/dashboard?year={$y}&month={$m}",
            'monthAgenda' => $this->listMonthAgenda($y, $m),
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
    private function listMonthAgenda(int $year, int $month): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));

        $items = [];
        foreach ($this->todos->listTodos() as $todo) {
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
