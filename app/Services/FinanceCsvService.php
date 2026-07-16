<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use Illuminate\Support\Collection;

class FinanceCsvService
{
    public const IMPORT_MEMO_MARKER = '[予算CSV]';

    public const FORMAT_TRANSACTIONS = 'transactions';

    public const FORMAT_BUDGET_MONITOR = 'budget_monitor';

    public const FORMAT_ACCOUNTS = 'accounts';

    /** @var array<string, string> */
    private const BUDGET_MONITOR_FLOW_COLUMNS = [
        'IN' => 'income:jp_bank_rakuten',
        'OUT' => 'expense:jp_bank_rakuten',
        'PH Bank In' => 'income:ph_bank_bpi',
        'PH Bank Out' => 'expense:ph_bank_bpi',
    ];

    /** @var array<int, string> */
    private const BUDGET_MONITOR_JP_BANK_BALANCE_COLUMNS = [
        5 => 'jp_bank_rakuten',
        6 => 'jp_bank_paypay',
        7 => 'jp_bank_seven',
        8 => 'jp_bank_smbc',
        9 => 'jp_cash_petty',
    ];

    /** @var array<int, string> */
    private const BUDGET_MONITOR_JP_CARD_BALANCE_COLUMNS = [
        10 => 'jp_card_rakuten',
        11 => 'jp_card_amazon',
        12 => 'jp_card_paypay',
        13 => 'jp_card_jal',
        14 => 'jp_card_seven',
        15 => 'jp_card_fami',
        16 => 'jp_card_epos',
        17 => 'jp_card_smbc_cl',
    ];

    /** @var array<int, string> */
    private const BUDGET_MONITOR_PH_CARD_BALANCE_COLUMNS = [
        24 => 'ph_card_bpi_mc',
        25 => 'ph_card_bankard_mc_gold',
    ];

    public function __construct(private FinanceService $finance) {}

    public function detectFormat(string $content): string
    {
        $content = $this->normalizeCsvEncoding($content);
        $firstLine = strtok($content, "\r\n");
        if (! is_string($firstLine)) {
            return self::FORMAT_TRANSACTIONS;
        }

        if ($this->looksLikeAccountMasterHeader($firstLine)) {
            return self::FORMAT_ACCOUNTS;
        }

        if (str_contains($firstLine, 'Balance') && str_contains($firstLine, 'PH Bank In')) {
            return self::FORMAT_BUDGET_MONITOR;
        }

        return self::FORMAT_TRANSACTIONS;
    }

    /**
     * @return array{format: string, created: int, updated: int, skipped: int, deleted: int, from: ?string, to: ?string, messages: list<string>}
     */
    public function import(string $content, array $options = []): array
    {
        $content = $this->normalizeCsvEncoding($content);
        $format = $options['format'] ?? $this->detectFormat($content);
        $replace = (bool) ($options['replace'] ?? false);
        $includeCardDeltas = (bool) ($options['includeCardDeltas'] ?? true);
        $updateExisting = (bool) ($options['updateExisting'] ?? true);

        if ($format === self::FORMAT_ACCOUNTS) {
            return $this->importAccounts($content, $updateExisting);
        }

        $this->finance->ensureDefaultAccounts();

        if ($format === self::FORMAT_BUDGET_MONITOR) {
            return $this->importBudgetMonitor($content, $replace, $includeCardDeltas);
        }

        return $this->importTransactions($content, $replace);
    }

    public function export(array $filters, string $format = self::FORMAT_TRANSACTIONS): string
    {
        if ($format === self::FORMAT_ACCOUNTS) {
            return $this->exportAccounts();
        }

        if ($format === self::FORMAT_BUDGET_MONITOR) {
            return $this->exportBudgetMonitor($filters);
        }

        return $this->exportTransactions($filters);
    }

