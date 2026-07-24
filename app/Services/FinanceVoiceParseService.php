<?php

namespace App\Services;

class FinanceVoiceParseService
{
    public function __construct(
        private LlmJsonClient $llm,
        private FinanceService $finance,
    ) {}

    public function isReady(): bool
    {
        return $this->llm->isReady();
    }

    public function activeProviderLabel(): string
    {
        return $this->llm->activeProviderLabel();
    }

    /**
     * @param  list<array{id: int, name: string, kind: string, kindLabel?: string, region?: string}>  $accounts
     * @param  array<string, string>  $categoryLabels  slug => label
     * @return array{
     *   type: string,
     *   accountId: int|null,
     *   toAccountId: int|null,
     *   amount: float|null,
     *   toAmount: float|null,
     *   category: string|null,
     *   memo: string|null,
     *   transactionDate: string|null,
     *   confidence: string,
     *   raw: array<string, mixed>,
     *   provider: string
     * }
     */
    public function parse(string $transcript, array $accounts, array $categoryLabels, ?string $today = null): array
    {
        $text = trim($transcript);
        if ($text === '') {
            throw new \InvalidArgumentException(__('音声テキストが空です。'));
        }

        $today = $today ?: $this->finance->todayIso();
        $prompt = $this->buildPrompt($text, $accounts, $categoryLabels, $today);
        $result = $this->llm->completeJson(
            $prompt,
            'You are a precise JSON extractor for Japanese finance utterances. Output JSON only.'
        );

        $normalized = $this->normalizeParsed($result['decoded'], $accounts, $categoryLabels, $today);
        $normalized['raw'] = $result['decoded'];
        $normalized['provider'] = $result['provider'];

        return $normalized;
    }

    /**
     * @param  list<array{id: int, name: string, kind: string, kindLabel?: string, region?: string}>  $accounts
     * @param  array<string, string>  $categoryLabels
     */
    private function buildPrompt(string $transcript, array $accounts, array $categoryLabels, string $today): string
    {
        $accountLines = [];
        foreach ($accounts as $account) {
            $id = (int) ($account['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $kind = (string) ($account['kindLabel'] ?? $account['kind'] ?? '');
            $name = (string) ($account['name'] ?? '');
            $region = (string) ($account['region'] ?? '');
            $accountLines[] = "- id={$id} name=\"{$name}\" kind=\"{$kind}\" region=\"{$region}\"";
        }

        $categoryLines = [];
        foreach ($categoryLabels as $slug => $label) {
            $categoryLines[] = "- slug=\"{$slug}\" label=\"{$label}\"";
        }

        $accountsBlock = implode("\n", $accountLines);
        $categoriesBlock = implode("\n", $categoryLines);

        return <<<PROMPT
You convert Japanese spoken finance notes into a single JSON object for a personal ledger app.
Today's date is {$today} (YYYY-MM-DD).
Return ONLY valid JSON. No markdown. No commentary.

Allowed type values: "expense", "income", "transfer".
- 支払い / 支払 / 支出 / 出金 → expense
- 入金 / 収入 / 給料 / 給与 → income
- 振替 / 送金 → transfer

Accounts (match by name; prefer exact or longest partial match; use bank over card when ambiguous like 楽天):
{$accountsBlock}

Expense categories (use slug; only for expense; null if unclear):
{$categoriesBlock}

JSON schema:
{
  "type": "expense|income|transfer",
  "account_id": number|null,
  "to_account_id": number|null,
  "amount": number|null,
  "to_amount": number|null,
  "category": string|null,
  "memo": string|null,
  "transaction_date": "YYYY-MM-DD"|null,
  "confidence": "high|medium|low"
}

Rules:
- amount must be a positive number without currency symbol.
- For expense/income, account_id is the selected account.
- For transfer, account_id is source, to_account_id is destination.
- If a phrase like 「楽天銀行から支払い1000円、買い物」 appears: type=expense, account=楽天銀行, amount=1000, category=shopping.
- Put leftover useful words into memo (not category labels already mapped).
- If date not mentioned, transaction_date = {$today}.
- Never invent account_id values that are not in the list. If unsure, set account_id null.

User utterance:
{$transcript}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  list<array{id: int, name: string}>  $accounts
     * @param  array<string, string>  $categoryLabels
     * @return array{
     *   type: string,
     *   accountId: int|null,
     *   toAccountId: int|null,
     *   amount: float|null,
     *   toAmount: float|null,
     *   category: string|null,
     *   memo: string|null,
     *   transactionDate: string|null,
     *   confidence: string
     * }
     */
    private function normalizeParsed(array $decoded, array $accounts, array $categoryLabels, string $today): array
    {
        $validIds = [];
        foreach ($accounts as $account) {
            $id = (int) ($account['id'] ?? 0);
            if ($id > 0) {
                $validIds[$id] = true;
            }
        }

        $type = strtolower(trim((string) ($decoded['type'] ?? 'expense')));
        if (! in_array($type, ['income', 'expense', 'transfer'], true)) {
            $type = 'expense';
        }

        $accountId = $this->nullablePositiveInt($decoded['account_id'] ?? $decoded['accountId'] ?? null);
        $toAccountId = $this->nullablePositiveInt($decoded['to_account_id'] ?? $decoded['toAccountId'] ?? null);
        if ($accountId !== null && ! isset($validIds[$accountId])) {
            $accountId = null;
        }
        if ($toAccountId !== null && ! isset($validIds[$toAccountId])) {
            $toAccountId = null;
        }

        $amount = $this->nullablePositiveFloat($decoded['amount'] ?? null);
        $toAmount = $this->nullablePositiveFloat($decoded['to_amount'] ?? $decoded['toAmount'] ?? null);

        $category = trim((string) ($decoded['category'] ?? ''));
        if ($category === '' || ! array_key_exists($category, $categoryLabels) || $type !== 'expense') {
            $category = null;
        }

        $memo = trim((string) ($decoded['memo'] ?? ''));
        $memo = $memo !== '' ? mb_substr($memo, 0, 200) : null;

        return [
            'type' => $type,
            'accountId' => $accountId,
            'toAccountId' => $toAccountId,
            'amount' => $amount,
            'toAmount' => $toAmount,
            'category' => $category,
            'memo' => $memo,
            'transactionDate' => $this->llm->normalizeDate(
                $decoded['transaction_date'] ?? $decoded['transactionDate'] ?? null,
                $today
            ),
            'confidence' => $this->llm->normalizeConfidence($decoded['confidence'] ?? null),
        ];
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $n = (int) $value;

        return $n > 0 ? $n : null;
    }

    private function nullablePositiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace([',', '，', '円', '￥', '¥'], '', $value);
            $value = trim($value);
        }
        if (! is_numeric($value)) {
            return null;
        }
        $n = (float) $value;

        return $n > 0 ? $n : null;
    }
}
