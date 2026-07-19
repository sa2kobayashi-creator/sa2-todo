<?php

namespace App\Http\Middleware;

use App\Services\CalendarService;
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
            'importanceLabels' => [
                'high' => __('高'),
                'medium' => __('中'),
                'low' => __('低'),
            ],
            'categoryLabels' => [
                'task' => __('タスク'),
                'personal' => __('個人'),
                'memo' => __('メモ'),
                'other' => __('その他'),
            ],
            'weekdayLabels' => CalendarService::translatedWeekdayLabels(),
            'reminderLabels' => [
                'at9am' => __('当日9時'),
                '30min' => __('30分前'),
                '1hour' => __('1時間前'),
                '1day' => __('1日前'),
            ],
            'reminderOptions' => TodoService::REMINDER_OPTIONS,
            'notifyViaLabels' => [
                'push' => __('プッシュ通知'),
                'push_sound' => __('プッシュ（音あり）'),
                'line' => __('LINE'),
            ],
            'notifyViaOptions' => TodoService::NOTIFY_VIA_OPTIONS,
            'nationalHolidayDates' => $this->holidays->listAllJapaneseNationalHolidayDateKeys(),
            'closureDates' => $this->holidays->listAllClosureDateKeys(),
            'businessClosureDates' => $this->holidays->listAllBusinessClosureDateKeys(),
            'clearFiltersHref' => $this->todos->buildClearFiltersHref(),
            'noteColors' => collect(NoteService::NOTE_COLORS)->mapWithKeys(fn (array $color, string $key) => [
                $key => [
                    'label' => __($color['label']),
                    'bg' => $color['bg'],
                    'border' => $color['border'],
                ],
            ])->all(),
            'colorKeys' => NoteService::COLOR_KEYS,
            'noteCategories' => [
                'personal' => __('個人'),
                'work' => __('仕事'),
                'money' => __('お金'),
                'idea' => __('アイデア'),
                'word' => __('言葉'),
                'cooking' => __('料理'),
                'hobby' => __('趣味'),
                'other' => __('その他'),
            ],
            'defaultCategory' => NoteService::DEFAULT_CATEGORY,
            'financeRegionLabels' => [
                'jp' => __('日本'),
                'ph' => __('フィリピン'),
            ],
            'financeKindLabels' => [
                'bank' => __('銀行'),
                'cash' => __('現金'),
                'credit_card' => __('クレカ'),
                'wallet' => __('ウォレット'),
            ],
            'financeTypeLabels' => [
                'income' => __('収入'),
                'expense' => __('支出'),
                'transfer' => __('振替'),
            ],
            'transitCategoryLabels' => [
                'nishitetsu_bus' => __('西鉄バス'),
                'jr' => __('JR'),
                'ferry' => __('市営渡船'),
                'nishitetsu_rail' => __('西鉄電車'),
                'subway' => __('地下鉄'),
            ],
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
