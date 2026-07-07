<?php

namespace App\Http\Controllers;

use App\Services\FinanceService;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private FinanceService $finance) {}

    public function index(Request $request)
    {
        $filters = $this->finance->parseFilters($request->query());
        $pageData = $this->finance->buildPageData($filters);
        $returnTo = $this->finance->buildFinanceQuery($filters);

        return view('finance.index', [
            'filters' => $filters,
            'returnTo' => $returnTo,
            'periodValue' => $pageData['periodValue'],
            'monthLabel' => $pageData['monthLabel'],
            'accounts' => $pageData['accounts'],
            'groupedAccounts' => $pageData['groupedAccounts'],
            'summary' => $pageData['summary'],
            'transactions' => $pageData['transactions'],
            'jpAccounts' => array_values(array_filter($pageData['accounts'], fn ($a) => $a['region'] === 'jp')),
            'phAccounts' => array_values(array_filter($pageData['accounts'], fn ($a) => $a['region'] === 'ph')),
            'bankAccounts' => array_values(array_filter(
                $pageData['accounts'],
                fn ($a) => in_array($a['kind'], ['bank', 'wallet', 'cash'], true)
            )),
            'defaultDate' => $this->finance->todayIso(),
            'buildFinanceQuery' => fn (array $f, array $extra = []) => $this->finance->buildFinanceQuery($f, $extra),
            'formatMoney' => fn (float $amount, string $currency) => $this->finance->formatMoney($amount, $currency),
            ...$this->flashFromQuery($request),
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

        return $this->redirectWithMessage($returnTo, '取引を登録しました');
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

        return $this->redirectWithMessage($returnTo, '取引を削除しました');
    }

    public function updateAccountBalance(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/finance');
        $balance = (float) $request->input('initialBalance', 0);
        if (! $this->finance->updateAccountInitialBalance($id, $balance)) {
            return $this->redirectWithMessage($returnTo, '口座が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, '開始残高を更新しました');
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
}