    /**
     * @return array{format: string, created: int, skipped: int, deleted: int, from: ?string, to: ?string, messages: list<string>}
     */
    private function importBudgetMonitor(string $content, bool $replace, bool $includeCardDeltas): array
    {
        $rows = $this->parseCsvRows($content);
        if ($rows === []) {
            throw new \InvalidArgumentException('CSVが空です');
        }

        $header = array_shift($rows);
        $columnMap = $this->buildBudgetMonitorColumnMap($header);
        $accountsBySlug = FinanceAccount::query()->where('is_active', true)->get()->keyBy('slug');
        $previousBalances = [];
        $created = 0;
        $skipped = 0;
        $dates = [];

        if ($replace) {
            $deleted = FinanceTransaction::query()
                ->where('memo', 'like', '%'.self::IMPORT_MEMO_MARKER.'%')
                ->delete();
        } else {
            $deleted = 0;
        }

        foreach ($rows as $row) {
            $date = $this->parseBudgetMonitorDate($row[0] ?? null);
            if ($date === null) {
                $skipped++;

                continue;
            }

            $dates[] = $date;
            $comment = trim((string) ($row[$columnMap['Comment'] ?? 27] ?? ''));

            foreach (self::BUDGET_MONITOR_FLOW_COLUMNS as $headerLabel => $mapping) {
                $index = $columnMap[$headerLabel] ?? null;
                if ($index === null) {
                    continue;
                }

                $amount = $this->parseAmount($row[$index] ?? null);
                if ($amount <= 0) {
                    continue;
                }

                [$type, $slug] = explode(':', $mapping, 2);
                $account = $accountsBySlug->get($slug);
                if (! $account) {
                    $skipped++;

                    continue;
                }

                $memo = trim(self::IMPORT_MEMO_MARKER.' '.$headerLabel.($comment !== '' ? ' '.$comment : ''));
                if ($this->transactionExists($date, $type, $account->id, $amount, $memo)) {
                    $skipped++;

                    continue;
                }

                FinanceTransaction::query()->create([
                    'transaction_date' => $date,
                    'type' => $type,
                    'account_id' => $account->id,
                    'amount' => $amount,
                    'currency' => $account->currency,
                    'memo' => $memo,
                ]);
                $created++;
            }

            if ($includeCardDeltas) {
                foreach (self::BUDGET_MONITOR_JP_CARD_BALANCE_COLUMNS + self::BUDGET_MONITOR_PH_CARD_BALANCE_COLUMNS as $index => $slug) {
                    $current = $this->parseAmount($row[$index] ?? null);
                    $previous = $previousBalances[$index] ?? null;
                    $previousBalances[$index] = $current;

                    if ($previous === null || $current <= $previous) {
                        continue;
                    }

                    $delta = round($current - $previous, 2);
                    if ($delta <= 0) {
                        continue;
                    }

                    $account = $accountsBySlug->get($slug);
                    if (! $account) {
                        $skipped++;

                        continue;
                    }

                    $memo = trim(self::IMPORT_MEMO_MARKER.' カード利用'.($comment !== '' ? ' '.$comment : ''));
                    if ($this->transactionExists($date, 'expense', $account->id, $delta, $memo)) {
                        $skipped++;

                        continue;
                    }

                    FinanceTransaction::query()->create([
                        'transaction_date' => $date,
                        'type' => 'expense',
                        'account_id' => $account->id,
                        'amount' => $delta,
                        'currency' => $account->currency,
                        'memo' => $memo,
                    ]);
                    $created++;
                }
            }

            foreach (self::BUDGET_MONITOR_JP_BANK_BALANCE_COLUMNS as $index => $slug) {
                $previousBalances[$index] = $this->parseAmount($row[$index] ?? null);
            }
        }

        sort($dates);

        return [
            'format' => self::FORMAT_BUDGET_MONITOR,
            'created' => $created,
            'updated' => 0,
            'skipped' => $skipped,
            'deleted' => $deleted,
            'from' => $dates[0] ?? null,
            'to' => $dates !== [] ? $dates[array_key_last($dates)] : null,
            'messages' => [
                '予算監視CSVから入出金を展開しました（IN/OUT・PH Bank In/Out'.($includeCardDeltas ? '・クレカ増加' : '').'）。',
            ],
        ];
    }

