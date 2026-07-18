<?php

namespace App\Http\Controllers;

use App\Models\AiApiKey;
use App\Models\TranslationApiKey;
use App\Services\CalendarService;
use App\Services\HolidayService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private HolidayService $holidays) {}

    public function index(Request $request)
    {
        $year = (int) ($request->query('year') ?: date('Y'));
        $section = $this->parseSection($request->query('section'));
        $aiTab = $request->query('tab') === 'chat' ? 'chat' : 'translation';

        return view('settings.index', [
            'section' => $section,
            'settingsSection' => $section,
            'aiTab' => $aiTab,
            'holidayYear' => $year,
            'holidays' => $this->holidays->listByYear($year),
            'weekdayRules' => $this->holidays->listWeekdayRules(),
            'weekdayLabels' => CalendarService::WEEKDAY_LABELS,
            'prevHolidayYear' => $year - 1,
            'nextHolidayYear' => $year + 1,
            'settingsPath' => fn (?string $sec = null, ?int $y = null) => $this->settingsPath($sec ?? $section, $y ?? $year),
            'lineConfigured' => false,
            'pushConfigured' => false,
            'translationKeys' => $section === 'ai'
                ? TranslationApiKey::orderBy('priority', 'desc')->orderBy('id')->get()
                : collect(),
            'aiChatKeys' => $section === 'ai'
                ? AiApiKey::orderBy('priority', 'desc')->orderBy('id')->get()
                : collect(),
            'aiProviders' => config('ai_chat.providers', []),
            'aiPlans' => config('ai_chat.plans', []),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function importHolidays(Request $request)
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $country = $request->input('country') === 'ph' ? 'ph' : 'jp';
        $added = $this->holidays->importNationalHolidays($year, $country);
        $label = $country === 'ph' ? 'フィリピンの祝日' : '日本の祝日';

        return $this->redirectWithMessage(
            $this->settingsPath('holidays', $year),
            $added > 0 ? "{$year}年の{$label}を {$added} 件登録しました" : "{$year}年の{$label}は登録済みです"
        );
    }

    public function addHoliday(Request $request)
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $name = (string) $request->input('name');
        if ($request->input('dateMode') === 'range') {
            $added = $this->holidays->addCustomHolidayRange(
                (string) $request->input('startDate'),
                (string) $request->input('endDate'),
                $name
            );
            if (! $added) {
                return $this->redirectWithMessage($this->settingsPath('holidays', $year), '期間と名称を正しく入力してください', 'error');
            }

            return $this->redirectWithMessage($this->settingsPath('holidays', $year), "休日を {$added} 件追加しました");
        }

        $entry = $this->holidays->addCustomHoliday((string) $request->input('date'), $name);
        if (! $entry) {
            return $this->redirectWithMessage($this->settingsPath('holidays', $year), '日付と名称を入力してください', 'error');
        }

        return $this->redirectWithMessage($this->settingsPath('holidays', $year), '休日を追加しました');
    }

    public function deleteHoliday(Request $request, int $id)
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $this->holidays->removeHoliday($id);

        return $this->redirectWithMessage($this->settingsPath('holidays', $year), '休日を削除しました');
    }

    public function addWeekdayRule(Request $request)
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $rule = $this->holidays->addWeekdayRule([
            'name' => $request->input('name'),
            'startDate' => $request->input('startDate'),
            'endDate' => $request->input('endDate'),
            'weekdays' => $request->input('weekdays', []),
            'exceptions' => $request->input('exceptions', []),
        ]);
        if (! $rule) {
            return $this->redirectWithMessage($this->settingsPath('holidays', $year), '期間・曜日を正しく入力してください', 'error');
        }

        return $this->redirectWithMessage($this->settingsPath('holidays', $year), '曜日休日ルールを追加しました');
    }

    public function deleteWeekdayRule(Request $request, int $id)
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $this->holidays->removeWeekdayRule($id);

        return $this->redirectWithMessage($this->settingsPath('holidays', $year), '曜日休日ルールを削除しました');
    }

    public function addWeekdayException(Request $request, int $id)
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        if (! $this->holidays->addWeekdayException($id, (string) $request->input('date'))) {
            return $this->redirectWithMessage($this->settingsPath('holidays', $year), '除外日を正しく入力してください（ルール期間内）', 'error');
        }

        return $this->redirectWithMessage($this->settingsPath('holidays', $year), '除外日を追加しました');
    }

    public function deleteWeekdayException(Request $request, int $id)
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $this->holidays->removeWeekdayException($id, (string) $request->input('date'));

        return $this->redirectWithMessage($this->settingsPath('holidays', $year), '除外日を削除しました');
    }

    private function parseSection(?string $value): string
    {
        if ($value === 'translation') {
            return 'ai';
        }

        return in_array($value, ['integration', 'notifications', 'ai', 'holidays'], true) ? $value : 'holidays';
    }

    private function settingsPath(string $section, ?int $year = null): string
    {
        $params = ['section' => $section];
        if ($section === 'ai') {
            $params['tab'] = 'translation';
        }
        if ($year) {
            $params['year'] = $year;
        }

        return '/settings?'.http_build_query($params);
    }
}
