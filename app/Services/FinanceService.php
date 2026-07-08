<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FinanceAccountSchedule;
use App\Models\FinanceTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FinanceService
{
    public const REGION_LABELS = [
        'jp' => '日本',
        'ph' => 'フィリピン',
    ];

    public const TAB_LABELS = [
        'jp' => '日本',
        'ph' => 'フィリピン',
        'transfer' => '送金',
        'all' => '全体',
    ];

    public const KIND_LABELS = [
        'bank' => '銀行',
        'cash' => '現金',
        'credit_card' => 'クレカ',
        'wallet' => 'ウォレット',
    ];

    public const TYPE_LABELS = [
        'income' => '収入',
        'expense' => '支出',
        'transfer' => '振替',
    ];

    public const SCHEDULE_TYPE_LABELS = [
        'payment' => '支払予定',
        'deposit' => '入金予定',
    ];

    /** @var list<array{slug: string, region: string, kind: string, name: string, currency: string, sort_order: int, linked_slug?: string}> */
    public const DEFAULT_ACCOUNTS = [
        ['slug' => 'jp_bank_rakuten', 'region' => 'jp', 'kind' => 'bank', 'name' => '楽天銀行', 'currency' => 'JPY', 'sort_order' => 10],
        ['slug' => 'jp_bank_seven', 'region' => 'jp', 'kind' => 'bank', 'name' => 'セブン銀行', 'currency' => 'JPY', 'sort_order' => 20],
        ['slug' => 'jp_bank_paypay', 'region' => 'jp', 'kind' => 'bank', 'name' => 'PAYPAY銀行', 'currency' => 'JPY', 'sort_order' => 30],
        ['slug' => 'jp_bank_smbc', 'region' => 'jp', 'kind' => 'bank', 'name' => '三井住友銀行', 'currency' => 'JPY', 'sort_order' => 40],
        ['slug' => 'jp_cash_petty', 'region' => 'jp', 'kind' => 'cash', 'name' => '手持ち現金（Petty cash）', 'currency' => 'JPY', 'sort_order' => 50],
        ['slug' => 'jp_card_rakuten', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'Rakuten', 'currency' => 'JPY', 'sort_order' => 110, 'linked_slug' => 'jp_bank_rakuten'],
        ['slug' => 'jp_card_amazon', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'Amazon', 'currency' => 'JPY', 'sort_order' => 120],
        ['slug' => 'jp_card_paypay', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'Paypay', 'currency' => 'JPY', 'sort_order' => 130, 'linked_slug' => 'jp_bank_paypay'],
        ['slug' => 'jp_card_jal', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'JAL', 'currency' => 'JPY', 'sort_order' => 140],
        ['slug' => 'jp_card_seven', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'Seven', 'currency' => 'JPY', 'sort_order' => 150, 'linked_slug' => 'jp_bank_seven'],
        ['slug' => 'jp_card_fami', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'Fami', 'currency' => 'JPY', 'sort_order' => 160],
        ['slug' => 'jp_card_epos', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'EPOS', 'currency' => 'JPY', 'sort_order' => 170],
        ['slug' => 'jp_card_smbc_cl', 'region' => 'jp', 'kind' => 'credit_card', 'name' => '三井住友 (CL)', 'currency' => 'JPY', 'sort_order' => 180, 'linked_slug' => 'jp_bank_smbc'],
        ['slug' => 'ph_bank_bpi', 'region' => 'ph', 'kind' => 'bank', 'name' => 'BPI', 'currency' => 'PHP', 'sort_order' => 210],
        ['slug' => 'ph_wallet_maya', 'region' => 'ph', 'kind' => 'wallet', 'name' => 'Maya', 'currency' => 'PHP', 'sort_order' => 220],
        ['slug' => 'ph_wallet_wize', 'region' => 'ph', 'kind' => 'wallet', 'name' => 'Wize', 'currency' => 'PHP', 'sort_order' => 230],
        ['slug' => 'ph_cash_petty', 'region' => 'ph', 'kind' => 'cash', 'name' => '手持ち現金（Petty cash）', 'currency' => 'PHP', 'sort_order' => 240],
        ['slug' => 'ph_card_bpi_mc', 'region' => 'ph', 'kind' => 'credit_card', 'name' => 'BPI Mastercard', 'currency' => 'PHP', 'sort_order' => 310, 'linked_slug' => 'ph_bank_bpi'],
        ['slug' => 'ph_card_bankard_mc_gold', 'region' => 'ph', 'kind' => 'credit_card', 'name' => 'Bankard Mastercard Gold', 'currency' => 'PHP', 'sort_order' => 320],
        ['slug' => 'ph_card_bankard_airmiles', 'region' => 'ph', 'kind' => 'credit_card', 'name' => 'Bankard Airmiles Visa', 'currency' => 'PHP', 'sort_order' => 330],
    ];

    public function ensureDefaultAccounts(): void
    {
        if (FinanceAccount::query()->exists()) {
            return;
        }

        $idBySlug = [];
        foreach (self::DEFAULT_ACCOUNTS as $row) {
            $account = FinanceAccount::query()->create([
                'slug' => $row['slug'],
                'region' => $row['region'],
                'kind' => $row['kind'],
                'name' => $row['name'],
                'currency' => $row['currency'],
                'sort_order' => $row['sort_order'],
                'initial_balance' => 0,
                'is_active' => true,
            ]);
            $idBySlug[$row['slug']] = $account->id;
        }

        foreach (self::DEFAULT_ACCOUNTS as $row) {
            if (empty($row['linked_slug'])) {
                continue;
            }
            $account = FinanceAccount::query()->where('slug', $row['slug'])->first();
            if ($account && isset($idBySlug[$row['linked_slug']])) {
                $account->linked_bank_id = $idBySlug[$row['linked_slug']];
                $account->save();
            }
        }
    }

    /** @return array{tab: string, year: int, month: int, accountId: ?int} */
    public function parseFilters(array $query): array
    {
        $tab = $this->normalizeTab($query['tab'] ?? 'jp');
        $period = is_string($query['period'] ?? null) ? $query['period'] : date('Y-m');
        if (! preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
            $period = date('Y-m');
            preg_match('/^(\d{4})-(\d{2})$/', $period, $matches);
        }

        $accountId = isset($query['account']) ? (int) $query['account'] : null;
        if ($accountId !== null && $accountId <= 0) {
            $accountId = null;
        }

        return [
            'tab' => $tab,
            'year' => (int) $matches[1],
            'month' => (int) $matches[2],
            'accountId' => $accountId,
        ];
    }

    public function normalizeTab(?string $tab): string
    {
        return in_array($tab, ['jp', 'ph', 'transfer', 'all'], true) ? $tab : 'jp';
    }

    public function normalizeType(?string $type): string
    {
        return in_array($type, ['income', 'expense', 'transfer'], true) ? $type : 'expense';
    }

    public function normalizeKind(?string $kind): string
    {
        return in_array($kind, ['bank', 'cash', 'credit_card', 'wallet'], true) ? $kind : 'bank';
    }

    public function normalizeRegion(?string $region): string
    {
        return in_array($region, ['jp', 'ph'], true) ? $region : 'jp';
    }

    public function currencyForRegion(string $region): string
    {
        return $region === 'ph' ? 'PHP' : 'JPY';
    }

    /** @param array{tab?: string, year?: int, month?: int, accountId?: ?int} $filters */
    public function buildFinanceQuery(array $filters, array $extra = []): string
    {
        $params = array_filter([
            'tab' => $filters['tab'] ?? null,
            'period' => isset($filters['year'], $filters['month'])
                ? sprintf('%04d-%02d', $filters['year'], $filters['month'])
                : null,
            'account' => ! empty($filters['accountId']) ? $filters['accountId'] : null,
        ], fn ($value) => $value !== null && $value !== '');

        foreach ($extra as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }

        if ($params === []) {
            return '/finance';
        }

        return '/finance?'.http_build_query($params);
    }

    /** @param array{tab?: string, year?: int, month?: int} $filters */
    public function buildFinanceReportQuery(array $filters): string
    {
        $params = array_filter([
            'tab' => $filters['tab'] ?? null,
            'period' => isset($filters['year'], $filters['month'])
                ? sprintf('%04d-%02d', $filters['year'], $filters['month'])
                : null,
        ], fn ($value) => $value !== null && $value !== '');

        if ($params === []) {
            return '/finance/report';
        }

        return '/finance/report?'.http_build_query($params);
    }

    /** @param array{tab: string, year: int, month: int} $filters */
    /** @return array<string, mixed> */
    public function buildReportData(array $filters): array
    {
        $this->ensureDefaultAccounts();

        $monthStart = sprintf('%04d-%02d-01', $filters['year'], $filters['month']);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $monthTransactions = FinanceTransaction::query()
            ->with(['account', 'toAccount'])
            ->whereDate('transaction_date', '>=', $monthStart)
            ->whereDate('transaction_date', '<=', $monthEnd)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $displayTransactions = $this->filterTransactionsForTab($monthTransactions, $filters['tab'], null);
        $summary = $this->buildMonthSummary($displayTransactions, $filters['tab']);

        $transactionRows = $displayTransactions
            ->map(fn (FinanceTransaction $t) => $this->transactionToArray($t))
            ->values()
            ->all();

        $groupedTransactions = [
            'income' => array_values(array_filter($transactionRows, fn (array $t) => $t['type'] === 'income')),
            'expense' => array_values(array_filter($transactionRows, fn (array $t) => $t['type'] === 'expense')),
            'transfer' => array_values(array_filter($transactionRows, fn (array $t) => $t['type'] === 'transfer')),
        ];

        $allTransactions = FinanceTransaction::query()->get();
        $accountsWithBalance = collect($this->listAccounts())
            ->map(function (array $accountRow) use ($allTransactions) {
                $model = FinanceAccount::query()->find($accountRow['id']);
                if ($model) {
                    $accountRow['balance'] = $this->calculateAccountBalance($model, $allTransactions);
                }

                return $accountRow;
            })
            ->filter(function (array $account) use ($filters) {
                if ($filters['tab'] === 'transfer' || $filters['tab'] === 'all') {
                    return true;
                }

                return $account['region'] === $filters['tab'];
            })
            ->values()
            ->all();

        $schedules = FinanceAccountSchedule::query()
            ->with('account')
            ->whereDate('scheduled_date', '>=', $monthStart)
            ->whereDate('scheduled_date', '<=', $monthEnd)
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get()
            ->filter(function (FinanceAccountSchedule $schedule) use ($filters) {
                $account = $schedule->account;
                if (! $account || ! $account->is_active) {
                    return false;
                }
                if ($filters['tab'] === 'all' || $filters['tab'] === 'transfer') {
                    return true;
                }

                return $account->region === $filters['tab'];
            })
            ->map(fn (FinanceAccountSchedule $schedule) => $this->scheduleToArray($schedule))
            ->values()
            ->all();

        return [
            'periodValue' => sprintf('%04d-%02d', $filters['year'], $filters['month']),
            'monthLabel' => sprintf('%d年%d月', $filters['year'], $filters['month']),
            'year' => $filters['year'],
            'month' => $filters['month'],
            'summary' => $summary,
            'groupedTransactions' => $groupedTransactions,
            'transactions' => $transactionRows,
            'accountBreakdown' => $this->buildAccountBreakdown($displayTransactions),
            'schedules' => $schedules,
            'accounts' => $accountsWithBalance,
            'balanceTotals' => $this->buildBalanceTotals($accountsWithBalance),
        ];
    }

    /** @param Collection<int, FinanceTransaction> $transactions */
    /** @return list<array{accountName: string, currency: string, income: float, expense: float, net: float}> */
    private function buildAccountBreakdown(Collection $transactions): array
    {
        $rows = [];

        foreach ($transactions as $transaction) {
            if ($transaction->type === 'transfer') {
                continue;
            }

            $name = $transaction->account?->name ?? '不明';
            $currency = $transaction->account?->currency ?? 'JPY';
            $key = $name.'|'.$currency;

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'accountName' => $name,
                    'currency' => $currency,
                    'income' => 0.0,
                    'expense' => 0.0,
                ];
            }

            if ($transaction->type === 'income') {
                $rows[$key]['income'] += (float) $transaction->amount;
            } elseif ($transaction->type === 'expense') {
                $rows[$key]['expense'] += (float) $transaction->amount;
            }
        }

        return array_values(array_map(function (array $row) {
            $row['income'] = round($row['income'], 2);
            $row['expense'] = round($row['expense'], 2);
            $row['net'] = round($row['income'] - $row['expense'], 2);

            return $row;
        }, $rows));
    }

    /** @return list<array<string, mixed>> */
    public function listAccounts(?string $region = null): array
    {
        $query = FinanceAccount::query()->where('is_active', true)->orderBy('sort_order');
        if ($region !== null && in_array($region, ['jp', 'ph'], true)) {
            $query->where('region', $region);
        }

        return $query->get()->map(fn (FinanceAccount $account) => $this->accountToArray($account))->all();
    }

    /** @return array<string, mixed> */
    public function accountToArray(FinanceAccount $account, ?Collection $transactions = null): array
    {
        $balance = $this->calculateAccountBalance($account, $transactions);

        return [
            'id' => $account->id,
            'slug' => $account->slug,
            'region' => $account->region,
            'regionLabel' => self::REGION_LABELS[$account->region] ?? $account->region,
            'kind' => $account->kind,
            'kindLabel' => self::KIND_LABELS[$account->kind] ?? $account->kind,
            'name' => $account->name,
            'currency' => $account->currency,
            'sortOrder' => $account->sort_order,
            'linkedBankId' => $account->linked_bank_id,
            'initialBalance' => (float) $account->initial_balance,
            'adjustmentAmount' => (float) ($account->adjustment_amount ?? 0),
            'balance' => $balance,
        ];
    }

    public function calculateAccountBalance(FinanceAccount $account, ?Collection $transactions = null): float
    {
        $balance = (float) $account->initial_balance + (float) ($account->adjustment_amount ?? 0);
        $transactions ??= FinanceTransaction::query()->get();

        foreach ($transactions as $transaction) {
            if ((int) $transaction->account_id === (int) $account->id) {
                if ($transaction->type === 'income') {
                    $balance += (float) $transaction->amount;
                } elseif ($transaction->type === 'expense') {
                    $balance -= (float) $transaction->amount;
                } elseif ($transaction->type === 'transfer') {
                    $balance -= (float) $transaction->amount;
                }
            }

            if ($transaction->type === 'transfer' && (int) $transaction->to_account_id === (int) $account->id) {
                $balance += (float) ($transaction->to_amount ?? $transaction->amount);
            }
        }

        return round($balance, 2);
    }

    /** @return array{accounts: list<array<string, mixed>>, summary: array<string, float>, transactions: list<array<string, mixed>>} */
    public function buildPageData(array $filters): array
    {
        $this->ensureDefaultAccounts();

        $accounts = collect($this->listAccounts());
        $allTransactions = FinanceTransaction::query()
            ->with(['account', 'toAccount'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $accountsWithBalance = $accounts
            ->map(function (array $accountRow) use ($allTransactions) {
                $model = FinanceAccount::query()->find($accountRow['id']);
                if (! $model) {
                    return $accountRow;
                }
                $accountRow['balance'] = $this->calculateAccountBalance($model, $allTransactions);

                return $accountRow;
            });

        $schedulesByAccount = FinanceAccountSchedule::query()
            ->with('account')
            ->whereIn('account_id', $accountsWithBalance->pluck('id'))
            ->where('scheduled_date', '>=', $this->todayIso())
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get()
            ->groupBy('account_id');

        $accountsWithBalance = $accountsWithBalance
            ->map(fn (array $accountRow) => $this->attachSchedulesToAccountRow($accountRow, $schedulesByAccount->get($accountRow['id'])));

        $monthStart = sprintf('%04d-%02d-01', $filters['year'], $filters['month']);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $monthTransactions = $allTransactions->filter(
            fn (FinanceTransaction $t) => $t->transaction_date->format('Y-m-d') >= $monthStart
                && $t->transaction_date->format('Y-m-d') <= $monthEnd
        );

        $displayTransactions = $this->filterTransactionsForTab($monthTransactions, $filters['tab'], $filters['accountId']);
        $summary = $this->buildMonthSummary($monthTransactions, $filters['tab']);

        $groupedAccounts = $this->groupAccountsByKind(
            $accountsWithBalance->filter(function (array $account) use ($filters) {
                if ($filters['tab'] === 'transfer' || $filters['tab'] === 'all') {
                    return true;
                }

                return $account['region'] === $filters['tab'];
            })->values()->all()
        );

        $visibleAccounts = $accountsWithBalance->filter(function (array $account) use ($filters) {
            if ($filters['tab'] === 'transfer' || $filters['tab'] === 'all') {
                return true;
            }

            return $account['region'] === $filters['tab'];
        })->values()->all();

        return [
            'accounts' => $accountsWithBalance->values()->all(),
            'groupedAccounts' => $groupedAccounts,
            'balanceTotals' => $this->buildBalanceTotals($visibleAccounts),
            'summary' => $summary,
            'transactions' => $displayTransactions->map(fn (FinanceTransaction $t) => $this->transactionToArray($t))->values()->all(),
            'periodValue' => sprintf('%04d-%02d', $filters['year'], $filters['month']),
            'monthLabel' => sprintf('%d年%d月', $filters['year'], $filters['month']),
        ];
    }

    /** @param list<array<string, mixed>> $accounts */
    /** @return array<string, list<array<string, mixed>>> */
    public function groupAccountsByKind(array $accounts): array
    {
        $groups = [];
        foreach (['bank', 'cash', 'wallet', 'credit_card'] as $kind) {
            $items = array_values(array_filter($accounts, fn (array $a) => $a['kind'] === $kind));
            if ($items !== []) {
                $groups[$kind] = $items;
            }
        }

        return $groups;
    }

    /** @param Collection<int, FinanceTransaction> $transactions */
    public function filterTransactionsForTab(Collection $transactions, string $tab, ?int $accountId): Collection
    {
        $filtered = $transactions->filter(function (FinanceTransaction $transaction) use ($tab, $accountId) {
            if ($accountId !== null) {
                return (int) $transaction->account_id === $accountId
                    || (int) $transaction->to_account_id === $accountId;
            }

            if ($tab === 'transfer') {
                return $transaction->type === 'transfer';
            }

            if ($tab === 'all') {
                return true;
            }

            $accountRegion = $transaction->account?->region;
            $toRegion = $transaction->toAccount?->region;

            if ($transaction->type === 'transfer') {
                return $accountRegion === $tab || $toRegion === $tab;
            }

            return $accountRegion === $tab;
        });

        return $filtered->sortByDesc(fn (FinanceTransaction $t) => $t->transaction_date->format('Y-m-d').sprintf('%08d', $t->id));
    }

    /** @param Collection<int, FinanceTransaction> $transactions */
    /** @return array{income: float, expense: float, transferOut: float, transferIn: float, net: float, currency: string} */
    public function buildMonthSummary(Collection $transactions, string $tab): array
    {
        $currency = $tab === 'ph' ? 'PHP' : 'JPY';
        if ($tab === 'all' || $tab === 'transfer') {
            $currency = 'JPY';
        }

        $income = 0.0;
        $expense = 0.0;
        $transferOut = 0.0;
        $transferIn = 0.0;

        foreach ($transactions as $transaction) {
            if ($tab === 'transfer' && $transaction->type !== 'transfer') {
                continue;
            }

            if ($tab !== 'all' && $tab !== 'transfer') {
                if ($transaction->type === 'transfer') {
                    if ($transaction->account?->region !== $tab && $transaction->toAccount?->region !== $tab) {
                        continue;
                    }
                } elseif ($transaction->account?->region !== $tab) {
                    continue;
                }
            }

            if ($transaction->type === 'income') {
                if ($tab === 'all' || $transaction->account?->currency === $currency || $tab === $transaction->account?->region) {
                    $income += (float) $transaction->amount;
                }
            } elseif ($transaction->type === 'expense') {
                if ($tab === 'all' || $transaction->account?->currency === $currency || $tab === $transaction->account?->region) {
                    $expense += (float) $transaction->amount;
                }
            } elseif ($transaction->type === 'transfer') {
                if ($tab === 'ph') {
                    if ($transaction->account?->region === 'ph') {
                        $transferOut += (float) $transaction->amount;
                    }
                    if ($transaction->toAccount?->region === 'ph') {
                        $transferIn += (float) ($transaction->to_amount ?? $transaction->amount);
                    }
                } elseif ($tab === 'jp') {
                    if ($transaction->account?->region === 'jp') {
                        $transferOut += (float) $transaction->amount;
                    }
                    if ($transaction->toAccount?->region === 'jp') {
                        $transferIn += (float) ($transaction->to_amount ?? $transaction->amount);
                    }
                } else {
                    $transferOut += (float) $transaction->amount;
                    $transferIn += (float) ($transaction->to_amount ?? $transaction->amount);
                }
            }
        }

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'transferOut' => round($transferOut, 2),
            'transferIn' => round($transferIn, 2),
            'net' => round($income - $expense, 2),
            'currency' => $currency,
        ];
    }

    /** @return array<string, mixed> */
    public function transactionToArray(FinanceTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'transactionDate' => $transaction->transaction_date->format('Y-m-d'),
            'type' => $transaction->type,
            'typeLabel' => self::TYPE_LABELS[$transaction->type] ?? $transaction->type,
            'accountId' => $transaction->account_id,
            'accountName' => $transaction->account?->name,
            'accountRegion' => $transaction->account?->region,
            'toAccountId' => $transaction->to_account_id,
            'toAccountName' => $transaction->toAccount?->name,
            'toAccountRegion' => $transaction->toAccount?->region,
            'amount' => (float) $transaction->amount,
            'toAmount' => $transaction->to_amount !== null ? (float) $transaction->to_amount : null,
            'currency' => $transaction->currency,
            'toCurrency' => $transaction->to_currency,
            'memo' => $transaction->memo ?? '',
            'isCrossRegion' => $transaction->type === 'transfer'
                && $transaction->account?->region !== $transaction->toAccount?->region,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createTransaction(array $payload): FinanceTransaction
    {
        $type = $this->normalizeType($payload['type'] ?? null);
        $account = FinanceAccount::query()->findOrFail((int) $payload['accountId']);
        $amount = round(max(0, (float) ($payload['amount'] ?? 0)), 2);
        $date = $this->normalizeDate($payload['transactionDate'] ?? null);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('金額は 0 より大きい値を入力してください');
        }

        if ($type === 'transfer') {
            $toAccount = FinanceAccount::query()->findOrFail((int) ($payload['toAccountId'] ?? 0));
            $toAmount = isset($payload['toAmount']) && $payload['toAmount'] !== ''
                ? round(max(0, (float) $payload['toAmount']), 2)
                : $amount;

            if ($toAmount <= 0) {
                throw new \InvalidArgumentException('入金側の金額は 0 より大きい値を入力してください');
            }

            return FinanceTransaction::query()->create([
                'transaction_date' => $date,
                'type' => 'transfer',
                'account_id' => $account->id,
                'to_account_id' => $toAccount->id,
                'amount' => $amount,
                'to_amount' => $toAmount,
                'currency' => $account->currency,
                'to_currency' => $toAccount->currency,
                'memo' => trim((string) ($payload['memo'] ?? '')),
            ]);
        }

        return FinanceTransaction::query()->create([
            'transaction_date' => $date,
            'type' => $type,
            'account_id' => $account->id,
            'amount' => $amount,
            'currency' => $account->currency,
            'memo' => trim((string) ($payload['memo'] ?? '')),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function updateTransaction(int $id, array $payload): bool
    {
        $transaction = FinanceTransaction::query()->find($id);
        if (! $transaction) {
            return false;
        }

        $type = $this->normalizeType($payload['type'] ?? $transaction->type);
        $account = FinanceAccount::query()->findOrFail((int) ($payload['accountId'] ?? $transaction->account_id));
        $amount = round(max(0, (float) ($payload['amount'] ?? $transaction->amount)), 2);
        $date = $this->normalizeDate($payload['transactionDate'] ?? $transaction->transaction_date->format('Y-m-d'));

        if ($amount <= 0) {
            throw new \InvalidArgumentException('金額は 0 より大きい値を入力してください');
        }

        $data = [
            'transaction_date' => $date,
            'type' => $type,
            'account_id' => $account->id,
            'amount' => $amount,
            'currency' => $account->currency,
            'memo' => trim((string) ($payload['memo'] ?? $transaction->memo)),
            'to_account_id' => null,
            'to_amount' => null,
            'to_currency' => null,
        ];

        if ($type === 'transfer') {
            $toAccount = FinanceAccount::query()->findOrFail((int) ($payload['toAccountId'] ?? $transaction->to_account_id));
            $toAmount = isset($payload['toAmount']) && $payload['toAmount'] !== ''
                ? round(max(0, (float) $payload['toAmount']), 2)
                : $amount;
            $data['to_account_id'] = $toAccount->id;
            $data['to_amount'] = $toAmount;
            $data['to_currency'] = $toAccount->currency;
        }

        return $transaction->update($data);
    }

    public function deleteTransaction(int $id): bool
    {
        return (bool) FinanceTransaction::query()->whereKey($id)->delete();
    }

    public function updateAccountInitialBalance(int $id, float $balance, ?float $adjustmentAmount = null): bool
    {
        $account = FinanceAccount::query()->find($id);
        if (! $account) {
            return false;
        }

        $account->initial_balance = round($balance, 2);
        if ($adjustmentAmount !== null) {
            $account->adjustment_amount = round($adjustmentAmount, 2);
        }

        return $account->save();
    }

    public function updateLinkedBank(int $accountId, ?int $linkedBankId): bool
    {
        $account = FinanceAccount::query()->find($accountId);
        if (! $account || $account->kind !== 'credit_card') {
            return false;
        }

        if ($linkedBankId !== null) {
            $bank = FinanceAccount::query()->find($linkedBankId);
            if (! $bank || ! in_array($bank->kind, ['bank', 'wallet'], true)) {
                return false;
            }
            if ($bank->region !== $account->region) {
                return false;
            }
        }

        $account->linked_bank_id = $linkedBankId;

        return $account->save();
    }

    /** @param array<string, mixed> $payload */
    public function createAccount(array $payload): FinanceAccount
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('口座名を入力してください');
        }

        $region = $this->normalizeRegion($payload['region'] ?? null);
        $kind = $this->normalizeKind($payload['kind'] ?? null);
        $linkedBankId = isset($payload['linkedBankId']) && $payload['linkedBankId'] !== ''
            ? (int) $payload['linkedBankId']
            : null;

        if ($kind === 'credit_card' && $linkedBankId !== null) {
            $this->assertValidLinkedBank($linkedBankId, $region);
        } elseif ($kind !== 'credit_card') {
            $linkedBankId = null;
        }

        $maxOrder = (int) FinanceAccount::query()->max('sort_order');

        return FinanceAccount::query()->create([
            'slug' => $this->generateAccountSlug($region, $kind, $name),
            'region' => $region,
            'kind' => $kind,
            'name' => $name,
            'currency' => $this->currencyForRegion($region),
            'sort_order' => isset($payload['sortOrder']) ? (int) $payload['sortOrder'] : $maxOrder + 10,
            'linked_bank_id' => $linkedBankId,
            'initial_balance' => round((float) ($payload['initialBalance'] ?? 0), 2),
            'adjustment_amount' => round((float) ($payload['adjustmentAmount'] ?? 0), 2),
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function updateAccount(int $id, array $payload): bool
    {
        $account = FinanceAccount::query()->where('is_active', true)->find($id);
        if (! $account) {
            return false;
        }

        $name = trim((string) ($payload['name'] ?? $account->name));
        if ($name === '') {
            throw new \InvalidArgumentException('口座名を入力してください');
        }

        $kind = $this->normalizeKind($payload['kind'] ?? $account->kind);
        $linkedBankId = array_key_exists('linkedBankId', $payload)
            ? ($payload['linkedBankId'] !== null && $payload['linkedBankId'] !== '' ? (int) $payload['linkedBankId'] : null)
            : $account->linked_bank_id;

        if ($kind === 'credit_card' && $linkedBankId !== null) {
            $this->assertValidLinkedBank($linkedBankId, $account->region);
        } elseif ($kind !== 'credit_card') {
            $linkedBankId = null;
        }

        $account->name = $name;
        $account->kind = $kind;
        $account->linked_bank_id = $linkedBankId;

        if (array_key_exists('initialBalance', $payload)) {
            $account->initial_balance = round((float) $payload['initialBalance'], 2);
        }
        if (array_key_exists('adjustmentAmount', $payload)) {
            $account->adjustment_amount = round((float) $payload['adjustmentAmount'], 2);
        }
        if (isset($payload['sortOrder'])) {
            $account->sort_order = (int) $payload['sortOrder'];
        }

        return $account->save();
    }

    public function deleteAccount(int $id): bool
    {
        $account = FinanceAccount::query()->where('is_active', true)->find($id);
        if (! $account) {
            return false;
        }

        FinanceAccount::query()
            ->where('linked_bank_id', $account->id)
            ->update(['linked_bank_id' => null]);

        $account->is_active = false;

        return $account->save();
    }

    /** @param list<int> $orderedIds */
    public function reorderAccounts(array $orderedIds): bool
    {
        $orderedIds = array_values(array_unique(array_filter(array_map('intval', $orderedIds))));
        if ($orderedIds === []) {
            throw new \InvalidArgumentException('並び替える口座がありません');
        }

        $accounts = FinanceAccount::query()
            ->where('is_active', true)
            ->whereIn('id', $orderedIds)
            ->get()
            ->keyBy('id');

        if ($accounts->count() !== count($orderedIds)) {
            throw new \InvalidArgumentException('無効な口座が含まれています');
        }

        $kinds = $accounts->pluck('kind')->unique();
        if ($kinds->count() > 1) {
            throw new \InvalidArgumentException('同じ種別の口座のみ並び替えできます');
        }

        $minOrder = (int) $accounts->min('sort_order');
        foreach ($orderedIds as $index => $id) {
            $account = $accounts[$id];
            $account->sort_order = $minOrder + ($index * 10);
            $account->save();
        }

        return true;
    }

    private function assertValidLinkedBank(int $linkedBankId, string $region): void
    {
        $bank = FinanceAccount::query()->where('is_active', true)->find($linkedBankId);
        if (! $bank || ! in_array($bank->kind, ['bank', 'wallet', 'cash'], true)) {
            throw new \InvalidArgumentException('引落口座が正しくありません');
        }
        if ($bank->region !== $region) {
            throw new \InvalidArgumentException('引落口座は同じ地域の口座を選択してください');
        }
    }

    private function generateAccountSlug(string $region, string $kind, string $name): string
    {
        $base = Str::slug($region.'_'.$kind.'_'.$name, '_');
        if ($base === '') {
            $base = $region.'_'.$kind.'_account';
        }
        $slug = substr($base, 0, 58);
        $suffix = 1;
        while (FinanceAccount::query()->where('slug', $slug)->exists()) {
            $slug = substr($base, 0, 54).'_'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function scheduleTypeForAccountKind(?string $kind): ?string
    {
        return match ($kind) {
            'credit_card' => 'payment',
            'bank' => 'deposit',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function scheduleToArray(FinanceAccountSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'accountId' => $schedule->account_id,
            'scheduleType' => $schedule->schedule_type,
            'typeLabel' => self::SCHEDULE_TYPE_LABELS[$schedule->schedule_type] ?? $schedule->schedule_type,
            'scheduledDate' => $schedule->scheduled_date->format('Y-m-d'),
            'amount' => (float) $schedule->amount,
            'memo' => $schedule->memo ?? '',
            'currency' => $schedule->account?->currency ?? 'JPY',
            'accountName' => $schedule->account?->name ?? '',
        ];
    }

    /** @param \Illuminate\Support\Collection<int, FinanceAccountSchedule>|null $schedules */
    /** @param array<string, mixed> $accountRow */
    public function attachSchedulesToAccountRow(array $accountRow, $schedules = null): array
    {
        $scheduleItems = collect($schedules ?? [])
            ->map(fn (FinanceAccountSchedule $schedule) => $this->scheduleToArray($schedule))
            ->values()
            ->all();

        $accountRow['scheduleType'] = $this->scheduleTypeForAccountKind($accountRow['kind'] ?? null);
        $accountRow['scheduleTypeLabel'] = $accountRow['scheduleType']
            ? (self::SCHEDULE_TYPE_LABELS[$accountRow['scheduleType']] ?? '')
            : '';
        $accountRow['schedules'] = $scheduleItems;
        $accountRow['nextSchedule'] = $scheduleItems[0] ?? null;

        return $accountRow;
    }

    /** @param array<string, mixed> $payload */
    public function createSchedule(int $accountId, array $payload): FinanceAccountSchedule
    {
        $account = FinanceAccount::query()->where('is_active', true)->find($accountId);
        if (! $account) {
            throw new \InvalidArgumentException('口座が見つかりません');
        }

        $scheduleType = $this->scheduleTypeForAccountKind($account->kind);
        if (! $scheduleType) {
            throw new \InvalidArgumentException('この口座では予定を登録できません');
        }

        $amount = round(max(0, (float) ($payload['amount'] ?? 0)), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('金額は 0 より大きい値を入力してください');
        }

        return FinanceAccountSchedule::query()->create([
            'account_id' => $account->id,
            'schedule_type' => $scheduleType,
            'scheduled_date' => $this->normalizeDate($payload['scheduledDate'] ?? null),
            'amount' => $amount,
            'memo' => trim((string) ($payload['memo'] ?? '')),
        ]);
    }

    public function deleteSchedule(int $id): bool
    {
        return (bool) FinanceAccountSchedule::query()->whereKey($id)->delete();
    }

    /** @param array<string, mixed> $payload */
    public function upsertNextSchedule(int $accountId, array $payload): FinanceAccountSchedule
    {
        $account = FinanceAccount::query()->where('is_active', true)->find($accountId);
        if (! $account) {
            throw new \InvalidArgumentException('口座が見つかりません');
        }

        $scheduleType = $this->scheduleTypeForAccountKind($account->kind);
        if (! $scheduleType) {
            throw new \InvalidArgumentException('この口座では予定を登録できません');
        }

        $amount = round(max(0, (float) ($payload['amount'] ?? 0)), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('金額は 0 より大きい値を入力してください');
        }

        $data = [
            'scheduled_date' => $this->normalizeDate($payload['scheduledDate'] ?? null),
            'amount' => $amount,
            'memo' => trim((string) ($payload['memo'] ?? '')),
        ];

        $existing = FinanceAccountSchedule::query()
            ->where('account_id', $account->id)
            ->where('schedule_type', $scheduleType)
            ->where('scheduled_date', '>=', $this->todayIso())
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->first();

        if ($existing) {
            $existing->fill($data);
            $existing->save();

            return $existing;
        }

        return FinanceAccountSchedule::query()->create([
            'account_id' => $account->id,
            'schedule_type' => $scheduleType,
            ...$data,
        ]);
    }

    /** @param list<array<string, mixed>> $accounts */
    /** @return array{totals: array<string, float>, assets: array<string, float>, creditCards: array<string, float>, upcomingPayments: array<string, float>, upcomingDeposits: array<string, float>} */
    public function buildBalanceTotals(array $accounts): array
    {
        $totals = [];
        $assets = [];
        $creditCards = [];
        $upcomingPayments = [];
        $upcomingDeposits = [];

        foreach ($accounts as $account) {
            $currency = $account['currency'];
            $balance = (float) $account['balance'];
            $totals[$currency] = ($totals[$currency] ?? 0) + $balance;

            if ($account['kind'] === 'credit_card') {
                $creditCards[$currency] = ($creditCards[$currency] ?? 0) + $balance;
                if (! empty($account['nextSchedule'])) {
                    $upcomingPayments[$currency] = ($upcomingPayments[$currency] ?? 0) + (float) $account['nextSchedule']['amount'];
                }
            } elseif (in_array($account['kind'], ['bank', 'cash', 'wallet'], true)) {
                $assets[$currency] = ($assets[$currency] ?? 0) + $balance;
                if ($account['kind'] === 'bank' && ! empty($account['nextSchedule'])) {
                    $upcomingDeposits[$currency] = ($upcomingDeposits[$currency] ?? 0) + (float) $account['nextSchedule']['amount'];
                }
            }
        }

        $roundMap = fn (array $map) => array_map(fn (float $value) => round($value, 2), $map);

        return [
            'totals' => $roundMap($totals),
            'assets' => $roundMap($assets),
            'creditCards' => $roundMap($creditCards),
            'upcomingPayments' => $roundMap($upcomingPayments),
            'upcomingDeposits' => $roundMap($upcomingDeposits),
        ];
    }

    public function formatMoney(float $amount, string $currency): string
    {
        $prefix = $currency === 'PHP' ? '₱' : '¥';

        return $prefix.number_format($amount, $currency === 'PHP' ? 2 : 0);
    }

    public function todayIso(): string
    {
        return date('Y-m-d');
    }

    private function normalizeDate(?string $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return $this->todayIso();
    }
}