    /**
     * @return array{format: string, created: int, skipped: int, deleted: int, from: ?string, to: ?string, messages: list<string>}
     */
    private function importTransactions(string $content, bool $replace): array
    {
        $rows = $this->parseCsvRows($content);
        if ($rows === []) {
            throw new \InvalidArgumentException('CSVが空です');
        }

        $header = array_map(fn ($value) => strtolower(trim((string) $value)), array_shift($rows) ?: []);
        $indexes = $this->mapTransactionHeaderIndexes($header);
        $accountsById = FinanceAccount::query()->where('is_active', true)->get()->keyBy('id');
        $accountsByName = FinanceAccount::query()->where('is_active', true)->get()->keyBy(fn (FinanceAccount $a) => mb_strtolower($a->name));
        $created = 0;
        $skipped = 0;
        $dates = [];

        if ($replace) {
            $deleted = FinanceTransaction::query()
                ->where('memo', 'like', '%'.self::IMPORT_MEMO_MARKER.'%')
                ->delete();
        } else {
            $deleted = 0;
        }

        foreach ($rows as $row) {
            $date = $this->normalizeImportDate($row[$indexes['date']] ?? null);
            $type = $this->normalizeImportType($row[$indexes['type']] ?? null);
            $amount = $this->parseAmount($row[$indexes['amount']] ?? null);
            if ($date === null || $amount <= 0) {
                $skipped++;

                continue;
            }

            $account = $this->resolveAccountFromRow($row, $indexes, $accountsById, $accountsByName);
            if (! $account) {
                $skipped++;

                continue;
            }

            $memoRaw = ($indexes['memo'] !== null)
                ? trim((string) ($row[$indexes['memo']] ?? ''))
                : '';
            $memo = trim(self::IMPORT_MEMO_MARKER.($memoRaw !== '' ? ' '.$memoRaw : ''));

            if ($this->transactionExists($date, $type, $account->id, $amount, $memo)) {
                $skipped++;

                continue;
            }

            FinanceTransaction::query()->create([
                'transaction_date' => $date,
                'type' => $type,
                'account_id' => $account->id,
                'amount' => $amount,
                'currency' => $account->currency,
                'memo' => $memo,
            ]);
            $created++;
            $dates[] = $date;
        }

        sort($dates);

        return [
            'format' => self::FORMAT_TRANSACTIONS,
            'created' => $created,
            'updated' => 0,
            'skipped' => $skipped,
            'deleted' => $deleted,
            'from' => $dates[0] ?? null,
            'to' => $dates !== [] ? $dates[array_key_last($dates)] : null,
            'messages' => ['取引CSVをインポートしました。'],
        ];
    }

    private function exportTransactions(array $filters): string
    {
        $filters = $this->finance->parseFilters($filters);
        $monthStart = sprintf('%04d-%02d-01', $filters['year'], $filters['month']);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $transactions = FinanceTransaction::query()
            ->with(['account', 'toAccount'])
            ->whereDate('transaction_date', '>=', $monthStart)
            ->whereDate('transaction_date', '<=', $monthEnd)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $lines = [['日付', '種別', '口座', '金額', '通貨', '振替先', '振替先金額', 'メモ']];
        foreach ($transactions as $transaction) {
            $lines[] = [
                $transaction->transaction_date->format('Y-m-d'),
                FinanceService::TYPE_LABELS[$transaction->type] ?? $transaction->type,
                $transaction->account?->name ?? '',
                (string) $transaction->amount,
                $transaction->currency,
                $transaction->toAccount?->name ?? '',
                $transaction->to_amount !== null ? (string) $transaction->to_amount : '',
                $transaction->memo ?? '',
            ];
        }

        return $this->toCsv($lines);
    }

