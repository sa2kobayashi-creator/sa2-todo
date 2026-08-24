<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesHolidays;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Services\CalendarService;
use App\Services\HolidayService;
use Illuminate\Http\Request;

class PersonalHolidayController extends Controller
{
    use RedirectsWithFlash;
    use ManagesHolidays;

    public function __construct(private HolidayService $holidays) {}

    public function index(Request $request)
    {
        $year = (int) ($request->query('year') ?: date('Y'));
        $ownerId = (int) $request->user()->id;

        return view('mypage.holidays', array_merge($this->flashFromQuery($request), [
            'holidayYear' => $year,
            'holidays' => $this->holidays->listByYear($year, $ownerId),
            'weekdayRules' => $this->holidays->listWeekdayRules($ownerId),
            'weekdayLabels' => CalendarService::translatedWeekdayLabels(),
            'prevHolidayYear' => $year - 1,
            'nextHolidayYear' => $year + 1,
        ]));
    }

    protected function holidayOwnerUserId(Request $request): ?int
    {
        return (int) $request->user()->id;
    }

    protected function holidayReturnPath(Request $request, int $year): string
    {
        return '/mypage/holidays?year='.$year;
    }
}
