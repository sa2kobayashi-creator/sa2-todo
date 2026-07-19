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

    /**
     * マニュアル運用の予算監視CSVヘッダー（この順序で入出力）
     *
     * @var list<string>
     */
    public const BUDGET_MONITOR_HEADERS = [
        'Date', 'Day', 'Balance', 'IN', 'OUT', '送金',
        '楽天銀行', 'PAYPAY銀行', 'セブン銀行', '三井住友銀行', 'Petty Cash',
        'BPI', 'PH Bank In', 'PH Bank Out', 'PH Petty Cash',
        'Comment',
        '楽天VISA', '楽天VISAプレミアム', 'Amazon Master', 'PayPAY Visa', 'JAL JCB', 'セブンJCB', 'ファミJCB', 'EPOS VISA', '三井住友CL',
        'Total',
        'BPI', 'PH Bank In', 'PH Bank Out', 'PH G/Cash', 'BPI Master', 'Bankard Gold Master', 'Bankard Airmiles Visa',
        'Total',
    ];

    /** @var array<string, string> ラベル => income|expense:口座名（同名列は先頭） */
    private const BUDGET_MONITOR_FLOW_COLUMNS = [
        'IN' => 'income:楽天銀行',
        'OUT' => 'expense:楽天銀行',
        'PH Bank In' => 'income:BPI',
        'PH Bank Out' => 'expense:BPI',
    ];

    /**
     * 残高スナップショット列（0埋めの日は更新なしとみなす）
     *
     * @var list<array{label: string, region?: string, kind?: string}>
     */
    private const BUDGET_MONITOR_BALANCE_COLUMNS = [
        ['label' => '楽天銀行', 'region' => 'jp'],
        ['label' => 'PAYPAY銀行', 'region' => 'jp'],
        ['label' => 'セブン銀行', 'region' => 'jp'],
        ['label' => '三井住友銀行', 'region' => 'jp'],
        ['label' => 'Petty Cash', 'region' => 'jp', 'kind' => 'cash'],
        ['label' => 'BPI', 'region' => 'ph', 'kind' => 'bank'],
        ['label' => 'PH Petty Cash', 'region' => 'ph', 'kind' => 'cash'],
        ['label' => 'PH G/Cash', 'region' => 'ph'],
    ];

    /** @var list<string> */
    private const BUDGET_MONITOR_JP_BANK_SLUGS = [
        'jp_bank_rakuten',
        'jp_bank_paypay',
        'jp_bank_seven',
        'jp_bank_smbc',
        'jp_cash_petty',
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

        if (
            str_contains($firstLine, 'Balance')
            && (str_contains($firstLine, 'PH Bank In') || str_contains($firstLine, '楽天銀行') || str_contains($firstLine, '送金'))
        ) {
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
        $accounts = FinanceAccount::query()->where('is_active', true)->get();
        $created = 0;
        $skipped = 0;
        $missingAccounts = [];

        $parsed = [];
        foreach ($rows as $row) {
            $date = $this->parseBudgetMonitorDate($row[0] ?? null);
            if ($date === null) {
                $skipped++;

                continue;
            }
            $parsed[] = ['date' => $date, 'row' => $row];
        }

        $dates = array_values(array_unique(array_column($parsed, 'date')));
        sort($dates);
        $from = $dates[0] ?? null;
        $to = $dates !== [] ? $dates[array_key_last($dates)] : null;

        if ($replace) {
            $deleteQuery = FinanceTransaction::query()
                ->where('memo', 'like', '%'.self::IMPORT_MEMO_MARKER.'%');
            if ($from && $to) {
                $deleteQuery
                    ->whereDate('transaction_date', '>=', $from)
                    ->whereDate('transaction_date', '<=', $to);
            }
            $deleted = $deleteQuery->delete();
        } else {
            $deleted = 0;
        }

        foreach ($parsed as $item) {
            $date = $item['date'];
            $row = $item['row'];
            $comment = trim((string) ($row[$columnMap['Comment'][0] ?? -1] ?? ''));
            if ($comment === '' && isset($columnMap['comment'][0])) {
                $comment = trim((string) ($row[$columnMap['comment'][0]] ?? ''));
            }

            foreach (self::BUDGET_MONITOR_FLOW_COLUMNS as $headerLabel => $mapping) {
                $index = $columnMap[$headerLabel][0] ?? null;
                if ($index === null) {
                    continue;
                }

                $amount = $this->parseAmount($row[$index] ?? null);
                if ($amount <= 0) {
                    continue;
                }

                [$type, $accountName] = explode(':', $mapping, 2);
                $account = $this->findAccountByLabel($accountName, $accounts);
                if (! $account) {
                    $missingAccounts[$accountName] = true;
                    $skipped++;

                    continue;
                }

                $created += $this->createBudgetImportTransaction(
                    $date,
                    $type,
                    $account,
                    $amount,
                    trim(self::IMPORT_MEMO_MARKER.' '.$headerLabel.($comment !== '' ? ' '.$comment : '')),
                    $skipped
                );
            }

            if ($includeCardDeltas) {
                foreach ($this->resolveBudgetMonitorCardAccounts($columnMap, $header, $accounts) as $index => $account) {
                    $amount = $this->parseAmount($row[$index] ?? null);
                    if ($amount <= 0) {
                        continue;
                    }

                    $created += $this->createBudgetImportTransaction(
                        $date,
                        'expense',
                        $account,
                        $amount,
                        trim(self::IMPORT_MEMO_MARKER.' カード利用 '.$account->name.($comment !== '' ? ' '.$comment : '')),
                        $skipped
                    );
                }
            }

            // JP 銀行列がすべて 0 の日は「未記入」扱い（残高同期しない）
            $jpBankLabels = ['楽天銀行', 'PAYPAY銀行', 'セブン銀行', '三井住友銀行', 'Petty Cash'];
            $jpBankFilled = false;
            foreach ($jpBankLabels as $label) {
                $idx = $columnMap[$label][0] ?? null;
                if ($idx !== null && $this->parseAmount($row[$idx] ?? null) > 0) {
                    $jpBankFilled = true;
                    break;
                }
            }

            foreach (self::BUDGET_MONITOR_BALANCE_COLUMNS as $spec) {
                $label = $spec['label'];
                $isJpBank = in_array($label, $jpBankLabels, true);
                if ($isJpBank && ! $jpBankFilled) {
                    continue;
                }

                $indexes = $columnMap[$label] ?? [];
                if ($indexes === []) {
                    // エイリアス見出し
                    if ($label === 'PH G/Cash') {
                        $indexes = $columnMap['PH G/Cash'] ?? $columnMap['PH GCash'] ?? $columnMap['Gcash'] ?? [];
                    } elseif ($label === 'PH Petty Cash') {
                        $indexes = $columnMap['PH Petty Cash'] ?? [];
                    }
                }
                if ($indexes === []) {
                    continue;
                }

                // 同名列は後勝ち（当日の最終残高）
                $target = null;
                foreach ($indexes as $index) {
                    $raw = $row[$index] ?? null;
                    if ($raw === null || trim((string) $raw) === '') {
                        continue;
                    }
                    $target = $this->parseAmount($raw);
                }
                if ($target === null) {
                    continue;
                }
                if ($isJpBank && $target <= 0) {
                    continue;
                }

                $account = $this->findAccountByLabel($label, $accounts, $spec['region'] ?? null, $spec['kind'] ?? null);
                if (! $account) {
                    $missingAccounts[$label] = true;
                    $skipped++;

                    continue;
                }

                $created += $this->syncAccountBalanceToTarget(
                    $account,
                    $date,
                    $target,
                    trim(self::IMPORT_MEMO_MARKER.' 残高 '.$account->name.($comment !== '' ? ' '.$comment : '')),
                    $skipped
                );
            }
        }

        $messages = ['予算監視CSVを口座名照合でインポートしました（入出金'.($includeCardDeltas ? '・クレカ' : '').'・残高同期）。'];
        if ($missingAccounts !== []) {
            $messages[] = '未検出の口座: '.implode('、', array_keys($missingAccounts)).'（口座マスターの名称をCSV見出しと揃えてください）';
        }

        return [
            'format' => self::FORMAT_BUDGET_MONITOR,
            'created' => $created,
            'updated' => 0,
            'skipped' => $skipped,
            'deleted' => $deleted,
            'from' => $from,
            'to' => $to,
            'messages' => $messages,
        ];
    }

    private function createBudgetImportTransaction(
        string $date,
        string $type,
        FinanceAccount $account,
        float $amount,
        string $memo,
        int &$skipped,
    ): int {
        if ($this->transactionExists($date, $type, $account->id, $amount, $memo)) {
            $skipped++;

            return 0;
        }

        FinanceTransaction::query()->create([
            'transaction_date' => $date,
            'type' => $type,
            'account_id' => $account->id,
            'amount' => $amount,
            'currency' => $account->currency,
            'memo' => $memo,
        ]);

        return 1;
    }

    private function syncAccountBalanceToTarget(
        FinanceAccount $account,
        string $date,
        float $target,
        string $memo,
        int &$skipped,
    ): int {
        $until = FinanceTransaction::query()
            ->whereDate('transaction_date', '<=', $date)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
        $current = $this->finance->calculateAccountBalance($account, $until);
        $delta = round($target - $current, 2);
        if (abs($delta) < 0.01) {
            return 0;
        }

        $type = $delta > 0 ? 'income' : 'expense';
        $amount = abs($delta);

        return $this->createBudgetImportTransaction($date, $type, $account, $amount, $memo, $skipped);
    }

    /**
     * @param Collection<int, FinanceAccount> $accounts
     */
    private function findAccountByLabel(
        string $label,
        Collection $accounts,
        ?string $preferRegion = null,
        ?string $preferKind = null,
    ): ?FinanceAccount {
        $normalized = $this->normalizeAccountLabel($label);
        $aliases = [
            'ph g/cash' => ['gcash', 'g/cash', 'ph g/cash', 'ph gcash', 'ph g cash'],
            'ph petty cash' => ['ph petty cash', 'petty cash'],
            'petty cash' => ['petty cash'],
            'bpi' => ['bpi'],
            '楽天visa' => ['楽天visa', 'rakuten visa', 'rakuten'],
            '楽天visaプレミアム' => ['楽天visaプレミアム', 'rakuten visa premium'],
            'amazon master' => ['amazon master', 'amazon'],
            'paypay visa' => ['paypay visa', 'paypay'],
            'jal jcb' => ['jal jcb', 'jal'],
            'セブンjcb' => ['セブンjcb', 'seven jcb', 'seven'],
            'ファミjcb' => ['ファミjcb', 'fami jcb', 'fami'],
            'epos visa' => ['epos visa', 'epos'],
            '三井住友cl' => ['三井住友cl', '三井住友 (cl)', 'smbc cl'],
            'bpi master' => ['bpi master', 'bpi mastercard'],
            'bankard gold master' => ['bankard gold master', 'bankard mastercard gold', 'bankard'],
            'bankard airmiles visa' => ['bankard airmiles visa'],
            'paypay銀行' => ['paypay銀行'],
            '楽天銀行' => ['楽天銀行'],
        ];

        $candidates = $aliases[$normalized] ?? [$normalized];
        $matches = $accounts->filter(function (FinanceAccount $account) use ($candidates) {
            $name = $this->normalizeAccountLabel($account->name);

            return in_array($name, $candidates, true);
        })->values();

        if ($matches->isEmpty()) {
            return null;
        }

        if ($preferRegion !== null) {
            $regionMatches = $matches->where('region', $preferRegion)->values();
            if ($regionMatches->isNotEmpty()) {
                $matches = $regionMatches;
            }
        }

        if ($preferKind !== null) {
            $kindMatches = $matches->where('kind', $preferKind)->values();
            if ($kindMatches->isNotEmpty()) {
                $matches = $kindMatches;
            }
        }

        return $matches->first();
    }

    private function normalizeAccountLabel(string $label): string
    {
        $value = trim(mb_strtolower($label));
        $value = str_replace(['　', '_'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
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

        $lines = [self::BUDGET_MONITOR_HEADERS];
        $dayLabels = ['日', '月', '火', '水', '木', '金', '土'];
        $jpCardSlugs = [
            'jp_card_rakuten',
            'jp_card_rakuten_premium',
            'jp_card_amazon',
            'jp_card_paypay',
            'jp_card_jal',
            'jp_card_seven',
            'jp_card_fami',
            'jp_card_epos',
            'jp_card_smbc_cl',
        ];
        $phCardSlugs = [
            'ph_card_bpi_mc',
            'ph_card_bankard_mc_gold',
            'ph_card_bankard_airmiles',
        ];

        $cursor = strtotime($monthStart);
        $end = strtotime($monthEnd);

        while ($cursor <= $end) {
            $date = date('Y-m-d', $cursor);
            $dayTransactions = $allTransactions->filter(
                fn (FinanceTransaction $transaction) => $transaction->transaction_date->format('Y-m-d') === $date
            );
            $until = $this->transactionsUntilDate($allTransactions, $date);

            $jpIncome = $this->sumByAccountKind($dayTransactions, 'income', 'jp', ['bank', 'cash', 'wallet']);
            $jpExpense = $this->sumByAccountKind($dayTransactions, 'expense', 'jp', ['bank', 'cash', 'wallet']);
            $phBankIn = $this->sumForAccountSlug($dayTransactions, 'income', 'ph_bank_bpi');
            $phBankOut = $this->sumForAccountSlug($dayTransactions, 'expense', 'ph_bank_bpi');
            $transferOut = $dayTransactions
                ->filter(fn (FinanceTransaction $t) => $t->type === 'transfer')
                ->sum(fn (FinanceTransaction $t) => (float) $t->amount);

            $comment = $dayTransactions
                ->map(function (FinanceTransaction $transaction) {
                    $memo = trim((string) $transaction->memo);

                    return str_starts_with($memo, self::IMPORT_MEMO_MARKER)
                        ? trim(mb_substr($memo, mb_strlen(self::IMPORT_MEMO_MARKER)))
                        : $memo;
                })
                ->filter()
                ->unique()
                ->implode(' / ');

            $jpBankBalances = [];
            foreach (self::BUDGET_MONITOR_JP_BANK_SLUGS as $slug) {
                $account = $accounts->get($slug);
                $jpBankBalances[] = $account ? $this->finance->calculateAccountBalance($account, $until) : 0.0;
            }

            $jpCardAmounts = [];
            foreach ($jpCardSlugs as $slug) {
                $jpCardAmounts[] = $this->sumForAccountSlug($dayTransactions, 'expense', $slug);
            }

            $phCardAmounts = [];
            foreach ($phCardSlugs as $slug) {
                $phCardAmounts[] = $this->sumForAccountSlug($dayTransactions, 'expense', $slug);
            }

            $bpiBalance = $accounts->get('ph_bank_bpi')
                ? $this->finance->calculateAccountBalance($accounts->get('ph_bank_bpi'), $until)
                : 0.0;
            $phCashBalance = $accounts->get('ph_cash_petty')
                ? $this->finance->calculateAccountBalance($accounts->get('ph_cash_petty'), $until)
                : 0.0;
            $jpBalance = array_sum($jpBankBalances);

            $fmt = fn (float $amount) => $this->formatBudgetMonitorAmount($amount);
            $fmtBlankZero = fn (float $amount) => $amount > 0 ? $this->formatBudgetMonitorAmount($amount) : '';

            $lines[] = [
                $this->formatBudgetMonitorDate($date),
                $dayLabels[(int) date('w', $cursor)],
                $fmt($jpBalance),
                $fmt($jpIncome),
                $fmt($jpExpense),
                $transferOut > 0 ? $fmt((float) $transferOut) : ($jpIncome > 0 ? $fmt($jpIncome) : ''),
                ...array_map($fmt, $jpBankBalances),
                $fmt($bpiBalance),
                $fmt($phBankIn),
                $fmt($phBankOut),
                $fmt($phCashBalance),
                $comment,
                ...array_map($fmtBlankZero, $jpCardAmounts),
                $fmt(array_sum($jpCardAmounts)),
                $fmt($bpiBalance),
                $fmt($phBankIn),
                $fmt($phBankOut),
                $fmt($phCashBalance),
                ...array_map($fmtBlankZero, $phCardAmounts),
                $fmt(array_sum($phCardAmounts)),
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

    /**
     * @param list<string|null> $header
     * @return array<string, list<int>> ラベル => 出現インデックス一覧（先頭から）
     */
    private function buildBudgetMonitorColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $label) {
            $key = trim((string) $label);
            if ($key === '') {
                continue;
            }
            $map[$key] ??= [];
            $map[$key][] = (int) $index;
        }

        return $map;
    }

    /**
     * クレカ列を「見出し名 = 口座名」で解決
     *
     * @param array<string, list<int>> $columnMap
     * @param list<string|null> $header
     * @param Collection<int, FinanceAccount> $accounts
     * @return array<int, FinanceAccount> index => account
     */
    private function resolveBudgetMonitorCardAccounts(array $columnMap, array $header, Collection $accounts): array
    {
        $commentIndex = $columnMap['Comment'][0] ?? $columnMap['comment'][0] ?? null;
        $skip = [
            'date', 'day', 'balance', 'in', 'out', '送金', 'comment', 'total', 'ph',
            '楽天銀行', 'paypay銀行', 'セブン銀行', '三井住友銀行', 'petty cash',
            'bpi', 'ph bank in', 'ph bank out', 'ph petty cash', 'ph g/cash', 'ph gcash', 'gcash',
        ];
        $cards = $accounts->where('kind', 'credit_card')->values();

        $resolveFrom = function (bool $requireAfterComment) use ($header, $commentIndex, $skip, $cards): array {
            $resolved = [];
            $usedIds = [];
            foreach ($header as $index => $label) {
                $label = trim((string) $label);
                if ($label === '') {
                    continue;
                }
                if ($requireAfterComment && $commentIndex !== null && $index <= $commentIndex) {
                    continue;
                }
                $key = $this->normalizeAccountLabel($label);
                if (in_array($key, $skip, true)) {
                    continue;
                }
                $account = $this->findAccountByLabel($label, $cards);
                if (! $account || isset($usedIds[$account->id])) {
                    continue;
                }
                $resolved[(int) $index] = $account;
                $usedIds[$account->id] = true;
            }

            return $resolved;
        };

        $resolved = $resolveFrom(true);
        if (count($resolved) < 1) {
            $resolved = $resolveFrom(false);
        }

        ksort($resolved);

        return $resolved;
    }

    private function formatBudgetMonitorAmount(float $amount): string
    {
        if (abs($amount) < 0.00001) {
            return '0';
        }

        if (fmod($amount, 1.0) === 0.0) {
            return (string) (int) round($amount);
        }

        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
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

        if ($csv === false || $csv === '') {
            return '';
        }

        // Excel（日本語 Windows）が UTF-8 を正しく開くための BOM
        return "\xEF\xBB\xBF".$csv;
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