    private function exportBudgetMonitor(array $filters): string
    {
        $filters = $this->finance->parseFilters($filters);
        $monthStart = sprintf('%04d-%02d-01', $filters['year'], $filters['month']);
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $accounts = FinanceAccount::query()->where('is_active', true)->orderBy('sort_order')->get()->keyBy('slug');
        $allTransactions = FinanceTransaction::query()->with(['account', 'toAccount'])->orderBy('transaction_date')->orderBy('id')->get();

        $header = [
            'Date', 'Day', 'Balance', 'IN', 'OUT',
            'Rakuten', 'Paypay', 'Seven', '三井住友', 'Cash',
            'Rakuten', 'Amazon', 'Paypay', 'JAL', 'Seven', 'Fami', 'EPOS', '(CL)', 'Total',
            'PH', 'BPI', 'PH Bank In', 'PH Bank Out', 'PH G/Cash', 'BPI Master', 'BanKard', 'Total', 'Comment',
        ];

        $lines = [$header];
        $dayLabels = ['日', '月', '火', '水', '木', '金', '土'];
        $cursor = strtotime($monthStart);
        $end = strtotime($monthEnd);

        while ($cursor <= $end) {
            $date = date('Y-m-d', $cursor);
            $dayTransactions = $allTransactions->filter(
                fn (FinanceTransaction $transaction) => $transaction->transaction_date->format('Y-m-d') === $date
            );

            $jpIncome = $this->sumByAccountKind($dayTransactions, 'income', 'jp', ['bank', 'cash', 'wallet']);
            $jpExpense = $this->sumByAccountKind($dayTransactions, 'expense', 'jp', ['bank', 'cash', 'wallet']);
            $phBankIn = $this->sumForAccountSlug($dayTransactions, 'income', 'ph_bank_bpi');
            $phBankOut = $this->sumForAccountSlug($dayTransactions, 'expense', 'ph_bank_bpi');
            $comment = $dayTransactions
                ->map(fn (FinanceTransaction $transaction) => trim((string) $transaction->memo))
                ->filter()
                ->implode(' / ');

            $jpBankBalances = [];
            foreach (self::BUDGET_MONITOR_JP_BANK_BALANCE_COLUMNS as $slug) {
                $account = $accounts->get($slug);
                $jpBankBalances[] = $account
                    ? $this->finance->calculateAccountBalance($account, $this->transactionsUntilDate($allTransactions, $date))
                    : 0;
            }

            $jpCardBalances = [];
            foreach (self::BUDGET_MONITOR_JP_CARD_BALANCE_COLUMNS as $slug) {
                $account = $accounts->get($slug);
                $jpCardBalances[] = $account
                    ? $this->finance->calculateAccountBalance($account, $this->transactionsUntilDate($allTransactions, $date))
                    : 0;
            }

            $phCardBalances = [];
            foreach (self::BUDGET_MONITOR_PH_CARD_BALANCE_COLUMNS as $slug) {
                $account = $accounts->get($slug);
                $phCardBalances[] = $account
                    ? $this->finance->calculateAccountBalance($account, $this->transactionsUntilDate($allTransactions, $date))
                    : 0;
            }

            $jpBalance = array_sum($jpBankBalances) + array_sum($jpCardBalances);
            $bpiBalance = $accounts->get('ph_bank_bpi')
                ? $this->finance->calculateAccountBalance($accounts->get('ph_bank_bpi'), $this->transactionsUntilDate($allTransactions, $date))
                : 0;
            $phCashBalance = $accounts->get('ph_cash_petty')
                ? $this->finance->calculateAccountBalance($accounts->get('ph_cash_petty'), $this->transactionsUntilDate($allTransactions, $date))
                : 0;
            $phBalance = $bpiBalance + $phCashBalance + array_sum($phCardBalances);

            $lines[] = [
                $this->formatBudgetMonitorDate($date),
                $dayLabels[(int) date('w', $cursor)],
                $this->formatCsvAmount($jpBalance),
                $this->formatCsvAmount($jpIncome),
                $this->formatCsvAmount($jpExpense),
                ...array_map(fn (float $amount) => $this->formatCsvAmount($amount), $jpBankBalances),
                ...array_map(fn (float $amount) => $amount > 0 ? $this->formatCsvAmount($amount) : '', $jpCardBalances),
                $this->formatCsvAmount(array_sum($jpCardBalances)),
                $this->formatCsvAmount($phBalance),
                $this->formatCsvAmount($bpiBalance),
                $this->formatCsvAmount($phBankIn),
                $this->formatCsvAmount($phBankOut),
                $this->formatCsvAmount($phCashBalance),
                ...array_map(fn (float $amount) => $amount > 0 ? $this->formatCsvAmount($amount) : '', $phCardBalances),
                $this->formatCsvAmount(array_sum($phCardBalances)),
                $comment,
            ];

            $cursor = strtotime('+1 day', $cursor);
        }

        return $this->toCsv($lines);
    }

