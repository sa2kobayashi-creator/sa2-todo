<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FinanceAccountSchedule;
use App\Models\FinanceExpenseCategory;
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

    /** クイック選択用の支出カテゴリ */
    public const EXPENSE_CATEGORY_PRIMARY = [
        'medical' => '医療費',
        'tobacco_alcohol' => 'たばこ/酒',
        'transport' => '交通費',
        'food' => '食費',
        'shopping' => '買い物',
    ];

    /** 「その他」モーダル内の支出カテゴリ */
    public const EXPENSE_CATEGORY_OTHER = [
        'tuition' => '学費',
        'electricity' => '電気',
        'living' => '生活費',
        'internet' => 'インターネット',
        'allowance' => 'お小遣い',
        'card_payment' => 'カード支払い',
        'loan_payment' => '借用支払い',
        'fee' => '手数料',
    ];

    public const SCHEDULE_TYPE_LABELS = [
        'payment' => '支払予定',
        'deposit' => '入金予定',
    ];


    private ?int $actingUserId = null;

    public function actingAs(int $userId): self
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('ユーザーが不正です');
        }
        $this->actingUserId = $userId;

        return $this;
    }

    public function requireUserId(): int
    {
        if ($this->actingUserId === null || $this->actingUserId <= 0) {
            throw new \InvalidArgumentException('ユーザーコンテキストが設定されていません');
        }

        return $this->actingUserId;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<FinanceAccount> */
    private function accountsQuery()
    {
        return FinanceAccount::query()->where('user_id', $this->requireUserId());
    }

    /** @return \Illuminate\Database\Eloquent\Builder<FinanceTransaction> */
    private function transactionsQuery()
    {
        return FinanceTransaction::query()->where('user_id', $this->requireUserId());
    }

    /** @return \Illuminate\Database\Eloquent\Builder<FinanceExpenseCategory> */
    private function expenseCategoriesQuery()
    {
        return FinanceExpenseCategory::query()->where('user_id', $this->requireUserId());
    }

    /** @return \Illuminate\Database\Eloquent\Builder<FinanceAccountSchedule> */
    private function schedulesQuery()
    {
        return FinanceAccountSchedule::query()->whereHas(
            'account',
            fn ($q) => $q->where('user_id', $this->requireUserId())
        );
    }

    private function findOwnedAccount(int $id, bool $activeOnly = false): ?FinanceAccount
    {
        $query = $this->accountsQuery();
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->find($id);
    }

    private function requireOwnedAccount(int $id, bool $activeOnly = false): FinanceAccount
    {
        $account = $this->findOwnedAccount($id, $activeOnly);
        if (! $account) {
            throw new \InvalidArgumentException('口座が見つかりません');
        }

        return $account;
    }

    /** @return array<string, string> */
    public static function builtInExpenseCategoryLabels(): array
    {
        return self::EXPENSE_CATEGORY_PRIMARY + self::EXPENSE_CATEGORY_OTHER;
    }

    /** @return array<string, string> slug => label */
    public function customExpenseCategoryLabels(): array
    {
        return $this->expenseCategoriesQuery()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (FinanceExpenseCategory $category) => [$category->slug => $category->label])
            ->all();
    }

    /** 「その他」モーダル用（組み込み + ユーザー追加） */
    /** @return array<string, string> */
    public function expenseCategoryOther(): array
    {
        return collect(self::EXPENSE_CATEGORY_OTHER)
            ->map(fn (string $label) => __($label))
            ->all() + $this->customExpenseCategoryLabels();
    }

    /** @return array<string, string> */
    public function expenseCategoryLabels(): array
    {
        return collect(self::EXPENSE_CATEGORY_PRIMARY)
            ->map(fn (string $label) => __($label))
            ->all() + $this->expenseCategoryOther();
    }

    public function normalizeExpenseCategory(?string $category, string $type): ?string
    {
        if ($type !== 'expense') {
            return null;
        }
        $category = trim((string) $category);
        if ($category === '' || ! array_key_exists($category, $this->expenseCategoryLabels())) {
            return null;
        }

        return $category;
    }

    /**
     * @return array{slug: string, label: string}
     */
    public function createExpenseCategory(string $label): array
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        if ($label === '') {
            throw new \InvalidArgumentException('カテゴリー名を入力してください');
        }
        if (mb_strlen($label) > 40) {
            throw new \InvalidArgumentException('カテゴリー名は40文字以内にしてください');
        }

        $existingLabels = array_map('mb_strtolower', array_values($this->expenseCategoryLabels()));
        if (in_array(mb_strtolower($label), $existingLabels, true)) {
            throw new \InvalidArgumentException('同じ名前のカテゴリーが既にあります');
        }

        $slug = $this->makeExpenseCategorySlug($label);
        $sortOrder = (int) $this->expenseCategoriesQuery()->max('sort_order') + 10;

        $category = $this->expenseCategoriesQuery()->create([
            'user_id' => $this->requireUserId(),
            'slug' => $slug,
            'label' => $label,
            'sort_order' => $sortOrder,
        ]);

        return [
            'slug' => $category->slug,
            'label' => $category->label,
        ];
    }

    public function deleteExpenseCategory(string $slug): bool
    {
        $slug = trim($slug);
        if ($slug === '' || array_key_exists($slug, self::builtInExpenseCategoryLabels())) {
            throw new \InvalidArgumentException('このカテゴリーは削除できません');
        }

        $category = $this->expenseCategoriesQuery()->where('slug', $slug)->first();
        if (! $category) {
            throw new \InvalidArgumentException('カテゴリーが見つかりません');
        }

        return (bool) $category->delete();
    }

    private function makeExpenseCategorySlug(string $label): string
    {
        $base = Str::slug($label, '_');
        if ($base === '') {
            $base = 'custom';
        }
        $base = Str::limit($base, 24, '');
        $slug = $base;
        $n = 1;
        $reserved = $this->expenseCategoryLabels();
        while (array_key_exists($slug, $reserved) || $this->expenseCategoriesQuery()->where('slug', $slug)->exists()) {
            $slug = $base.'_'.$n;
            $n++;
            if ($n > 100) {
                $slug = 'custom_'.Str::lower(Str::random(8));
                break;
            }
        }

        return Str::limit($slug, 40, '');
    }

    /** @var list<array{slug: string, region: string, kind: string, name: string, currency: string, sort_order: int, linked_slug?: string}> */
    public const DEFAULT_ACCOUNTS = [
        ['slug' => 'jp_bank_rakuten', 'region' => 'jp', 'kind' => 'bank', 'name' => '楽天銀行', 'currency' => 'JPY', 'sort_order' => 10],
        ['slug' => 'jp_bank_seven', 'region' => 'jp', 'kind' => 'bank', 'name' => 'セブン銀行', 'currency' => 'JPY', 'sort_order' => 20],
        ['slug' => 'jp_bank_paypay', 'region' => 'jp', 'kind' => 'bank', 'name' => 'PAYPAY銀行', 'currency' => 'JPY', 'sort_order' => 30],
        ['slug' => 'jp_bank_smbc', 'region' => 'jp', 'kind' => 'bank', 'name' => '三井住友銀行', 'currency' => 'JPY', 'sort_order' => 40],
        ['slug' => 'jp_cash_petty', 'region' => 'jp', 'kind' => 'cash', 'name' => 'Petty Cash', 'currency' => 'JPY', 'sort_order' => 50],
        ['slug' => 'jp_card_rakuten', 'region' => 'jp', 'kind' => 'credit_card', 'name' => '楽天VISA', 'currency' => 'JPY', 'sort_order' => 110, 'linked_slug' => 'jp_bank_rakuten'],
        ['slug' => 'jp_card_rakuten_premium', 'region' => 'jp', 'kind' => 'credit_card', 'name' => '楽天VISAプレミアム', 'currency' => 'JPY', 'sort_order' => 115, 'linked_slug' => 'jp_bank_rakuten'],
        ['slug' => 'jp_card_amazon', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'Amazon Master', 'currency' => 'JPY', 'sort_order' => 120],
        ['slug' => 'jp_card_paypay', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'PayPAY Visa', 'currency' => 'JPY', 'sort_order' => 130, 'linked_slug' => 'jp_bank_paypay'],
        ['slug' => 'jp_card_jal', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'JAL JCB', 'currency' => 'JPY', 'sort_order' => 140],
        ['slug' => 'jp_card_seven', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'セブンJCB', 'currency' => 'JPY', 'sort_order' => 150, 'linked_slug' => 'jp_bank_seven'],
        ['slug' => 'jp_card_fami', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'ファミJCB', 'currency' => 'JPY', 'sort_order' => 160],
        ['slug' => 'jp_card_epos', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'EPOS VISA', 'currency' => 'JPY', 'sort_order' => 170],
        ['slug' => 'jp_card_smbc_cl', 'region' => 'jp', 'kind' => 'credit_card', 'name' => '三井住友CL', 'currency' => 'JPY', 'sort_order' => 180, 'linked_slug' => 'jp_bank_smbc'],
        ['slug' => 'ph_bank_bpi', 'region' => 'ph', 'kind' => 'bank', 'name' => 'BPI', 'currency' => 'PHP', 'sort_order' => 210],
        ['slug' => 'ph_wallet_maya', 'region' => 'ph', 'kind' => 'wallet', 'name' => 'Maya', 'currency' => 'PHP', 'sort_order' => 220],
        ['slug' => 'ph_wallet_wize', 'region' => 'ph', 'kind' => 'wallet', 'name' => 'Wize', 'currency' => 'PHP', 'sort_order' => 230],
        ['slug' => 'ph_cash_petty', 'region' => 'ph', 'kind' => 'cash', 'name' => 'PH Petty Cash', 'currency' => 'PHP', 'sort_order' => 240],
        ['slug' => 'ph_card_bpi_mc', 'region' => 'ph', 'kind' => 'credit_card', 'name' => 'BPI Master', 'currency' => 'PHP', 'sort_order' => 310, 'linked_slug' => 'ph_bank_bpi'],
        ['slug' => 'ph_card_bankard_mc_gold', 'region' => 'ph', 'kind' => 'credit_card', 'name' => 'Bankard Gold Master', 'currency' => 'PHP', 'sort_order' => 320],
        ['slug' => 'ph_card_bankard_airmiles', 'region' => 'ph', 'kind' => 'credit_card', 'name' => 'Bankard Airmiles Visa', 'currency' => 'PHP', 'sort_order' => 330],
    ];

    public function ensureDefaultAccounts(): void
    {
        $userId = $this->requireUserId();
        if ($this->accountsQuery()->exists()) {
            return;
        }

        $idBySlug = [];
        foreach (self::DEFAULT_ACCOUNTS as $row) {
            $account = $this->accountsQuery()->create([
                'user_id' => $userId,
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
            $account = $this->accountsQuery()->where('slug', $row['slug'])->first();
            if ($account && isset($idBySlug[$row['linked_slug']])) {
                $account->linked_bank_id = $idBySlug[$row['linked_slug']];
                $account->save();
            }
        }
    }

    public function filterAccountsForTab(array $accounts, string $tab): array
    {
        if ($tab === 'all' || $tab === 'transfer') {
            return array_values($accounts);
        }

        return array_values(array_filter($accounts, fn (array $account) => $account['region'] === $tab));
    }

    /** @param list<array<string, mixed>> $accounts @return list<array{region: ?string, regionLabel: ?string, kinds: array<string, list<array<string, mixed>>>}> */
    public function buildAccountDisplayGroups(array $accounts, string $tab): array
    {
        if ($tab === 'all') {
            $groups = [];
            foreach (['jp', 'ph'] as $region) {
                $regionAccounts = $this->filterAccountsForTab($accounts, $region);
                $kinds = $this->groupAccountsByKind($regionAccounts);
                if ($kinds === []) {
                    continue;
                }

                $groups[] = [
                    'region' => $region,
                    'regionLabel' => __(self::REGION_LABELS[$region] ?? $region),
                    'kinds' => $kinds,
                ];
            }

            return $groups;
        }

        $kinds = $this->groupAccountsByKind($accounts);
        if ($kinds === []) {
            return [];
        }

        return [[
            'region' => in_array($tab, ['jp', 'ph'], true) ? $tab : null,
            'regionLabel' => isset(self::REGION_LABELS[$tab]) ? __(self::REGION_LABELS[$tab]) : null,
            'kinds' => $kinds,
        ]];
    }

    /** @param list<array<string, mixed>> $accounts @return array<string, list<array<string, mixed>>> */
    public function groupAccountsByRegion(array $accounts): array
    {
        $groups = [];
        foreach (['jp', 'ph'] as $region) {
            $items = array_values(array_filter($accounts, fn (array $account) => $account['region'] === $region));
            if ($items !== []) {
                $groups[$region] = $items;
            }
        }

        return $groups;
    }

    public function tabContextLabel(string $tab): string
    {
        return match ($tab) {
            'jp' => '日本の口座・取引を表示中',
            'ph' => 'フィリピンの口座・取引を表示中',
            'transfer' => '送金取引を表示中',
            'all' => '日本・フィリピンのすべてを表示中',
            default => '',
        };
    }

    /** @param list<array<string, mixed>> $accounts */
    public function sanitizeAccountFilter(array $filters, array $accounts): array
    {
        if ($filters['accountId'] === null) {
            return $filters;
        }

        $account = collect($accounts)->firstWhere('id', $filters['accountId']);
        if (! $account) {
            $filters['accountId'] = null;

            return $filters;
        }

        $tab = $filters['tab'];
        if ($tab !== 'all' && $tab !== 'transfer' && $account['region'] !== $tab) {
            $filters['accountId'] = null;
        }

        return $filters;
    }

    /** @return array{tab: string, year: int, month: int, day: ?int, accountId: ?int} */
    public function parseFilters(array $query): array
    {
        $tab = $this->normalizeTab($query['tab'] ?? 'jp');

        $year = isset($query['year']) ? (int) $query['year'] : null;
        $month = isset($query['month']) ? (int) $query['month'] : null;
        $period = is_string($query['period'] ?? null) ? $query['period'] : null;

        if ($year === null || $month === null) {
            if (is_string($period) && preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
                $year = (int) $matches[1];
                $month = (int) $matches[2];
            } else {
                $year = (int) date('Y');
                $month = (int) date('n');
            }
        }

        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }

        $day = null;
        if (isset($query['day']) && $query['day'] !== '' && $query['day'] !== null) {
            $day = (int) $query['day'];
            $daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
            if ($day < 1 || $day > $daysInMonth) {
                $day = null;
            }
        }

        $accountId = isset($query['account']) ? (int) $query['account'] : null;
        if ($accountId !== null && $accountId <= 0) {
            $accountId = null;
        }

        return [
            'tab' => $tab,
            'year' => $year,
            'month' => $month,
            'day' => $day,
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

    /** @param array{tab?: string, year?: int, month?: int, day?: ?int, accountId?: ?int} $filters */
    public function buildFinanceQuery(array $filters, array $extra = []): string
    {
        $params = array_filter([
            'tab' => $filters['tab'] ?? null,
            'year' => $filters['year'] ?? null,
            'month' => isset($filters['month']) ? sprintf('%02d', (int) $filters['month']) : null,
            'day' => ! empty($filters['day']) ? sprintf('%02d', (int) $filters['day']) : null,
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

    /** @param array{tab?: string, year?: int, month?: int, accountId?: ?int} $filters */
    public function buildFinanceExportQuery(array $filters, string $format): string
    {
        $params = array_filter([
            'tab' => $filters['tab'] ?? null,
            'period' => isset($filters['year'], $filters['month'])
                ? sprintf('%04d-%02d', $filters['year'], $filters['month'])
                : null,
            'account' => ! empty($filters['accountId']) ? $filters['accountId'] : null,
            'format' => $format,
        ], fn ($value) => $value !== null && $value !== '');

        return '/finance/export?'.http_build_query($params);
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
        $this->materializeDueSchedules();

        $monthStart = sprintf('%04d-%02d-01', $filters['year'], $filters['month']);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $monthTransactions = $this->transactionsQuery()
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

        $allTransactions = $this->transactionsQuery()->get();
        $accountsWithBalance = collect($this->listAccounts())
            ->map(function (array $accountRow) use ($allTransactions) {
                $model = $this->accountsQuery()->find($accountRow['id']);
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

        $schedules = $this->schedulesQuery()
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
            'monthLabel' => __(':year年:month月', ['year' => $filters['year'], 'month' => $filters['month']]),
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
        $query = $this->accountsQuery()->where('is_active', true)->orderBy('sort_order');
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
            'regionLabel' => __(self::REGION_LABELS[$account->region] ?? $account->region),
            'kind' => $account->kind,
            'kindLabel' => __(self::KIND_LABELS[$account->kind] ?? $account->kind),
            'name' => $account->name,
            'currency' => $account->currency,
            'sortOrder' => $account->sort_order,
            'linkedBankId' => $account->linked_bank_id,
            'initialBalance' => (float) $account->initial_balance,
            'adjustmentAmount' => (float) ($account->adjustment_amount ?? 0),
            'balance' => $balance,
            'balanceLabel' => __($account->kind === 'credit_card' ? '利用額' : '残高'),
            'showInOverview' => (bool) ($account->show_in_overview ?? false),
        ];
    }

    public function calculateAccountBalance(FinanceAccount $account, ?Collection $transactions = null): float
    {
        if ($account->kind === 'credit_card') {
            return $this->calculateCreditCardBalance($account, $transactions);
        }

        $balance = (float) $account->initial_balance + (float) ($account->adjustment_amount ?? 0);
        $transactions ??= $this->transactionsQuery()->get();
        $transactions = $this->filterEffectiveTransactions($transactions);

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

    public function calculateCreditCardBalance(FinanceAccount $account, ?Collection $transactions = null): float
    {
        $balance = max(0, (float) $account->initial_balance) + max(0, (float) ($account->adjustment_amount ?? 0));
        if ((float) $account->initial_balance < 0) {
            $balance += abs((float) $account->initial_balance);
        }
        if ((float) ($account->adjustment_amount ?? 0) < 0) {
            $balance += abs((float) $account->adjustment_amount);
        }

        $transactions ??= $this->transactionsQuery()->get();
        $transactions = $this->filterEffectiveTransactions($transactions);

        foreach ($transactions as $transaction) {
            if ((int) $transaction->account_id === (int) $account->id) {
                if ($transaction->type === 'expense') {
                    $balance += (float) $transaction->amount;
                } elseif ($transaction->type === 'income') {
                    $balance -= (float) $transaction->amount;
                } elseif ($transaction->type === 'transfer') {
                    $balance -= (float) $transaction->amount;
                }
            }

            if ($transaction->type === 'transfer' && (int) $transaction->to_account_id === (int) $account->id) {
                $balance -= (float) ($transaction->to_amount ?? $transaction->amount);
            }
        }

        return round(max(0, $balance), 2);
    }

    /** @return array{configured: ?array{initialBalance: float, adjustmentAmount: float, total: float, currency: string}, items: list<array{id: int, label: string, date: string, displayDate: string, amount: float, currency: string}>} */
    public function buildCreditCardUsageBreakdown(FinanceAccount $account, ?Collection $transactions = null): array
    {
        if ($account->kind !== 'credit_card') {
            return ['configured' => null, 'items' => []];
        }

        $configuredTotal = $this->creditCardConfiguredBalance($account);
        $configured = $configuredTotal > 0 ? [
            'initialBalance' => (float) $account->initial_balance,
            'adjustmentAmount' => (float) ($account->adjustment_amount ?? 0),
            'total' => $configuredTotal,
            'currency' => $account->currency,
        ] : null;

        $charges = [];
        if ($configuredTotal > 0) {
            $charges[] = [
                'id' => 0,
                'label' => '設定残高',
                'date' => '0000-01-01',
                'displayDate' => '設定',
                'amount' => $configuredTotal,
                'currency' => $account->currency,
            ];
        }

        $transactions ??= $this->transactionsQuery()
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
        $transactions = $this->filterEffectiveTransactions($transactions);

        $payments = 0.0;

        foreach ($transactions as $transaction) {
            if ((int) $transaction->account_id === (int) $account->id && $transaction->type === 'expense') {
                $memo = trim((string) ($transaction->memo ?? ''));
                $label = $this->formatDisplayMemo($memo, $account->name);
                $charges[] = [
                    'id' => $transaction->id,
                    'label' => $label !== '' ? $label : '利用',
                    'date' => $transaction->transaction_date->format('Y-m-d'),
                    'displayDate' => $transaction->transaction_date->format('Y/n/j'),
                    'amount' => round((float) $transaction->amount, 2),
                    'currency' => $transaction->currency,
                ];

                continue;
            }

            if ((int) $transaction->account_id === (int) $account->id) {
                if ($transaction->type === 'income') {
                    $payments += (float) $transaction->amount;
                } elseif ($transaction->type === 'transfer') {
                    $payments += (float) $transaction->amount;
                }
            }

            if ($transaction->type === 'transfer' && (int) $transaction->to_account_id === (int) $account->id) {
                $payments += (float) ($transaction->to_amount ?? $transaction->amount);
            }
        }

        $remainingPayment = round($payments, 2);
        $outstanding = [];

        foreach ($charges as $charge) {
            if ($remainingPayment >= $charge['amount']) {
                $remainingPayment = round($remainingPayment - $charge['amount'], 2);

                continue;
            }

            if ($remainingPayment > 0) {
                $charge['amount'] = round($charge['amount'] - $remainingPayment, 2);
                $remainingPayment = 0.0;
            }

            $outstanding[] = $charge;
        }

        $items = array_values(array_filter(
            array_reverse($outstanding),
            fn (array $item) => $item['id'] !== 0
        ));

        return [
            'configured' => $configured,
            'items' => $items,
        ];
    }

    public function creditCardConfiguredBalance(FinanceAccount $account): float
    {
        $opening = max(0, (float) $account->initial_balance);
        if ((float) $account->initial_balance < 0) {
            $opening += abs((float) $account->initial_balance);
        }
        $adjustment = (float) ($account->adjustment_amount ?? 0);
        if ($adjustment > 0) {
            $opening += $adjustment;
        } elseif ($adjustment < 0) {
            $opening += abs($adjustment);
        }

        return round($opening, 2);
    }

    /** @return list<array{id: int, label: string, date: string, displayDate: string, amount: float, currency: string}> */
    public function buildCreditCardOutstandingCharges(FinanceAccount $account, ?Collection $transactions = null): array
    {
        return $this->buildCreditCardUsageBreakdown($account, $transactions)['items'];
    }

    public function materializeDueSchedules(): void
    {
        $this->materializeDuePaymentSchedules();
        $this->materializeDueDepositSchedules();
    }

    public function materializeDuePaymentSchedules(): void
    {
        $dueSchedules = $this->schedulesQuery()
            ->with('account')
            ->where('schedule_type', 'payment')
            ->whereDate('scheduled_date', '<=', $this->todayIso())
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get();

        foreach ($dueSchedules as $schedule) {
            $card = $schedule->account;
            if (! $card || (int) $card->user_id !== $this->requireUserId() || $card->kind !== 'credit_card' || ! $card->is_active) {
                continue;
            }

            $marker = $this->scheduleMarker($schedule->id);
            if ($this->transactionsQuery()->where('memo', 'like', '%'.$marker.'%')->exists()) {
                continue;
            }

            $amount = round((float) $schedule->amount, 2);
            if ($amount <= 0) {
                continue;
            }

            $date = $schedule->scheduled_date->format('Y-m-d');
            $memo = trim('カード引落: '.$card->name.($schedule->memo ? ' '.$schedule->memo : '').' '.$marker);

            $bank = $card->linked_bank_id
                ? $this->accountsQuery()->where('is_active', true)->find($card->linked_bank_id)
                : null;

            if ($bank) {
                $this->transactionsQuery()->create([
                    'user_id' => $this->requireUserId(),
                    'transaction_date' => $date,
                    'type' => 'transfer',
                    'account_id' => $bank->id,
                    'to_account_id' => $card->id,
                    'amount' => $amount,
                    'to_amount' => $amount,
                    'currency' => $bank->currency,
                    'to_currency' => $card->currency,
                    'memo' => $memo,
                ]);
            }
        }
    }

    public function materializeDueDepositSchedules(): void
    {
        $dueSchedules = $this->schedulesQuery()
            ->with('account')
            ->where('schedule_type', 'deposit')
            ->whereDate('scheduled_date', '<=', $this->todayIso())
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get();

        foreach ($dueSchedules as $schedule) {
            $bank = $schedule->account;
            if (! $bank || (int) $bank->user_id !== $this->requireUserId() || $bank->kind !== 'bank' || ! $bank->is_active) {
                continue;
            }

            $marker = $this->scheduleMarker($schedule->id);
            if ($this->transactionsQuery()->where('memo', 'like', '%'.$marker.'%')->exists()) {
                continue;
            }

            $amount = round((float) $schedule->amount, 2);
            if ($amount <= 0) {
                continue;
            }

            $date = $schedule->scheduled_date->format('Y-m-d');
            $memo = trim('入金予定: '.$bank->name.($schedule->memo ? ' '.$schedule->memo : '').' '.$marker);

            $this->transactionsQuery()->create([
                'user_id' => $this->requireUserId(),
                'transaction_date' => $date,
                'type' => 'income',
                'account_id' => $bank->id,
                'amount' => $amount,
                'currency' => $bank->currency,
                'memo' => $memo,
            ]);
        }
    }

    /** @return array{accounts: list<array<string, mixed>>, summary: array<string, float>, transactions: list<array<string, mixed>>} */
    public function buildPageData(array $filters): array
    {
        $this->ensureDefaultAccounts();
        $this->materializeDueSchedules();

        $accounts = collect($this->listAccounts());
        $allTransactions = $this->transactionsQuery()
            ->with(['account', 'toAccount'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $accountsWithBalance = $accounts
            ->map(function (array $accountRow) use ($allTransactions) {
                $model = $this->accountsQuery()->find($accountRow['id']);
                if (! $model) {
                    return $accountRow;
                }
                $accountRow['balance'] = $this->calculateAccountBalance($model, $allTransactions);

                return $accountRow;
            });

        $schedulesByAccount = $this->schedulesQuery()
            ->with('account')
            ->whereIn('account_id', $accountsWithBalance->pluck('id'))
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get()
            ->groupBy('account_id');

        $accountsWithBalance = $accountsWithBalance
            ->map(function (array $accountRow) use ($schedulesByAccount, $allTransactions) {
                $accountRow = $this->attachSchedulesToAccountRow(
                    $accountRow,
                    $schedulesByAccount->get($accountRow['id'])
                );

                if ($accountRow['kind'] !== 'credit_card') {
                    $accountRow['usageConfigured'] = null;
                    $accountRow['usageHistory'] = [];

                    return $accountRow;
                }

                // 請求内訳 UI は非表示。残高計算用ロジックは別途残す。
                $accountRow['usageConfigured'] = null;
                $accountRow['usageHistory'] = [];

                return $accountRow;
            });

        $allAccountRows = $accountsWithBalance->values()->all();
        $filters = $this->sanitizeAccountFilter($filters, $allAccountRows);

        $monthStart = sprintf('%04d-%02d-01', $filters['year'], $filters['month']);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $monthTransactions = $allTransactions->filter(
            fn (FinanceTransaction $t) => $t->transaction_date->format('Y-m-d') >= $monthStart
                && $t->transaction_date->format('Y-m-d') <= $monthEnd
        );

        $effectiveMonthTransactions = $this->filterEffectiveTransactions($monthTransactions);
        $summary = $this->buildMonthSummary($effectiveMonthTransactions, $filters['tab']);
        $summaryDetails = $this->buildSummaryDetails($effectiveMonthTransactions, $filters['tab']);

        $allSchedules = $this->schedulesQuery()
            ->with('account')
            ->whereIn('account_id', $accountsWithBalance->pluck('id'))
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get();

        $displayTransactionRows = $this->buildDisplayTransactionsForPage(
            $monthTransactions,
            $allSchedules,
            $filters,
            $allTransactions,
            $allAccountRows,
            $monthStart,
            $monthEnd,
        );

        $transactionBalanceContext = $this->buildTransactionBalanceContext(
            $allAccountRows,
            $allTransactions,
            $filters['accountId'] ?? null,
            $monthStart,
        );

        $visibleAccounts = $this->filterAccountsForTab($allAccountRows, $filters['tab']);

        $groupedAccounts = $this->groupAccountsByKind($visibleAccounts);
        $accountDisplayGroups = $this->buildAccountDisplayGroups($visibleAccounts, $filters['tab']);

        $overviewAccounts = array_values(array_filter(
            $visibleAccounts,
            fn (array $account) => ! empty($account['showInOverview'])
        ));
        usort($overviewAccounts, fn (array $a, array $b) => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0));
        $overviewAccountsByRegion = $this->groupAccountsByRegion($overviewAccounts);

        $unpinnedAccounts = array_values(array_filter(
            $visibleAccounts,
            fn (array $account) => empty($account['showInOverview'])
        ));
        usort($unpinnedAccounts, fn (array $a, array $b) => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0));

        return [
            'accounts' => $visibleAccounts,
            'allAccounts' => $allAccountRows,
            'groupedAccounts' => $groupedAccounts,
            'accountDisplayGroups' => $accountDisplayGroups,
            'overviewAccounts' => $overviewAccounts,
            'overviewAccountsByRegion' => $overviewAccountsByRegion,
            'unpinnedAccounts' => $unpinnedAccounts,
            'balanceTotals' => $this->buildBalanceTotals($visibleAccounts),
            'summary' => $summary,
            'summaryDetails' => $summaryDetails,
            'transactions' => $displayTransactionRows,
            'transactionBalanceContext' => $transactionBalanceContext,
            'filters' => $filters,
            'periodValue' => sprintf('%04d-%02d', $filters['year'], $filters['month']),
            'monthLabel' => __(':year年:month月', ['year' => $filters['year'], 'month' => $filters['month']]),
            'tabContextLabel' => $this->tabContextLabel($filters['tab']),
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

    /**
     * サマリーカード詳細用データ（実効日・タブ条件は buildMonthSummary と揃える）
     *
     * @param Collection<int, FinanceTransaction> $transactions
     * @return array{
     *   currency: string,
     *   income: array{total: float, items: list<array<string, mixed>>},
     *   expense: array{total: float, items: list<array<string, mixed>>, categories: list<array{slug: string, label: string, total: float, count: int}>},
     *   transferOut: array{total: float, items: list<array<string, mixed>>},
     *   transferIn: array{total: float, items: list<array<string, mixed>>},
     *   net: array{total: float, income: float, expense: float, items: list<array<string, mixed>>}
     * }
     */
    public function buildSummaryDetails(Collection $transactions, string $tab): array
    {
        $currency = $tab === 'ph' ? 'PHP' : 'JPY';
        if ($tab === 'all' || $tab === 'transfer') {
            $currency = 'JPY';
        }

        $labels = $this->expenseCategoryLabels();
        $incomeItems = [];
        $expenseItems = [];
        $transferOutItems = [];
        $transferInItems = [];
        $incomeTotal = 0.0;
        $expenseTotal = 0.0;
        $transferOutTotal = 0.0;
        $transferInTotal = 0.0;
        /** @var array<string, array{slug: string, label: string, total: float, count: int}> $expenseCategories */
        $expenseCategories = [];

        $sorted = $transactions
            ->sortByDesc(fn (FinanceTransaction $t) => $t->transaction_date->format('Y-m-d').'-'.$t->id)
            ->values();

        foreach ($sorted as $transaction) {
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
                    $amount = (float) $transaction->amount;
                    $incomeTotal += $amount;
                    $incomeItems[] = $this->summaryDetailItem($transaction, $amount, $labels);
                }
            } elseif ($transaction->type === 'expense') {
                if ($tab === 'all' || $transaction->account?->currency === $currency || $tab === $transaction->account?->region) {
                    $amount = (float) $transaction->amount;
                    $expenseTotal += $amount;
                    $item = $this->summaryDetailItem($transaction, $amount, $labels);
                    $expenseItems[] = $item;
                    $slug = $item['category'] ?? '';
                    $label = $item['categoryLabel'] ?: __('未分類');
                    if (! isset($expenseCategories[$slug])) {
                        $expenseCategories[$slug] = [
                            'slug' => $slug,
                            'label' => $label,
                            'total' => 0.0,
                            'count' => 0,
                        ];
                    }
                    $expenseCategories[$slug]['total'] += $amount;
                    $expenseCategories[$slug]['count']++;
                }
            } elseif ($transaction->type === 'transfer') {
                if ($tab === 'ph') {
                    if ($transaction->account?->region === 'ph') {
                        $amount = (float) $transaction->amount;
                        $transferOutTotal += $amount;
                        $transferOutItems[] = $this->summaryDetailItem($transaction, $amount, $labels, 'transferOut');
                    }
                    if ($transaction->toAccount?->region === 'ph') {
                        $amount = (float) ($transaction->to_amount ?? $transaction->amount);
                        $transferInTotal += $amount;
                        $transferInItems[] = $this->summaryDetailItem($transaction, $amount, $labels, 'transferIn');
                    }
                } elseif ($tab === 'jp') {
                    if ($transaction->account?->region === 'jp') {
                        $amount = (float) $transaction->amount;
                        $transferOutTotal += $amount;
                        $transferOutItems[] = $this->summaryDetailItem($transaction, $amount, $labels, 'transferOut');
                    }
                    if ($transaction->toAccount?->region === 'jp') {
                        $amount = (float) ($transaction->to_amount ?? $transaction->amount);
                        $transferInTotal += $amount;
                        $transferInItems[] = $this->summaryDetailItem($transaction, $amount, $labels, 'transferIn');
                    }
                } else {
                    $outAmount = (float) $transaction->amount;
                    $inAmount = (float) ($transaction->to_amount ?? $transaction->amount);
                    $transferOutTotal += $outAmount;
                    $transferInTotal += $inAmount;
                    $transferOutItems[] = $this->summaryDetailItem($transaction, $outAmount, $labels, 'transferOut');
                    $transferInItems[] = $this->summaryDetailItem($transaction, $inAmount, $labels, 'transferIn');
                }
            }
        }

        $categoryRows = array_values($expenseCategories);
        usort($categoryRows, function (array $a, array $b) {
            if ($a['slug'] === '' && $b['slug'] !== '') {
                return 1;
            }
            if ($b['slug'] === '' && $a['slug'] !== '') {
                return -1;
            }

            return $b['total'] <=> $a['total'];
        });
        foreach ($categoryRows as &$row) {
            $row['total'] = round($row['total'], 2);
        }
        unset($row);

        $netItems = array_merge($incomeItems, $expenseItems);
        usort(
            $netItems,
            fn (array $a, array $b) => ($b['transactionDate'] <=> $a['transactionDate']) ?: (($b['id'] ?? 0) <=> ($a['id'] ?? 0))
        );

        return [
            'currency' => $currency,
            'income' => [
                'total' => round($incomeTotal, 2),
                'items' => $incomeItems,
            ],
            'expense' => [
                'total' => round($expenseTotal, 2),
                'items' => $expenseItems,
                'categories' => $categoryRows,
            ],
            'transferOut' => [
                'total' => round($transferOutTotal, 2),
                'items' => $transferOutItems,
            ],
            'transferIn' => [
                'total' => round($transferInTotal, 2),
                'items' => $transferInItems,
            ],
            'net' => [
                'total' => round($incomeTotal - $expenseTotal, 2),
                'income' => round($incomeTotal, 2),
                'expense' => round($expenseTotal, 2),
                'items' => $netItems,
            ],
        ];
    }

    /**
     * @param array<string, string> $categoryLabels
     * @return array<string, mixed>
     */
    private function summaryDetailItem(
        FinanceTransaction $transaction,
        float $amount,
        array $categoryLabels,
        ?string $transferSide = null,
    ): array {
        $category = $transaction->category;
        $categoryLabel = $category
            ? ($categoryLabels[$category] ?? $category)
            : ($transaction->type === 'expense' ? __('未分類') : null);

        return [
            'id' => $transaction->id,
            'transactionDate' => $transaction->transaction_date->format('Y-m-d'),
            'type' => $transaction->type,
            'typeLabel' => __(self::TYPE_LABELS[$transaction->type] ?? $transaction->type),
            'transferSide' => $transferSide,
            'category' => $category ?? '',
            'categoryLabel' => $categoryLabel,
            'accountName' => $transaction->account?->name,
            'toAccountName' => $transaction->toAccount?->name,
            'memo' => $this->formatDisplayMemo($transaction->memo, $transaction->account?->name),
            'amount' => round($amount, 2),
            'currency' => $transferSide === 'transferIn'
                ? ($transaction->to_currency ?: $transaction->currency)
                : $transaction->currency,
        ];
    }

    /** @param Collection<int, FinanceTransaction> $transactions */
    public function filterEffectiveTransactions(Collection $transactions): Collection
    {
        $today = $this->todayIso();

        return $transactions->filter(
            fn (FinanceTransaction $transaction) => $transaction->transaction_date->format('Y-m-d') <= $today
        );
    }

    public function isTransactionEffective(FinanceTransaction $transaction, ?string $today = null): bool
    {
        $today ??= $this->todayIso();

        return $transaction->transaction_date->format('Y-m-d') <= $today;
    }

    public function isScheduleMaterialized(int $scheduleId): bool
    {
        return $this->transactionsQuery()
            ->where('memo', 'like', '%'.$this->scheduleMarker($scheduleId).'%')
            ->exists();
    }

    public function nextPaymentScheduleDate(int $accountId): ?string
    {
        $schedule = $this->schedulesQuery()
            ->where('account_id', $accountId)
            ->where('schedule_type', 'payment')
            ->whereDate('scheduled_date', '>=', $this->todayIso())
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->first();

        return $schedule?->scheduled_date->format('Y-m-d');
    }

    /**
     * @param list<array<string, mixed>> $accounts
     * @param Collection<int, FinanceTransaction> $allTransactions
     * @return array{outstanding: array<int, list<int>>, paymentDates: array<int, ?string>}
     */
    public function buildTransactionDisplayContext(array $accounts, Collection $allTransactions): array
    {
        $effective = $this->filterEffectiveTransactions($allTransactions);
        $context = ['outstanding' => [], 'paymentDates' => []];

        foreach ($accounts as $accountRow) {
            if (($accountRow['kind'] ?? '') !== 'credit_card') {
                continue;
            }

            $accountId = (int) $accountRow['id'];
            $model = $this->accountsQuery()->find($accountId);
            if (! $model) {
                continue;
            }

            $outstanding = $this->buildCreditCardOutstandingCharges($model, $effective);
            $context['outstanding'][$accountId] = array_values(array_filter(
                array_map(fn (array $item) => $item['id'], $outstanding),
                fn (int $id) => $id > 0
            ));
            $context['paymentDates'][$accountId] = $this->nextPaymentScheduleDate($accountId);
        }

        return $context;
    }

    /** @return array<string, mixed> */
    public function scheduleToDisplayTransactionArray(FinanceAccountSchedule $schedule): array
    {
        $account = $schedule->account;
        $date = $schedule->scheduled_date->format('Y-m-d');
        $type = $schedule->schedule_type === 'deposit' ? 'income' : 'expense';

        return [
            'id' => null,
            'scheduleId' => $schedule->id,
            'isScheduleOnly' => true,
            'isScheduled' => true,
            'scheduledLabel' => self::SCHEDULE_TYPE_LABELS[$schedule->schedule_type] ?? '予定',
            'transactionDate' => $date,
            'displayDate' => $date,
            'purchaseDate' => null,
            'type' => $type,
            'typeLabel' => self::TYPE_LABELS[$type] ?? $type,
            'category' => null,
            'categoryLabel' => null,
            'accountId' => $schedule->account_id,
            'accountName' => $account?->name,
            'accountRegion' => $account?->region,
            'toAccountId' => null,
            'toAccountName' => null,
            'toAccountRegion' => null,
            'amount' => (float) $schedule->amount,
            'toAmount' => null,
            'currency' => $account?->currency ?? 'JPY',
            'toCurrency' => null,
            'memo' => $schedule->memo ?? '',
            'displayMemo' => trim((string) ($schedule->memo ?? '')),
            'isCrossRegion' => false,
        ];
    }

    /**
     * @param Collection<int, FinanceTransaction> $monthTransactions
     * @param Collection<int, FinanceAccountSchedule> $allSchedules
     * @param array{tab?: string, accountId?: ?int, day?: ?int, year?: int, month?: int} $filters
     * @param Collection<int, FinanceTransaction> $allTransactions
     * @param list<array<string, mixed>> $accounts
     * @return list<array<string, mixed>>
     */
    public function buildDisplayTransactionsForPage(
        Collection $monthTransactions,
        Collection $allSchedules,
        array $filters,
        Collection $allTransactions,
        array $accounts,
        string $monthStart,
        string $monthEnd,
    ): array {
        $tab = $filters['tab'] ?? 'all';
        $accountId = $filters['accountId'] ?? null;
        $day = $filters['day'] ?? null;
        $displayContext = $this->buildTransactionDisplayContext($accounts, $allTransactions);

        $scopedTransactions = $monthTransactions;
        if ($day !== null && isset($filters['year'], $filters['month'])) {
            $dayIso = sprintf('%04d-%02d-%02d', $filters['year'], $filters['month'], $day);
            $scopedTransactions = $monthTransactions->filter(
                fn (FinanceTransaction $t) => $t->transaction_date->format('Y-m-d') === $dayIso
            );
        }

        $rows = $this->filterTransactionsForTab($scopedTransactions, $tab, $accountId)
            ->map(fn (FinanceTransaction $transaction) => $this->transactionToArray($transaction, $displayContext))
            ->values()
            ->all();

        $monthSchedules = $allSchedules->filter(function (FinanceAccountSchedule $schedule) use ($monthStart, $monthEnd, $day, $filters) {
            $date = $schedule->scheduled_date->format('Y-m-d');
            if ($date < $monthStart || $date > $monthEnd) {
                return false;
            }
            if ($day !== null && isset($filters['year'], $filters['month'])) {
                return $date === sprintf('%04d-%02d-%02d', $filters['year'], $filters['month'], $day);
            }

            return true;
        });

        foreach ($this->filterSchedulesForTab($monthSchedules, $tab, $accountId) as $schedule) {
            if ($schedule->schedule_type !== 'deposit' || $this->isScheduleMaterialized($schedule->id)) {
                continue;
            }

            $rows[] = $this->scheduleToDisplayTransactionArray($schedule);
        }

        $existingIds = collect($rows)->pluck('id')->filter()->values()->all();
        $extraCreditCardRows = $allTransactions->filter(function (FinanceTransaction $transaction) use (
            $existingIds,
            $displayContext,
            $monthStart,
            $monthEnd,
            $tab,
            $accountId,
            $day,
            $filters
        ) {
            if ($transaction->account?->kind !== 'credit_card' || $transaction->type !== 'expense') {
                return false;
            }

            if (in_array($transaction->id, $existingIds, true)) {
                return false;
            }

            $cardAccountId = (int) $transaction->account_id;
            if (! in_array($transaction->id, $displayContext['outstanding'][$cardAccountId] ?? [], true)) {
                return false;
            }

            $paymentDate = $displayContext['paymentDates'][$cardAccountId] ?? null;
            if (! $paymentDate || $paymentDate < $monthStart || $paymentDate > $monthEnd) {
                return false;
            }

            if ($day !== null && isset($filters['year'], $filters['month'])) {
                if ($paymentDate !== sprintf('%04d-%02d-%02d', $filters['year'], $filters['month'], $day)) {
                    return false;
                }
            }

            $txDate = $transaction->transaction_date->format('Y-m-d');
            if ($txDate >= $monthStart && $txDate <= $monthEnd) {
                return false;
            }

            return $this->filterTransactionsForTab(collect([$transaction]), $tab, $accountId)->isNotEmpty();
        });

        foreach ($extraCreditCardRows as $transaction) {
            $rows[] = $this->transactionToArray($transaction, $displayContext);
        }

        usort($rows, function (array $a, array $b) {
            $dateCompare = strcmp($b['displayDate'] ?? $b['transactionDate'], $a['displayDate'] ?? $a['transactionDate']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ($b['id'] ?? $b['scheduleId'] ?? 0) <=> ($a['id'] ?? $a['scheduleId'] ?? 0);
        });

        return $this->attachBalancesToDisplayRows($rows, $allTransactions, $accountId, $tab, $accounts);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $accountRows
     * @return list<array<string, mixed>>
     */
    public function attachBalancesToDisplayRows(
        array $rows,
        Collection $allTransactions,
        ?int $filterAccountId,
        string $tab = 'all',
        array $accountRows = [],
    ): array {
        if ($rows === []) {
            return $rows;
        }

        $effective = $this->filterEffectiveTransactions($allTransactions);
        $accounts = $this->accountsQuery()->where('is_active', true)->get()->keyBy('id');
        $today = $this->todayIso();

        $visibleAccountIds = [];
        $region = in_array($tab, ['jp', 'ph'], true) ? $tab : null;
        foreach ($accountRows !== [] ? $accountRows : $accounts->map(fn (FinanceAccount $a) => [
            'id' => $a->id,
            'region' => $a->region,
            'kind' => $a->kind,
            'currency' => $a->currency,
        ])->all() as $accountRow) {
            if ($region !== null && ($accountRow['region'] ?? null) !== $region) {
                continue;
            }
            $visibleAccountIds[] = (int) $accountRow['id'];
        }
        $visibleAccountIds = array_values(array_unique($visibleAccountIds));

        $sorted = $effective
            ->filter(fn (FinanceTransaction $t) => $t->transaction_date->format('Y-m-d') <= $today)
            ->sortBy(fn (FinanceTransaction $t) => sprintf(
                '%s-%010d',
                $t->transaction_date->format('Y-m-d'),
                $t->id
            ))
            ->values();

        /** @var array<int, float> $balances */
        $balances = [];
        /** @var array<int, float> $cardPayments */
        $cardPayments = [];
        foreach ($visibleAccountIds as $id) {
            if (! $accounts->has($id)) {
                continue;
            }
            $account = $accounts->get($id);
            $balances[$id] = $this->accountConfiguredStartingBalance($account);
            if ($account->kind === 'credit_card') {
                $cardPayments[$id] = 0.0;
            }
        }

        /** @var array<int, array{total: float, bank: float, cash: float, cardPayment: float, cardRemaining: float, currency: string}> $portfolioByTx */
        $portfolioByTx = [];
        /** @var array<int, array<int, float>> $maps */
        $maps = [];

        foreach ($sorted as $transaction) {
            $txId = (int) $transaction->id;
            foreach (array_keys($balances) as $accountId) {
                if (! $this->transactionAffectsAccount($transaction, $accountId)) {
                    continue;
                }
                $account = $accounts->get($accountId);
                $balances[$accountId] = round(
                    $this->applyTransactionEffectForAccount($account, $balances[$accountId], $transaction, $accountId),
                    2
                );
                $maps[$accountId][$txId] = $balances[$accountId];

                if ($account->kind === 'credit_card') {
                    if ((int) $transaction->account_id === $accountId) {
                        if ($transaction->type === 'income') {
                            $cardPayments[$accountId] = round($cardPayments[$accountId] + (float) $transaction->amount, 2);
                        } elseif ($transaction->type === 'transfer') {
                            $cardPayments[$accountId] = round($cardPayments[$accountId] + (float) $transaction->amount, 2);
                        }
                    }
                    if ($transaction->type === 'transfer' && (int) $transaction->to_account_id === $accountId) {
                        $cardPayments[$accountId] = round(
                            $cardPayments[$accountId] + (float) ($transaction->to_amount ?? $transaction->amount),
                            2
                        );
                    }
                }
            }

            $bank = 0.0;
            $cash = 0.0;
            $wallet = 0.0;
            $cardRemaining = 0.0;
            $cardPayment = 0.0;
            $currency = 'JPY';
            foreach ($visibleAccountIds as $accountId) {
                if (! $accounts->has($accountId)) {
                    continue;
                }
                $account = $accounts->get($accountId);
                $balance = $balances[$accountId] ?? 0.0;
                $currency = $account->currency;
                if ($account->kind === 'bank') {
                    $bank += $balance;
                } elseif ($account->kind === 'cash') {
                    $cash += $balance;
                } elseif ($account->kind === 'wallet') {
                    $wallet += $balance;
                } elseif ($account->kind === 'credit_card') {
                    $cardRemaining += max(0, $balance);
                    $cardPayment += $cardPayments[$accountId] ?? 0.0;
                }
            }

            $portfolioByTx[$txId] = [
                'total' => round($bank + $cash + $wallet, 2),
                'bank' => round($bank, 2),
                'cash' => round($cash, 2),
                'cardPayment' => round($cardPayment, 2),
                'cardRemaining' => round($cardRemaining, 2),
                'currency' => $currency,
            ];
        }

        foreach ($rows as &$row) {
            $row['balanceAfter'] = null;
            $row['balanceCurrency'] = null;
            $row['portfolioTotal'] = null;
            $row['portfolioBank'] = null;
            $row['portfolioCash'] = null;
            $row['portfolioCardPayment'] = null;
            $row['portfolioCardRemaining'] = null;
            $row['portfolioCurrency'] = null;

            if (! empty($row['isScheduleOnly']) || empty($row['id'])) {
                continue;
            }

            $transactionDate = (string) ($row['transactionDate'] ?? '');
            if ($transactionDate > $today) {
                continue;
            }

            $transactionId = (int) $row['id'];
            if (isset($portfolioByTx[$transactionId])) {
                $snap = $portfolioByTx[$transactionId];
                $row['portfolioTotal'] = $snap['total'];
                $row['portfolioBank'] = $snap['bank'];
                $row['portfolioCash'] = $snap['cash'];
                $row['portfolioCardPayment'] = $snap['cardPayment'];
                $row['portfolioCardRemaining'] = $snap['cardRemaining'];
                $row['portfolioCurrency'] = $snap['currency'];
                $row['balanceCurrency'] = $snap['currency'];
            }

            $balanceAccountId = $filterAccountId ?? (int) ($row['accountId'] ?? 0);
            if ($balanceAccountId > 0 && isset($maps[$balanceAccountId][$transactionId])) {
                $row['balanceAfter'] = $maps[$balanceAccountId][$transactionId];
                if ($accounts->has($balanceAccountId)) {
                    $row['balanceCurrency'] = $accounts->get($balanceAccountId)->currency;
                }
            }
        }
        unset($row);

        return $rows;
    }

    /** @return array<int, float> */
    public function buildBalanceAfterMapForAccount(FinanceAccount $account, Collection $transactions, int $accountId): array
    {
        $sorted = $transactions
            ->filter(fn (FinanceTransaction $transaction) => $this->transactionAffectsAccount($transaction, $accountId))
            ->sortBy(fn (FinanceTransaction $transaction) => sprintf(
                '%s-%010d',
                $transaction->transaction_date->format('Y-m-d'),
                $transaction->id
            ))
            ->values();

        $balance = $this->accountConfiguredStartingBalance($account);
        $map = [];

        foreach ($sorted as $transaction) {
            $balance = round(
                $this->applyTransactionEffectForAccount($account, $balance, $transaction, $accountId),
                2
            );
            $map[(int) $transaction->id] = $balance;
        }

        return $map;
    }

    public function calculateAccountBalanceUpToDate(
        FinanceAccount $account,
        Collection $allTransactions,
        int $accountId,
        string $exclusiveEndDate,
    ): float {
        $effective = $this->filterEffectiveTransactions($allTransactions);
        $sorted = $effective
            ->filter(function (FinanceTransaction $transaction) use ($accountId, $exclusiveEndDate) {
                return $this->transactionAffectsAccount($transaction, $accountId)
                    && $transaction->transaction_date->format('Y-m-d') < $exclusiveEndDate;
            })
            ->sortBy(fn (FinanceTransaction $transaction) => sprintf(
                '%s-%010d',
                $transaction->transaction_date->format('Y-m-d'),
                $transaction->id
            ))
            ->values();

        $balance = $this->accountConfiguredStartingBalance($account);
        foreach ($sorted as $transaction) {
            $balance = round(
                $this->applyTransactionEffectForAccount($account, $balance, $transaction, $accountId),
                2
            );
        }

        return $balance;
    }

    /** @param list<array<string, mixed>> $accounts */
    /** @return ?array{accountId: int, accountName: string, kindLabel: string, currency: string, openingBalance: float, currentBalance: float} */
    public function buildTransactionBalanceContext(
        array $accounts,
        Collection $allTransactions,
        ?int $accountId,
        string $monthStart,
    ): ?array {
        if ($accountId === null) {
            return null;
        }

        $accountRow = collect($accounts)->firstWhere('id', $accountId);
        if ($accountRow === null) {
            return null;
        }

        $account = $this->accountsQuery()->find($accountId);
        if ($account === null) {
            return null;
        }

        return [
            'accountId' => $accountId,
            'accountName' => (string) $accountRow['name'],
            'kindLabel' => (string) ($accountRow['kindLabel'] ?? ''),
            'currency' => (string) $accountRow['currency'],
            'openingBalance' => $this->calculateAccountBalanceUpToDate($account, $allTransactions, $accountId, $monthStart),
            'currentBalance' => (float) $accountRow['balance'],
        ];
    }

    private function accountConfiguredStartingBalance(FinanceAccount $account): float
    {
        if ($account->kind === 'credit_card') {
            return $this->creditCardConfiguredBalance($account);
        }

        return round((float) $account->initial_balance + (float) ($account->adjustment_amount ?? 0), 2);
    }

    private function transactionAffectsAccount(FinanceTransaction $transaction, int $accountId): bool
    {
        return (int) $transaction->account_id === $accountId
            || ($transaction->type === 'transfer' && (int) $transaction->to_account_id === $accountId);
    }

    private function applyTransactionEffectForAccount(
        FinanceAccount $account,
        float $balance,
        FinanceTransaction $transaction,
        int $accountId,
    ): float {
        if ($account->kind === 'credit_card') {
            if ((int) $transaction->account_id === $accountId) {
                if ($transaction->type === 'expense') {
                    $balance += (float) $transaction->amount;
                } elseif ($transaction->type === 'income') {
                    $balance -= (float) $transaction->amount;
                } elseif ($transaction->type === 'transfer') {
                    $balance -= (float) $transaction->amount;
                }
            }

            if ($transaction->type === 'transfer' && (int) $transaction->to_account_id === $accountId) {
                $balance -= (float) ($transaction->to_amount ?? $transaction->amount);
            }

            return max(0, $balance);
        }

        if ((int) $transaction->account_id === $accountId) {
            if ($transaction->type === 'income') {
                $balance += (float) $transaction->amount;
            } elseif ($transaction->type === 'expense') {
                $balance -= (float) $transaction->amount;
            } elseif ($transaction->type === 'transfer') {
                $balance -= (float) $transaction->amount;
            }
        }

        if ($transaction->type === 'transfer' && (int) $transaction->to_account_id === $accountId) {
            $balance += (float) ($transaction->to_amount ?? $transaction->amount);
        }

        return $balance;
    }

    /** @param Collection<int, FinanceAccountSchedule> $schedules */
    public function filterSchedulesForTab(Collection $schedules, string $tab, ?int $accountId): Collection
    {
        return $schedules->filter(function (FinanceAccountSchedule $schedule) use ($tab, $accountId) {
            if ($accountId !== null) {
                return (int) $schedule->account_id === $accountId;
            }

            if ($tab === 'transfer' || $tab === 'all') {
                return true;
            }

            return $schedule->account?->region === $tab;
        })->values();
    }

    /** @return array<string, mixed> */
    public function transactionToArray(FinanceTransaction $transaction, ?array $displayContext = null): array
    {
        $txDate = $transaction->transaction_date->format('Y-m-d');
        $today = $this->todayIso();
        $scheduleId = $this->extractScheduleIdFromMemo($transaction->memo);

        $row = [
            'id' => $transaction->id,
            'transactionDate' => $txDate,
            'displayDate' => $txDate,
            'purchaseDate' => null,
            'isScheduled' => false,
            'isScheduleOnly' => false,
            'scheduledLabel' => null,
            'type' => $transaction->type,
            'typeLabel' => self::TYPE_LABELS[$transaction->type] ?? $transaction->type,
            'category' => $transaction->category,
            'categoryLabel' => $transaction->category
                ? ($this->expenseCategoryLabels()[$transaction->category] ?? $transaction->category)
                : null,
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
            'displayMemo' => $this->formatDisplayMemo($transaction->memo, $transaction->account?->name),
            'isCrossRegion' => $transaction->type === 'transfer'
                && $transaction->account?->region !== $transaction->toAccount?->region,
            'scheduleId' => $scheduleId,
        ];

        if ($scheduleId === null && $txDate > $today) {
            $row['isScheduled'] = true;
            $row['scheduledLabel'] = '予定';
            $row['displayDate'] = $txDate;
        }

        $accountId = (int) $transaction->account_id;
        if (
            $displayContext
            && $transaction->account?->kind === 'credit_card'
            && $transaction->type === 'expense'
            && in_array($transaction->id, $displayContext['outstanding'][$accountId] ?? [], true)
        ) {
            $paymentDate = $displayContext['paymentDates'][$accountId] ?? null;
            if ($paymentDate) {
                $row['isScheduled'] = true;
                $row['scheduledLabel'] = '予定支払';
                $row['displayDate'] = $paymentDate;
                if ($txDate !== $paymentDate) {
                    $row['purchaseDate'] = $txDate;
                }
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $payload */
    public function createTransaction(array $payload): FinanceTransaction
    {
        $type = $this->normalizeType($payload['type'] ?? null);
        $account = $this->requireOwnedAccount((int) $payload['accountId']);
        $amount = round(max(0, (float) ($payload['amount'] ?? 0)), 2);
        $date = $this->normalizeDate($payload['transactionDate'] ?? null);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('金額は 0 より大きい値を入力してください');
        }

        if ($type === 'transfer') {
            $toAccount = $this->requireOwnedAccount((int) ($payload['toAccountId'] ?? 0));
            $toAmount = isset($payload['toAmount']) && $payload['toAmount'] !== ''
                ? round(max(0, (float) $payload['toAmount']), 2)
                : $amount;

            if ($toAmount <= 0) {
                throw new \InvalidArgumentException('入金側の金額は 0 より大きい値を入力してください');
            }

            return $this->transactionsQuery()->create([
                'user_id' => $this->requireUserId(),
                'transaction_date' => $date,
                'type' => 'transfer',
                'account_id' => $account->id,
                'to_account_id' => $toAccount->id,
                'amount' => $amount,
                'to_amount' => $toAmount,
                'currency' => $account->currency,
                'to_currency' => $toAccount->currency,
                'memo' => trim((string) ($payload['memo'] ?? '')),
                'category' => null,
            ]);
        }

        return $this->transactionsQuery()->create([
            'user_id' => $this->requireUserId(),
            'transaction_date' => $date,
            'type' => $type,
            'account_id' => $account->id,
            'amount' => $amount,
            'currency' => $account->currency,
            'memo' => trim((string) ($payload['memo'] ?? '')),
            'category' => $this->normalizeExpenseCategory($payload['category'] ?? null, $type),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function updateTransaction(int $id, array $payload): bool
    {
        $transaction = $this->transactionsQuery()->find($id);
        if (! $transaction) {
            return false;
        }

        $type = $this->normalizeType($payload['type'] ?? $transaction->type);
        $account = $this->requireOwnedAccount((int) ($payload['accountId'] ?? $transaction->account_id));
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
            'category' => $this->normalizeExpenseCategory(
                array_key_exists('category', $payload) ? $payload['category'] : $transaction->category,
                $type
            ),
            'to_account_id' => null,
            'to_amount' => null,
            'to_currency' => null,
        ];

        if ($type === 'transfer') {
            $toAccount = $this->requireOwnedAccount((int) ($payload['toAccountId'] ?? $transaction->to_account_id));
            $toAmount = isset($payload['toAmount']) && $payload['toAmount'] !== ''
                ? round(max(0, (float) $payload['toAmount']), 2)
                : $amount;
            $data['to_account_id'] = $toAccount->id;
            $data['to_amount'] = $toAmount;
            $data['to_currency'] = $toAccount->currency;
        }

        return $transaction->update($data);
    }

    public function scheduleMarker(int $scheduleId): string
    {
        return '[schedule:'.$scheduleId.']';
    }

    public function extractScheduleIdFromMemo(?string $memo): ?int
    {
        if (! is_string($memo) || ! preg_match('/\[schedule:(\d+)\]/', $memo, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    public function formatDisplayMemo(?string $memo, ?string $accountName = null): string
    {
        if (! is_string($memo) || trim($memo) === '') {
            return '';
        }

        $clean = trim(preg_replace('/\s*\[schedule:\d+\]\s*/', '', $memo) ?? '');
        if ($clean === '') {
            return '';
        }

        if (preg_match('/^(入金予定|カード引落):\s*(.+)$/u', $clean, $matches)) {
            return trim($matches[2]);
        }

        $marker = FinanceCsvService::IMPORT_MEMO_MARKER;
        $fromBudgetCsv = str_starts_with($clean, $marker);
        if ($fromBudgetCsv) {
            $clean = trim(mb_substr($clean, mb_strlen($marker)));
        }

        if ($accountName !== null && $accountName !== '') {
            foreach (['カード利用 '.$accountName, '残高 '.$accountName] as $prefix) {
                if (str_starts_with($clean, $prefix)) {
                    $clean = trim(mb_substr($clean, mb_strlen($prefix)));
                    break;
                }
            }
        } elseif ($fromBudgetCsv) {
            if (preg_match('/^(カード利用|残高)\s+/u', $clean)) {
                return '';
            }
        }

        if ($fromBudgetCsv) {
            $flowOnly = ['IN', 'OUT', 'PH Bank In', 'PH Bank Out', '送金'];
            if (in_array($clean, $flowOnly, true)) {
                return '';
            }
            if (preg_match('/^(IN|OUT|PH Bank In|PH Bank Out|送金)\s+(.+)$/u', $clean, $matches)) {
                return trim($matches[2]);
            }
        }

        return $clean;
    }

    public function deleteMaterializedScheduleTransactions(int $scheduleId): void
    {
        $marker = $this->scheduleMarker($scheduleId);
        $this->transactionsQuery()->where('memo', 'like', '%'.$marker.'%')->delete();
    }

    public function deleteTransaction(int $id): bool
    {
        $transaction = $this->transactionsQuery()->find($id);
        if (! $transaction) {
            return false;
        }

        $scheduleId = $this->extractScheduleIdFromMemo($transaction->memo);
        $deleted = (bool) $transaction->delete();

        if ($deleted && $scheduleId !== null) {
            $this->schedulesQuery()->whereKey($scheduleId)->delete();
        }

        return $deleted;
    }

    /** @param list<int> $ids */
    public function bulkDeleteTransactions(array $ids): int
    {
        $count = 0;
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            if ($id > 0 && $this->deleteTransaction($id)) {
                $count++;
            }
        }

        return $count;
    }

    public function updateAccountInitialBalance(int $id, float $balance, ?float $adjustmentAmount = null): bool
    {
        $account = $this->accountsQuery()->find($id);
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
        $account = $this->accountsQuery()->find($accountId);
        if (! $account || $account->kind !== 'credit_card') {
            return false;
        }

        if ($linkedBankId !== null) {
            $bank = $this->accountsQuery()->find($linkedBankId);
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

        $maxOrder = (int) $this->accountsQuery()->max('sort_order');

        return $this->accountsQuery()->create([
            'user_id' => $this->requireUserId(),
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
            'show_in_overview' => (bool) ($payload['showInOverview'] ?? false),
        ]);
    }

    public function setAccountOverviewVisibility(int $id, bool $show): bool
    {
        $account = $this->accountsQuery()->where('is_active', true)->find($id);
        if (! $account) {
            return false;
        }

        $account->show_in_overview = $show;

        return $account->save();
    }

    /** @param array<string, mixed> $payload */
    public function updateAccount(int $id, array $payload): bool
    {
        $account = $this->accountsQuery()->where('is_active', true)->find($id);
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
        $account = $this->accountsQuery()->where('is_active', true)->find($id);
        if (! $account) {
            return false;
        }

        $this->accountsQuery()
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

        $accounts = $this->accountsQuery()
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
        $bank = $this->accountsQuery()->where('is_active', true)->find($linkedBankId);
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
        while ($this->accountsQuery()->where('slug', $slug)->exists()) {
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
            ? __(self::SCHEDULE_TYPE_LABELS[$accountRow['scheduleType']] ?? '')
            : '';
        $accountRow['schedules'] = $scheduleItems;
        $today = $this->todayIso();
        $accountRow['nextSchedule'] = collect($scheduleItems)
            ->first(fn (array $schedule) => ($schedule['scheduledDate'] ?? '') >= $today);

        return $accountRow;
    }

    /** @param array<string, mixed> $payload */
    public function createSchedule(int $accountId, array $payload): FinanceAccountSchedule
    {
        $account = $this->accountsQuery()->where('is_active', true)->find($accountId);
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

        return $this->schedulesQuery()->create([
            'account_id' => $account->id,
            'schedule_type' => $scheduleType,
            'scheduled_date' => $this->normalizeDate($payload['scheduledDate'] ?? null),
            'amount' => $amount,
            'memo' => trim((string) ($payload['memo'] ?? '')),
        ]);
    }

    public function deleteSchedule(int $id): bool
    {
        $schedule = $this->schedulesQuery()->find($id);
        if (! $schedule) {
            return false;
        }

        $this->deleteMaterializedScheduleTransactions($id);

        return (bool) $schedule->delete();
    }

    /** @param array<string, mixed> $payload */
    public function updateSchedule(int $id, array $payload): ?FinanceAccountSchedule
    {
        $schedule = $this->schedulesQuery()->with('account')->find($id);
        if (! $schedule) {
            return null;
        }

        $amount = round(max(0, (float) ($payload['amount'] ?? $schedule->amount)), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('金額は 0 より大きい値を入力してください');
        }

        $this->deleteMaterializedScheduleTransactions($id);

        $schedule->fill([
            'scheduled_date' => $this->normalizeDate($payload['scheduledDate'] ?? $schedule->scheduled_date->format('Y-m-d')),
            'amount' => $amount,
            'memo' => trim((string) ($payload['memo'] ?? $schedule->memo ?? '')),
        ]);
        $schedule->save();

        $this->materializeDueSchedules();

        return $schedule->fresh();
    }

    /** @param array<string, mixed> $payload */
    public function upsertNextSchedule(int $accountId, array $payload): FinanceAccountSchedule
    {
        $account = $this->accountsQuery()->where('is_active', true)->find($accountId);
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

        $existing = $this->schedulesQuery()
            ->where('account_id', $account->id)
            ->where('schedule_type', $scheduleType)
            ->where('scheduled_date', '>=', $this->todayIso())
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->first();

        if ($existing) {
            $this->deleteMaterializedScheduleTransactions($existing->id);
            $existing->fill($data);
            $existing->save();
            $this->materializeDueSchedules();

            return $existing;
        }

        $schedule = $this->schedulesQuery()->create([
            'account_id' => $account->id,
            'schedule_type' => $scheduleType,
            ...$data,
        ]);
        $this->materializeDueSchedules();

        return $schedule;
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

            if ($account['kind'] === 'credit_card') {
                $creditCards[$currency] = ($creditCards[$currency] ?? 0) + $balance;
                if (! empty($account['nextSchedule'])) {
                    $upcomingPayments[$currency] = ($upcomingPayments[$currency] ?? 0) + (float) $account['nextSchedule']['amount'];
                }
            } elseif (in_array($account['kind'], ['bank', 'cash', 'wallet'], true)) {
                $totals[$currency] = ($totals[$currency] ?? 0) + $balance;
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
