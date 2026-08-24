<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait ManagesHolidays
{
    protected function holidayOwnerUserId(Request $request): ?int
    {
        return null;
    }

    abstract protected function holidayReturnPath(Request $request, int $year): string;

    public function importHolidays(Request $request): RedirectResponse
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $country = $request->input('country') === 'ph' ? 'ph' : 'jp';
        $added = $this->holidays->importNationalHolidays($year, $country, $this->holidayOwnerUserId($request));
        $label = $country === 'ph' ? 'フィリピンの祝日' : '日本の祝日';

        return $this->redirectWithMessage(
            $this->holidayReturnPath($request, $year),
            $added > 0 ? "{$year}年の{$label}を {$added} 件登録しました" : "{$year}年の{$label}は登録済みです"
        );
    }

    public function addHoliday(Request $request): RedirectResponse
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $ownerId = $this->holidayOwnerUserId($request);
        $name = (string) $request->input('name');
        if ($request->input('dateMode') === 'range') {
            $added = $this->holidays->addCustomHolidayRange(
                (string) $request->input('startDate'),
                (string) $request->input('endDate'),
                $name,
                $ownerId
            );
            if (! $added) {
                return $this->redirectWithMessage($this->holidayReturnPath($request, $year), '期間と名称を正しく入力してください', 'error');
            }

            return $this->redirectWithMessage($this->holidayReturnPath($request, $year), "休日を {$added} 件追加しました");
        }

        $entry = $this->holidays->addCustomHoliday((string) $request->input('date'), $name, $ownerId);
        if (! $entry) {
            return $this->redirectWithMessage($this->holidayReturnPath($request, $year), '日付と名称を入力してください', 'error');
        }

        return $this->redirectWithMessage($this->holidayReturnPath($request, $year), '休日を追加しました');
    }

    public function deleteHoliday(Request $request, int $id): RedirectResponse
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $this->holidays->removeHoliday($id, $this->holidayOwnerUserId($request));

        return $this->redirectWithMessage($this->holidayReturnPath($request, $year), '休日を削除しました');
    }

    public function addWeekdayRule(Request $request): RedirectResponse
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $rule = $this->holidays->addWeekdayRule([
            'name' => $request->input('name'),
            'startDate' => $request->input('startDate'),
            'endDate' => $request->input('endDate'),
            'weekdays' => $request->input('weekdays', []),
            'exceptions' => $request->input('exceptions', []),
        ], $this->holidayOwnerUserId($request));
        if (! $rule) {
            return $this->redirectWithMessage($this->holidayReturnPath($request, $year), '期間・曜日を正しく入力してください', 'error');
        }

        return $this->redirectWithMessage($this->holidayReturnPath($request, $year), '曜日休日ルールを追加しました');
    }

    public function deleteWeekdayRule(Request $request, int $id): RedirectResponse
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $this->holidays->removeWeekdayRule($id, $this->holidayOwnerUserId($request));

        return $this->redirectWithMessage($this->holidayReturnPath($request, $year), '曜日休日ルールを削除しました');
    }

    public function addWeekdayException(Request $request, int $id): RedirectResponse
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        if (! $this->holidays->addWeekdayException($id, (string) $request->input('date'), $this->holidayOwnerUserId($request))) {
            return $this->redirectWithMessage($this->holidayReturnPath($request, $year), '除外日を正しく入力してください（ルール期間内）', 'error');
        }

        return $this->redirectWithMessage($this->holidayReturnPath($request, $year), '除外日を追加しました');
    }

    public function deleteWeekdayException(Request $request, int $id): RedirectResponse
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $this->holidays->removeWeekdayException($id, (string) $request->input('date'), $this->holidayOwnerUserId($request));

        return $this->redirectWithMessage($this->holidayReturnPath($request, $year), '除外日を削除しました');
    }
}