    /**
     * @return array{format: string, created: int, updated: int, skipped: int, deleted: int, from: ?string, to: ?string, messages: list<string>}
     */
    private function importAccounts(string $content, bool $updateExisting): array
    {
        $rows = $this->parseCsvRows($content);
        if ($rows === []) {
            throw new \InvalidArgumentException('CSVが空です');
        }

        $header = array_map(fn ($value) => mb_strtolower(trim((string) $value)), array_shift($rows) ?: []);
        $indexes = $this->mapAccountHeaderIndexes($header);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        /** @var array<string, string> $pendingLinks account slug => linked bank slug or name */
        $pendingLinks = [];

        foreach ($rows as $row) {
            $name = trim($this->csvCell($row, $indexes['name']));
            if ($name === '') {
                $skipped++;

                continue;
            }

            $region = $this->normalizeImportRegion($this->csvCell($row, $indexes['region']));
            $kind = $this->normalizeImportKind($this->csvCell($row, $indexes['kind']));
            $slug = trim($this->csvCell($row, $indexes['slug']));
            $currency = trim($this->csvCell($row, $indexes['currency']));
            if ($currency === '') {
                $currency = $this->finance->currencyForRegion($region);
            }
            $sortOrder = trim($this->csvCell($row, $indexes['sort_order']));
            $initialBalance = $this->parseAmount($this->csvCell($row, $indexes['initial_balance']));
            $adjustmentAmount = $this->parseAmount($this->csvCell($row, $indexes['adjustment_amount']));
            $showInOverview = $this->parseImportBool($this->csvCell($row, $indexes['show_in_overview']));
            $linkedBankRaw = trim($this->csvCell($row, $indexes['linked_bank_slug']));

            $account = null;
            if ($slug !== '') {
                $account = FinanceAccount::query()->where('slug', $slug)->first();
            }
            if ($account === null && $updateExisting) {
                $account = FinanceAccount::query()
                    ->where('is_active', true)
                    ->where('name', $name)
                    ->where('region', $region)
                    ->where('kind', $kind)
                    ->first();
            }

            if ($account !== null) {
                if (! $updateExisting) {
                    $skipped++;

                    continue;
                }

                $account->name = $name;
                $account->region = $region;
                $account->kind = $kind;
                $account->currency = $currency;
                if ($sortOrder !== '' && ctype_digit($sortOrder)) {
                    $account->sort_order = (int) $sortOrder;
                }
                $account->initial_balance = $initialBalance;
                $account->adjustment_amount = $adjustmentAmount;
                $account->show_in_overview = $showInOverview;
                $account->is_active = true;
                if ($kind !== 'credit_card') {
                    $account->linked_bank_id = null;
                }
                $account->save();
                $updated++;
                $accountSlug = $account->slug;
            } else {
                if ($slug !== '' && FinanceAccount::query()->where('slug', $slug)->exists()) {
                    throw new \InvalidArgumentException("識別子 slug が重複しています: {$slug}");
                }

                $account = $this->finance->createAccount([
                    'name' => $name,
                    'region' => $region,
                    'kind' => $kind,
                    'initialBalance' => $initialBalance,
                    'adjustmentAmount' => $adjustmentAmount,
                    'showInOverview' => $showInOverview,
                    'sortOrder' => $sortOrder !== '' && ctype_digit($sortOrder) ? (int) $sortOrder : null,
                ]);

                if ($currency !== $this->finance->currencyForRegion($region)) {
                    $account->currency = $currency;
                }
                if ($slug !== '' && $account->slug !== $slug) {
                    $account->slug = $slug;
                }
                $account->save();
                $created++;
                $accountSlug = $account->slug;
            }

            if ($kind === 'credit_card' && $linkedBankRaw !== '') {
                $pendingLinks[$accountSlug] = $linkedBankRaw;
            }
        }

        if ($pendingLinks !== []) {
            $accountsBySlug = FinanceAccount::query()->where('is_active', true)->get()->keyBy('slug');
            $accountsByName = FinanceAccount::query()
                ->where('is_active', true)
                ->get()
                ->keyBy(fn (FinanceAccount $account) => mb_strtolower($account->name));

            foreach ($pendingLinks as $accountSlug => $linkedRaw) {
                $account = $accountsBySlug->get($accountSlug);
                if ($account === null) {
                    continue;
                }

                $linked = $accountsBySlug->get($linkedRaw)
                    ?? $accountsByName->get(mb_strtolower($linkedRaw));
                if ($linked === null || $linked->kind !== 'bank') {
                    continue;
                }

                if ($linked->region !== $account->region) {
                    continue;
                }

                $account->linked_bank_id = $linked->id;
                $account->save();
            }
        }

        return [
            'format' => self::FORMAT_ACCOUNTS,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'deleted' => 0,
            'from' => null,
            'to' => null,
            'messages' => ['口座マスターをインポートしました。'],
        ];
    }

