<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HolidayService;
use Illuminate\Http\Request;

class HolidayDatesController extends Controller
{
    public function __construct(private HolidayService $holidays) {}

    public function __invoke(Request $request)
    {
        $start = is_string($request->query('start')) ? $request->query('start') : '';
        $end = is_string($request->query('end')) ? $request->query('end') : '';

        return response()->json([
            'national' => $this->holidays->listJapaneseNationalHolidayDateKeysForRange($start, $end),
            'closure' => $this->holidays->listBusinessClosureDateKeysForRange($start, $end),
        ]);
    }
}
