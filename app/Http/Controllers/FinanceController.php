<?php

namespace App\Http\Controllers;

use App\Services\FinanceCsvService;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private FinanceService $finance,
        private FinanceCsvService $financeCsv,
    ) {}

    public function index(Request $request)
    {
        $filters = $this->finance->parseFilters($request->query());
        $pageData = $this->finance->buildPageData($filters);
        $filters = $pageData['filters'];
        $returnTo = $this->finance->buildFinanceQuery($filters);

        return view('finance.index', [
            'filters' => $filters,
            'returnTo' => $returnTo,
            'periodValue' => $pageData['periodValue'],
            'monthLabel' => $pageData['monthLabel'],
            'tabContextLabel' => $pageData['tabContextLabel'],
            'accounts' => $pageData['accounts'],
            'groupedAccounts' => $pageData['groupedAccounts'],
            'accountDisplayGroups' => $pageData['accountDisplayGroups'],
            'balanceTotals' => $pageData['balanceTotals'],
            'summary' => $pageData['summary'],
            'transactions' => $pageData['transactions'],
            'transactionBalanceContext' => $pageData['transactionBalanceContext'],
            'allAccounts' => $pageData['allAccounts'],
            'overviewAccounts' => $pageData['overviewAccounts'],
            'overviewAccountsByRegion' => $pageData['overviewAccountsByRegion'],
            'unpinnedAccounts' => $pageData['unpinnedAccounts'],
            'jpAccounts' => array_values(array_filter($pageData['accounts'], fn ($a) => $a['region'] === 'jp')),
            'phAccounts' => array_values(array_filter($pageData['accounts'], fn ($a) => $a['region'] === 'ph')),
            'bankAccounts' => array_values(array_filter(
                $pageData['accounts'],
                fn ($a) => in_array($a['kind'], ['bank', 'wallet', 'cash'], true)
            )),
            'defaultDate' => $this->finance->todayIso(),
            'buildFinanceQuery' => fn (array $f, array $extra = []) => $this->finance->buildFinanceQuery($f, $extra),
            'buildFinanceExportQuery' => fn (array $f, string $format) => $this->finance->buildFinanceExportQuery($f, $format),
            'buildFinanceReportQuery' => fn (array $f) => $this->finance->buildFinanceReportQuery($f),
            'formatMoney' => fn (float $amount, string $currency) => $this->finance->formatMoney($amount, $currency),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function report(Request $request)
    {
        $filters = $this->finance->parseFilters($request->query());
        unset($filters['accountId']);
        $reportData = $this->finance->buildReportData($filters);

        return view('finance.report', [
            'filters' => $filters,
            'periodValue' => $reportData['periodValue'],
            'monthLabel' => $reportData['monthLabel'],
            'summary' => $reportData['summary'],
            'groupedTransactions' => $reportData['groupedTransactions'],
            'transactions' => $reportData['transactions'],
            'accountBreakdown' => $reportData['accountBreakdown'],
            'schedules' => $reportData['schedules'],
            'accounts' => $reportData['accounts'],
            'balanceTotals' => $reportData['balanceTotals'],
            'buildFinanceReportQuery' => fn (array $f) => $this->finance->buildFinanceReportQuery($f),
            'buildFinanceQuery' => fn (array $f, array $extra = []) => $this->finance->buildFinanceQuery($f, $extra),
            'buildFinanceExportQuery' => fn (array $f, string $format) => $this->finance->buildFinanceExportQuery($f, $format),
            'formatMoney' => fn (float $amount, string $currency) => $this->finance->formatMoney($amount, $currency),
        ]);
    }

    public function store(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');

        try {
            $this->finance->createTransaction([
                'type' => $request->input('type'),
                'transactionDate' => $request->input('transactionDate'),
                'accountId' => $request->input('accountId'),
                'toAccountId' => $request->input('toAccountId'),
                'amount' => $request->input('amount'),
                'toAmount' => $request->input('toAmount'),
                'memo' => $request->input('memo'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        } catch (\Throwable) {
            return $this->redirectWithMessage($returnTo, '取引の登録に失敗しました', 'error');
        }

        $transactionDate = (string) $request->input('transactionDate');
        $message = $transactionDate > $this->finance->todayIso()
            ? '予定を登録しました（反映日まで残高には含まれません）'
            : '取引を登録しました';

        return $this->redirectWithMessage($returnTo, $message);
    }

    public function update(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');

        try {
            $updated = $this->finance->updateTransaction($id, [
                'type' => $request->input('type'),
                'transactionDate' => $request->input('transactionDate'),
                'accountId' => $request->input('accountId'),
                'toAccountId' => $request->input('toAccountId'),
                'amount' => $request->input('amount'),
                'toAmount' => $request->input('toAmount'),
                'memo' => $request->input('memo'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, '取引が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, '取引を更新しました');
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');
        if (! $this->finance->deleteTransaction($id)) {
            return $this->redirectWithMessage($returnTo, '取引が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, '取引を削除しました（カード引落の場合は支払予定も削除しました）');
    }

    public function updateAccountBalance(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');
        $balance = (float) $request->input('initialBalance', 0);
        $adjustmentAmount = $request->has('adjustmentAmount')
            ? (float) $request->input('adjustmentAmount', 0)
            : null;
        if (! $this->finance->updateAccountInitialBalance($id, $balance, $adjustmentAmount)) {
            return $this->redirectWithMessage($returnTo, '口座が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, '口座残高設定を更新しました');
    }

    public function updateLinkedBank(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');
        $linkedBankId = $request->input('linkedBankId');
        $linkedBankId = $linkedBankId !== null && $linkedBankId !== '' ? (int) $linkedBankId : null;

        if (! $this->finance->updateLinkedBank($id, $linkedBankId)) {
            return $this->redirectWithMessage($returnTo, '引落口座の更新に失敗しました', 'error');
        }

        return $this->redirectWithMessage($returnTo, '引落口座を更新しました');
    }

    public function storeAccount(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');

        try {
            $this->finance->createAccount([
                'name' => $request->input('name'),
                'region' => $request->input('region'),
                'kind' => $request->input('kind'),
                'initialBalance' => $request->input('initialBalance'),
                'adjustmentAmount' => $request->input('adjustmentAmount'),
                'linkedBankId' => $request->input('linkedBankId'),
                'showInOverview' => $request->boolean('show_in_overview'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        } catch (\Throwable) {
            return $this->redirectWithMessage($returnTo, '口座の登録に失敗しました', 'error');
        }

        return $this->redirectWithMessage($returnTo, '口座を登録しました');
    }

    public function updateAccountOverview(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');
        $show = $request->boolean('show');

        if (! $this->finance->setAccountOverviewVisibility($id, $show)) {
            return $this->redirectWithMessage($returnTo, '口座が見つかりません', 'error');
        }

        return $this->redirectWithMessage(
            $returnTo,
            $show ? '総残高エリアにカードを追加しました' : '総残高エリアからカードを外しました'
        );
    }

    public function updateAccount(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');

        try {
            $updated = $this->finance->updateAccount($id, [
                'name' => $request->input('name'),
                'kind' => $request->input('kind'),
                'initialBalance' => $request->input('initialBalance'),
                'adjustmentAmount' => $request->input('adjustmentAmount'),
                'linkedBankId' => $request->input('linkedBankId'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, '口座が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, '口座を更新しました');
    }

    public function destroyAccount(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');
        if (! $this->finance->deleteAccount($id)) {
            return $this->redirectWithMessage($returnTo, '口座が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, '口座を削除しました');
    }

    public function storeAccountSchedule(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');

        try {
            $this->finance->createSchedule($id, [
                'scheduledDate' => $request->input('scheduledDate'),
                'amount' => $request->input('amount'),
                'memo' => $request->input('memo'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, '予定を登録しました');
    }

    public function upsertAccountSchedule(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');

        try {
            $this->finance->upsertNextSchedule($id, [
                'scheduledDate' => $request->input('scheduledDate'),
                'amount' => $request->input('amount'),
                'memo' => $request->input('memo'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, '予定を保存しました');
    }

    public function destroyAccountSchedule(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');
        if (! $this->finance->deleteSchedule($id)) {
            return $this->redirectWithMessage($returnTo, '予定が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, '予定を削除しました（関連する引落取引も削除しました）');
    }

    public function updateAccountSchedule(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');

        try {
            if (! $this->finance->updateSchedule($id, [
                'scheduledDate' => $request->input('scheduledDate'),
                'amount' => $request->input('amount'),
                'memo' => $request->input('memo'),
            ])) {
                return $this->redirectWithMessage($returnTo, '予定が見つかりません', 'error');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, '予定を更新しました');
    }

    public function reorderAccounts(Request $request)
    {
        $accountIds = $request->input('accountIds', []);
        if (! is_array($accountIds)) {
            return response()->json(['ok' => false, 'message' => '不正なリクエストです'], 422);
        }

        try {
            $this->finance->reorderAccounts($accountIds);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $this->finance->parseFilters($request->query());
        $format = $request->query('format', FinanceCsvService::FORMAT_TRANSACTIONS);
        if (! in_array($format, [FinanceCsvService::FORMAT_TRANSACTIONS, FinanceCsvService::FORMAT_BUDGET_MONITOR], true)) {
            abort(400, '不正なエクスポート形式です');
        }

        $csv = $this->financeCsv->export($filters, $format);
        $filename = $format === FinanceCsvService::FORMAT_BUDGET_MONITOR
            ? sprintf('budget-monitor_%04d-%02d.csv', $filters['year'], $filters['month'])
            : sprintf('finance-transactions_%04d-%02d.csv', $filters['year'], $filters['month']);

        return response()->streamDownload(
            static function () use ($csv) {
                echo $csv;
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    public function importCsv(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
        ]);

        $content = (string) file_get_contents($request->file('csv_file')->getRealPath());
        if ($content === '') {
            return $this->redirectWithMessage($returnTo, 'CSVファイルが空です', 'error');
        }

        try {
            $result = $this->financeCsv->import($content, [
                'replace' => $request->boolean('replace'),
                'includeCardDeltas' => $request->boolean('include_card_deltas', true),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        } catch (\Throwable) {
            return $this->redirectWithMessage($returnTo, 'CSVのインポートに失敗しました', 'error');
        }

        $message = sprintf(
            'CSVをインポートしました（%d件追加、%d件スキップ%s）。',
            $result['created'],
            $result['skipped'],
            $result['deleted'] > 0 ? '、既存'.$result['deleted'].'件を削除' : ''
        );

        return $this->redirectWithMessage($returnTo, $message);
    }
}