    private function exportAccounts(): string
    {
        $accounts = FinanceAccount::query()
            ->where('is_active', true)
            ->with('linkedBank')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $lines = [[
            'slug',
            'region',
            'kind',
            'name',
            'currency',
            'sort_order',
            'linked_bank_slug',
            'initial_balance',
            'adjustment_amount',
            'show_in_overview',
        ]];

        foreach ($accounts as $account) {
            $lines[] = [
                $account->slug,
                $account->region,
                $account->kind,
                $account->name,
                $account->currency,
                (string) $account->sort_order,
                $account->linkedBank?->slug ?? '',
                (string) $account->initial_balance,
                (string) ($account->adjustment_amount ?? 0),
                $account->show_in_overview ? '1' : '0',
            ];
        }

        return $this->toCsv($lines);
    }

    private function looksLikeAccountMasterHeader(string $line): bool
    {
        $header = array_map(fn ($value) => mb_strtolower(trim((string) $value)), str_getcsv($line) ?: []);

        return $this->headerHasAny($header, ['name', '口座名', '名前', 'account_name'])
            && $this->headerHasAny($header, ['kind', '種別', 'account_kind'])
            && $this->headerHasAny($header, ['region', '地域']);
    }

    /** @param list<string> $header @param list<string> $candidates */
    private function headerHasAny(array $header, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (in_array(mb_strtolower($candidate), $header, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $header @return array<string, int> */
    private function mapAccountHeaderIndexes(array $header): array
    {
        $aliases = [
            'slug' => ['slug', '識別子', 'account_slug'],
            'region' => ['region', '地域'],
            'kind' => ['kind', '種別', 'account_kind'],
            'name' => ['name', '口座名', '名前', 'account_name'],
            'currency' => ['currency', '通貨'],
            'sort_order' => ['sort_order', '表示順', '並び順', 'sortorder'],
            'linked_bank_slug' => ['linked_bank_slug', '引落口座slug', '引落銀行', 'linked_bank', '引落口座'],
            'initial_balance' => ['initial_balance', '開始残高', '残高', 'initialbalance'],
            'adjustment_amount' => ['adjustment_amount', '調整金額', '調整', 'adjustmentamount'],
            'show_in_overview' => ['show_in_overview', '総残高表示', '概要表示', 'showinoverview'],
        ];

        $indexes = [];
        foreach ($aliases as $key => $options) {
            foreach ($options as $option) {
                $index = array_search(mb_strtolower($option), $header, true);
                if ($index !== false) {
                    $indexes[$key] = $index;
                    break;
                }
            }
        }

        foreach (['region', 'kind', 'name'] as $required) {
            if (! isset($indexes[$required])) {
                throw new \InvalidArgumentException('口座マスターCSVのヘッダーが不正です（地域・種別・口座名が必要）');
            }
        }

        foreach (['slug', 'currency', 'sort_order', 'linked_bank_slug', 'initial_balance', 'adjustment_amount', 'show_in_overview'] as $optional) {
            $indexes[$optional] ??= null;
        }

        return $indexes;
    }

    private function normalizeImportRegion(mixed $value): string
    {
        $raw = mb_strtolower(trim((string) $value));
        if (in_array($raw, ['jp', 'japan', '日本'], true)) {
            return 'jp';
        }
        if (in_array($raw, ['ph', 'philippines', 'フィリピン'], true)) {
            return 'ph';
        }

        return $this->finance->normalizeRegion($raw);
    }

    private function normalizeImportKind(mixed $value): string
    {
        $raw = mb_strtolower(trim((string) $value));
        if (in_array($raw, ['bank', '銀行'], true)) {
            return 'bank';
        }
        if (in_array($raw, ['credit_card', 'クレカ', 'クレジットカード', 'card'], true)) {
            return 'credit_card';
        }
        if (in_array($raw, ['wallet', 'ウォレット'], true)) {
            return 'wallet';
        }
        if (in_array($raw, ['cash', '現金'], true)) {
            return 'cash';
        }

        return $this->finance->normalizeKind($raw);
    }

    private function parseImportBool(mixed $value): bool
    {
        $raw = mb_strtolower(trim((string) $value));
        if ($raw === '') {
            return false;
        }

        return in_array($raw, ['1', 'true', 'yes', 'y', 'on', 'はい', '表示'], true);
    }

    /** Excel の Shift-JIS / CP932 CSV を UTF-8 に揃える */
    public function normalizeCsvEncoding(string $content): string
    {
        $content = str_replace("\u{FEFF}", '', $content);
        if ($content === '') {
            return $content;
        }

        if (mb_check_encoding($content, 'UTF-8') && $this->looksLikeReadableJapaneseCsv($content)) {
            return $content;
        }

        foreach (['SJIS-win', 'CP932', 'SJIS', 'EUC-JP'] as $encoding) {
            $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
            if (! is_string($converted) || $converted === '') {
                continue;
            }

            if ($this->looksLikeReadableJapaneseCsv($converted)) {
                return $converted;
            }
        }

        return $content;
    }

    private function looksLikeReadableJapaneseCsv(string $content): bool
    {
        $firstLine = strtok($content, "\r\n");
        if (! is_string($firstLine) || $firstLine === '') {
            return false;
        }

        if (preg_match('/地域|種別|口座名|日付|金額|メモ|識別子/', $firstLine) === 1) {
            return true;
        }

        return preg_match('/slug|region|kind|name|date|amount|Balance|PH Bank/i', $firstLine) === 1;
    }

    /** @param list<string|null> $row */
    private function csvCell(array $row, ?int $index): string
    {
        if ($index === null || ! array_key_exists($index, $row)) {
            return '';
        }

        return (string) ($row[$index] ?? '');
    }

    /** @return list<list<string>> */
    private function parseCsvRows(string $content): array
    {
        $content = trim(str_replace("\u{FEFF}", '', $this->normalizeCsvEncoding($content)));
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            // Excel のタブ区切り（Unicode テキスト）にも対応
            if (! str_contains($line, ',') && str_contains($line, "\t")) {
                $rows[] = array_map(fn ($value) => (string) $value, explode("\t", $line));
            } else {
                $rows[] = str_getcsv($line);
            }
        }

        return $rows;
    }

    /** @param list<string|null> $header @return array<string, int> */
    private function buildBudgetMonitorColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $label) {
            $map[trim((string) $label)] = $index;
        }

        return $map;
    }

