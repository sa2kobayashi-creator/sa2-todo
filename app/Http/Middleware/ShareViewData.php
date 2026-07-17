<?php

namespace App\Http\Middleware;

use App\Services\FinanceService;
use App\Services\HolidayService;
use App\Services\NoteService;
use App\Services\TodoService;
use App\Services\TransitService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ShareViewData
{
    public function __construct(
        private TodoService $todos,
        private HolidayService $holidays,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        View::share([
            'importanceLabels' => TodoService::IMPORTANCE_LABELS,
            'categoryLabels' => TodoService::CATEGORY_LABELS,
            'weekdayLabels' => ['日', '月', '火', '水', '木', '金', '土'],
            'reminderLabels' => TodoService::REMINDER_LABELS,
            'reminderOptions' => TodoService::REMINDER_OPTIONS,
            'notifyViaLabels' => TodoService::NOTIFY_VIA_LABELS,
            'notifyViaOptions' => TodoService::NOTIFY_VIA_OPTIONS,
            'nationalHolidayDates' => $this->holidays->listAllJapaneseNationalHolidayDateKeys(),
            'closureDates' => $this->holidays->listAllClosureDateKeys(),
            'businessClosureDates' => $this->holidays->listAllBusinessClosureDateKeys(),
            'clearFiltersHref' => $this->todos->buildClearFiltersHref(),
            'noteColors' => NoteService::NOTE_COLORS,
            'colorKeys' => NoteService::COLOR_KEYS,
            'noteCategories' => NoteService::NOTE_CATEGORIES,
            'defaultCategory' => NoteService::DEFAULT_CATEGORY,
            'financeRegionLabels' => FinanceService::REGION_LABELS,
            'financeKindLabels' => FinanceService::KIND_LABELS,
            'financeTypeLabels' => FinanceService::TYPE_LABELS,
            'transitCategoryLabels' => TransitService::CATEGORY_LABELS,
        ]);

        if ($user = $request->user()) {
            View::share([
                'currentUser' => $user->toPublicArray(),
                'isAdmin' => $user->isAdmin(),
            ]);
        }

        return $next($request);
    }
}
