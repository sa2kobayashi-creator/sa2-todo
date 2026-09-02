<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use Illuminate\Support\Collection;

class FinanceCsvService
{
    /** インポート行のメモ先頭（置き換え削除の目印） */
    public const IMPORT_MEMO_MARKER = '[予算CSV]';

    public const FORMAT_TRANSACTIONS = 'transactions';

    public const FORMAT_TEMPLATE = 'template';

    /** @var list<string> */
    public const TRANSACTION_HEADERS = [
        '日付', '種別', '口座', 'カテゴリー', '金額', '通貨', '振替先', '振替先金額', 'メモ',
    ];

    public function __construct(private FinanceService $finance) {}

    public function actingAs(int $userId): self
    {
        $this->finance->actingAs($userId);

        return $this;
    }

    private function userId(): int
    {
        return $this->finance->requireUserId();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\FinanceAccount> */
    private function accountsQuery()
    {
        return FinanceAccount::query()->where('user_id', $this->userId());
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\FinanceTransaction> */
    private function transactionsQuery()
    {
        return FinanceTransaction::query()->where('user_id', $this->userId());
    }

    /**
     * @return array{format: string, created: int, updated: int, skipped: int, deleted: int, from: ?string, to: ?string, messages: list<string>}
     */
    public function import(string $content, array $options = []): array
    {
        $content = $this->normalizeCsvEncoding($content);
        $replace = (bool) ($options['replace'] ?? false);

        $this->finance->ensureDefaultAccounts();

        return $this->importTransactions($content, $replace);
    }

    public function export(array $filters, string $format = self::FORMAT_TRANSACTIONS): string
    {
        if ($format === self::FORMAT_TEMPLATE) {
            return $this->exportTemplate();
        }

        if ($format !== self::FORMAT_TRANSACTIONS) {
            throw new \InvalidArgumentException(__('不正なエクスポート形式です'));
        }

        return $this->exportTransactions($filters);
    }

    private function exportTemplate(): string
    {
        return $this->toCsv([
            self::TRANSACTION_HEADERS,
            ['2026-07-02', '支出', '楽天銀行', '食費', '1200', 'JPY', '', '', 'コンビニ'],
            ['2026-07-02', '収入', '楽天銀行', '', '50000', 'JPY', '', '', '給与'],
        ]);
    }

    private function importTransactions(string $content, bool $replace): array
    {
        $rows = $this->parseCsvRows($content);
        if ($rows === []) {
            throw new \InvalidArgumentException(__('CSVが空です'));
        }

        $header = array_map(fn ($value) => strtolower(trim((string) $value)), array_shift($rows) ?: []);
        $indexes = $this->mapTransactionHeaderIndexes($header);
        $accountsById = $this->accountsQuery()->where('is_active', true)->get()->keyBy('id');
        $accountsByName = $this->accountsQuery()->where('is_active', true)->get()->keyBy(fn (FinanceAccount $a) => mb_strtolower($a->name));
        $created = 0;
        $skipped = 0;
        $dates = [];

        if ($replace) {
            $deleted = $this->transactionsQuery()
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
            $categoryRaw = ($indexes['category'] !== null)
                ? trim((string) ($row[$indexes['category']] ?? ''))
                : '';

            if ($this->transactionExists($date, $type, $account->id, $amount, $memo)) {
                $skipped++;

                continue;
            }

            $this->transactionsQuery()->create([
                'user_id' => $this->userId(),
                'transaction_date' => $date,
                'type' => $type,
                'account_id' => $account->id,
                'amount' => $amount,
                'currency' => $account->currency,
                'memo' => $memo,
                'category' => $this->finance->normalizeExpenseCategory($categoryRaw, $type),
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
            'messages' => [__('取引CSVをインポートしました。')],
        ];
    }

    private function exportTransactions(array $filters): string
    {
        $filters = $this->finance->parseFilters($filters);
        $monthStart = sprintf('%04d-%02d-01', $filters['year'], $filters['month']);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $transactions = $this->transactionsQuery()
            ->with(['account', 'toAccount'])
            ->whereDate('transaction_date', '>=', $monthStart)
            ->whereDate('transaction_date', '<=', $monthEnd)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $lines = [self::TRANSACTION_HEADERS];
        foreach ($transactions as $transaction) {
            $lines[] = [
                $transaction->transaction_date->format('Y-m-d'),
                FinanceService::TYPE_LABELS[$transaction->type] ?? $transaction->type,
                $transaction->account?->name ?? '',
                $transaction->type === 'expense'
                    ? $this->finance->expenseCategoryDisplayLabel($transaction->category)
                    : '',
                (string) $transaction->amount,
                $transaction->currency,
                $transaction->toAccount?->name ?? '',
                $transaction->to_amount !== null ? (string) $transaction->to_amount : '',
                $transaction->memo ?? '',
            ];
        }

        return $this->toCsv($lines);
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

        if (preg_match('/日付|金額|メモ|種別|口座/', $firstLine) === 1) {
            return true;
        }

        return preg_match('/date|amount|memo|type|account/i', $firstLine) === 1;
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

            if (! str_contains($line, ',') && str_contains($line, "\t")) {
                $rows[] = array_map(fn ($value) => (string) $value, explode("\t", $line));
            } else {
                $rows[] = str_getcsv($line);
            }
        }

        return $rows;
    }

    /** @param list<string> $header @return array<string, int|null> */
    private function mapTransactionHeaderIndexes(array $header): array
    {
        $aliases = [
            'date' => ['date', '日付', 'transactiondate', 'transaction_date'],
            'type' => ['type', '種別', 'transactiontype'],
            'account' => ['account', '口座', 'accountname', 'account_name'],
            'category' => ['category', 'カテゴリー', '支出カテゴリー'],
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
                throw new \InvalidArgumentException(__('取引CSVのヘッダーが不正です（日付・種別・口座・金額が必要）'));
            }
        }

        $indexes['memo'] ??= null;
        $indexes['category'] ??= null;

        return $indexes;
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

    /** @param list<list<string|int|float>> $lines */
    private function toCsv(array $lines): string
    {
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new \RuntimeException(__('CSVの生成に失敗しました'));
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
        return $this->transactionsQuery()
            ->whereDate('transaction_date', $date)
            ->where('type', $type)
            ->where('account_id', $accountId)
            ->where('amount', $amount)
            ->where('memo', $memo)
            ->exists();
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