    /** @param list<string> $header @return array<string, int> */
    private function mapTransactionHeaderIndexes(array $header): array
    {
        $aliases = [
            'date' => ['date', '日付', 'transactiondate', 'transaction_date'],
            'type' => ['type', '種別', 'transactiontype'],
            'account' => ['account', '口座', 'accountname', 'account_name'],
            'amount' => ['amount', '金額'],
            'memo' => ['memo', 'メモ', 'comment', '備考'],
        ];

        $indexes = [];
        foreach ($aliases as $key => $options) {
            foreach ($options as $option) {
                $index = array_search($option, $header, true);
                if ($index !== false) {
                    $indexes[$key] = $index;
                    break;
                }
            }
        }

        foreach (['date', 'type', 'account', 'amount'] as $required) {
            if (! isset($indexes[$required])) {
                throw new \InvalidArgumentException('取引CSVのヘッダーが不正です（日付・種別・口座・金額が必要）');
            }
        }

        $indexes['memo'] ??= null;

        return $indexes;
    }

    private function parseBudgetMonitorDate(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $raw, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        return $this->normalizeImportDate($raw);
    }

    private function normalizeImportDate(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $raw, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        $timestamp = strtotime($raw);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    private function parseAmount(mixed $value): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        $raw = str_replace([',', '¥', '₱', ' '], '', $raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return 0.0;
        }

        return round((float) $raw, 2);
    }

    private function formatBudgetMonitorDate(string $isoDate): string
    {
        [$year, $month, $day] = explode('-', $isoDate);

        return sprintf('%d/%d/%d', (int) $year, (int) $month, (int) $day);
    }

    private function formatCsvAmount(float $amount): string
    {
        if ($amount == 0.0) {
            return '0';
        }

        return number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }

    /** @param list<list<string|int|float>> $lines */
    private function toCsv(array $lines): string
    {
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new \RuntimeException('CSVの生成に失敗しました');
        }

        foreach ($lines as $line) {
            fputcsv($output, $line);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv === false ? '' : $csv;
    }

    /** @param Collection<int, FinanceAccount> $accountsById @param Collection<string, FinanceAccount> $accountsByName */
    private function resolveAccountFromRow(array $row, array $indexes, Collection $accountsById, Collection $accountsByName): ?FinanceAccount
    {
        if (isset($indexes['account'])) {
            $raw = trim((string) ($row[$indexes['account']] ?? ''));
            if ($raw !== '' && ctype_digit($raw)) {
                $account = $accountsById->get((int) $raw);
                if ($account) {
                    return $account;
                }
            }

            $account = $accountsByName->get(mb_strtolower($raw));
            if ($account) {
                return $account;
            }
        }

        return null;
    }

    private function transactionExists(string $date, string $type, int $accountId, float $amount, string $memo): bool
    {
        return FinanceTransaction::query()
            ->whereDate('transaction_date', $date)
            ->where('type', $type)
            ->where('account_id', $accountId)
            ->where('amount', $amount)
            ->where('memo', $memo)
            ->exists();
    }

    /** @param Collection<int, FinanceTransaction> $transactions */
    private function transactionsUntilDate(Collection $transactions, string $date): Collection
    {
        return $transactions->filter(
            fn (FinanceTransaction $transaction) => $transaction->transaction_date->format('Y-m-d') <= $date
        );
    }

    /** @param Collection<int, FinanceTransaction> $transactions @param list<string> $kinds */
    private function sumByAccountKind(Collection $transactions, string $type, string $region, array $kinds): float
    {
        return round((float) $transactions
            ->filter(function (FinanceTransaction $transaction) use ($type, $region, $kinds) {
                return $transaction->type === $type
                    && $transaction->account?->region === $region
                    && in_array($transaction->account?->kind, $kinds, true);
            })
            ->sum('amount'), 2);
    }

    /** @param Collection<int, FinanceTransaction> $transactions */
    private function sumForAccountSlug(Collection $transactions, string $type, string $slug): float
    {
        return round((float) $transactions
            ->filter(fn (FinanceTransaction $transaction) => $transaction->type === $type && $transaction->account?->slug === $slug)
            ->sum('amount'), 2);
    }

    private function normalizeImportType(?string $type): string
    {
        $raw = trim((string) $type);
        $map = [
            '収入' => 'income',
            '入金' => 'income',
            '支出' => 'expense',
            '出金' => 'expense',
            '振替' => 'transfer',
            '送金' => 'transfer',
        ];

        return $this->finance->normalizeType($map[$raw] ?? $raw);
    }
}
