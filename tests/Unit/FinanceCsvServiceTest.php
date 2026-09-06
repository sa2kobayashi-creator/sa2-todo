<?php

namespace Tests\Unit;

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\FinanceCsvService;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinanceCsvServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinanceCsvService $service;

    private FinanceService $finance;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create([
            'email' => 'finance-csv@example.com',
            'display_name' => 'Finance CSV',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);
        $this->finance = (new FinanceService)->actingAs($this->user->id);
        $this->service = (new FinanceCsvService($this->finance))->actingAs($this->user->id);
    }

    public function test_imports_and_exports_transactions_csv(): void
    {
        $this->makeAccount();

        $csv = "日付,種別,口座,カテゴリー,金額,メモ\n"
            ."2026-07-02,収入,楽天銀行,,78823,給与\n"
            ."2026-07-02,支出,楽天銀行,食費,1200,コンビニ\n";

        $result = $this->service->import($csv, ['replace' => true]);

        $this->assertSame(FinanceCsvService::FORMAT_TRANSACTIONS, $result['format']);
        $this->assertSame(2, $result['created']);
        $this->assertSame('2026-07-02', $result['from']);
        $this->assertSame('2026-07-02', $result['to']);

        $export = $this->service->export(['year' => 2026, 'month' => 7], FinanceCsvService::FORMAT_TRANSACTIONS);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $export);
        $this->assertStringContainsString('日付,種別,口座,カテゴリー,金額', $export);
        $this->assertStringContainsString('2026-07-02', $export);
        $this->assertStringContainsString('78823', $export);
        $this->assertStringContainsString('1200', $export);
        $this->assertStringContainsString(FinanceCsvService::IMPORT_MEMO_MARKER, $export);
    }

    public function test_replace_removes_previous_csv_imports(): void
    {
        $this->makeAccount();

        $csv = "日付,種別,口座,カテゴリー,金額,メモ\n2026-07-02,支出,楽天銀行,,1000,テスト\n";
        $this->service->import($csv, ['replace' => true]);
        $this->assertSame(1, FinanceTransaction::query()->count());

        $result = $this->service->import($csv, ['replace' => true]);
        $this->assertSame(1, FinanceTransaction::query()->count());
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $result['created']);
    }

    public function test_replace_keeps_manually_entered_transactions(): void
    {
        $account = $this->makeAccount();
        FinanceTransaction::create([
            'user_id' => $this->user->id,
            'transaction_date' => '2026-07-01',
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 500,
            'currency' => 'JPY',
            'memo' => '手入力の買い物',
        ]);

        $csv = "日付,種別,口座,カテゴリー,金額,メモ\n2026-07-02,支出,楽天銀行,,1000,CSV\n";
        $result = $this->service->import($csv, ['replace' => true]);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('finance_transactions', [
            'user_id' => $this->user->id,
            'memo' => '手入力の買い物',
            'amount' => 500,
        ]);
        $this->assertSame(2, FinanceTransaction::query()->where('user_id', $this->user->id)->count());
    }

    public function test_duplicate_rows_are_skipped_without_replace(): void
    {
        $this->makeAccount();
        $csv = "日付,種別,口座,カテゴリー,金額,メモ\n2026-07-02,支出,楽天銀行,,1000,重複\n";

        $first = $this->service->import($csv);
        $second = $this->service->import($csv);

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, FinanceTransaction::query()->count());
    }

    public function test_skips_unknown_account_invalid_date_and_non_positive_amount(): void
    {
        $this->makeAccount();
        $csv = "日付,種別,口座,カテゴリー,金額,メモ\n"
            ."2026-07-02,支出,存在しない銀行,,1000,不明口座\n"
            .",支出,楽天銀行,,1000,日付なし\n"
            ."2026-07-02,支出,楽天銀行,,0,ゼロ\n"
            ."2026-07-02,支出,楽天銀行,,-50,マイナス\n"
            ."2026-07-02,支出,楽天銀行,,abc,文字\n"
            ."2026-07-03,支出,楽天銀行,,1500,有効\n";

        $result = $this->service->import($csv);

        $this->assertSame(1, $result['created']);
        $this->assertSame(5, $result['skipped']);
        $this->assertSame('2026-07-03', $result['from']);
        $this->assertDatabaseHas('finance_transactions', [
            'user_id' => $this->user->id,
            'amount' => 1500,
        ]);
    }

    public function test_skips_inactive_accounts(): void
    {
        $this->makeAccount(['is_active' => false, 'slug' => 'jp_bank_inactive']);
        $csv = "日付,種別,口座,カテゴリー,金額,メモ\n2026-07-02,支出,楽天銀行,,1000,停止口座\n";

        $result = $this->service->import($csv);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_accepts_english_headers_slash_dates_and_formatted_amounts(): void
    {
        $account = $this->makeAccount();
        $csv = "date,type,account,category,amount,memo\n"
            ."2026/7/2,expense,楽天銀行,,\"1,200\",comma\n"
            ."2026/07/03,income,楽天銀行,,¥2500,yen\n";

        $result = $this->service->import($csv);

        $this->assertSame(2, $result['created']);
        $this->assertDatabaseHas('finance_transactions', [
            'account_id' => $account->id,
            'transaction_date' => '2026-07-02',
            'type' => 'expense',
            'amount' => 1200,
        ]);
        $this->assertDatabaseHas('finance_transactions', [
            'account_id' => $account->id,
            'transaction_date' => '2026-07-03',
            'type' => 'income',
            'amount' => 2500,
        ]);
    }

    public function test_accepts_japanese_type_aliases_and_tab_separated_rows(): void
    {
        $this->makeAccount();
        $csv = "日付\t種別\t口座\t金額\tメモ\n"
            ."2026-07-02\t入金\t楽天銀行\t3000\t給与\n"
            ."2026-07-03\t出金\t楽天銀行\t400\t買い物\n"
            ."2026-07-04\t送金\t楽天銀行\t100\t振替相当\n";

        $result = $this->service->import($csv);

        $this->assertSame(3, $result['created']);
        $this->assertDatabaseHas('finance_transactions', ['type' => 'income', 'amount' => 3000]);
        $this->assertDatabaseHas('finance_transactions', ['type' => 'expense', 'amount' => 400]);
        $this->assertDatabaseHas('finance_transactions', ['type' => 'transfer', 'amount' => 100]);
    }

    public function test_resolves_account_by_numeric_id(): void
    {
        $account = $this->makeAccount();
        $csv = "日付,種別,口座,金額,メモ\n2026-07-02,支出,{$account->id},900,ID指定\n";

        $result = $this->service->import($csv);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('finance_transactions', [
            'account_id' => $account->id,
            'amount' => 900,
        ]);
    }

    public function test_unknown_type_falls_back_to_expense(): void
    {
        $this->makeAccount();
        $csv = "日付,種別,口座,金額,メモ\n2026-07-02,不明,楽天銀行,800,種別不明\n";

        $this->service->import($csv);

        $this->assertDatabaseHas('finance_transactions', [
            'user_id' => $this->user->id,
            'type' => 'expense',
            'amount' => 800,
        ]);
    }

    public function test_stores_custom_expense_category_and_ignores_unknown_label(): void
    {
        $this->makeAccount();
        $this->finance->createExpenseCategory('食費');

        $csv = "日付,種別,口座,カテゴリー,金額,メモ\n"
            ."2026-07-02,支出,楽天銀行,食費,1100,既知\n"
            ."2026-07-03,支出,楽天銀行,未登録カテゴリ,2200,未知\n"
            ."2026-07-04,収入,楽天銀行,食費,3300,収入はカテゴリなし\n";

        $this->service->import($csv);

        $known = FinanceTransaction::query()->where('amount', 1100)->first();
        $unknown = FinanceTransaction::query()->where('amount', 2200)->first();
        $income = FinanceTransaction::query()->where('amount', 3300)->first();

        $this->assertNotNull($known?->category);
        $this->assertNull($unknown?->category);
        $this->assertNull($income?->category);
        $this->assertSame('食費', $this->finance->expenseCategoryDisplayLabel($known->category));
    }

    public function test_imports_shift_jis_csv(): void
    {
        $this->makeAccount();
        $utf8 = "日付,種別,口座,金額,メモ\n2026-07-02,支出,楽天銀行,777,SJIS\n";
        $sjis = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
        $this->assertNotSame($utf8, $sjis);

        $result = $this->service->import($sjis);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('finance_transactions', [
            'user_id' => $this->user->id,
            'amount' => 777,
        ]);
    }

    public function test_imports_utf8_bom_csv(): void
    {
        $this->makeAccount();
        $csv = "\xEF\xBB\xBF日付,種別,口座,金額,メモ\n2026-07-02,支出,楽天銀行,321,BOM\n";

        $result = $this->service->import($csv);

        $this->assertSame(1, $result['created']);
    }

    public function test_empty_csv_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->import("   \n");
    }

    public function test_invalid_headers_throw(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->import("地域,種別,口座名,開始残高\njp,bank,楽天銀行,0\n");
    }

    public function test_legacy_budget_monitor_headers_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->import("Date,Day,Balance,IN,OUT,楽天銀行\n2026/7/1,水,0,100,0,0\n");
    }

    public function test_exports_only_the_requested_month(): void
    {
        $account = $this->makeAccount();
        foreach (['2026-06-30' => 111, '2026-07-01' => 222, '2026-08-01' => 333] as $date => $amount) {
            FinanceTransaction::create([
                'user_id' => $this->user->id,
                'transaction_date' => $date,
                'type' => 'expense',
                'account_id' => $account->id,
                'amount' => $amount,
                'currency' => 'JPY',
                'memo' => '月跨ぎ'.$amount,
            ]);
        }

        $csv = $this->service->export(['year' => 2026, 'month' => 7]);

        $this->assertStringContainsString('222', $csv);
        $this->assertStringContainsString('月跨ぎ222', $csv);
        $this->assertStringNotContainsString('111', $csv);
        $this->assertStringNotContainsString('333', $csv);
    }

    public function test_export_does_not_include_other_users_transactions(): void
    {
        $account = $this->makeAccount();
        FinanceTransaction::create([
            'user_id' => $this->user->id,
            'transaction_date' => '2026-07-02',
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 100,
            'currency' => 'JPY',
            'memo' => '自分',
        ]);

        $other = User::create([
            'email' => 'finance-csv-other@example.com',
            'display_name' => 'Other',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);
        $otherAccount = FinanceAccount::create([
            'user_id' => $other->id,
            'slug' => 'other_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '他人銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        FinanceTransaction::create([
            'user_id' => $other->id,
            'transaction_date' => '2026-07-02',
            'type' => 'expense',
            'account_id' => $otherAccount->id,
            'amount' => 9999,
            'currency' => 'JPY',
            'memo' => '他人の取引',
        ]);

        $csv = $this->service->export(['year' => 2026, 'month' => 7]);

        $this->assertStringContainsString('自分', $csv);
        $this->assertStringNotContainsString('他人の取引', $csv);
        $this->assertStringNotContainsString('他人銀行', $csv);
    }

    public function test_exports_template_csv(): void
    {
        $csv = $this->service->export([], FinanceCsvService::FORMAT_TEMPLATE);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        foreach (FinanceCsvService::TRANSACTION_HEADERS as $header) {
            $this->assertStringContainsString($header, $csv);
        }
        $this->assertStringContainsString('コンビニ', $csv);
        $this->assertStringContainsString('給与', $csv);
    }

    public function test_rejects_unknown_export_formats(): void
    {
        foreach (['budget_monitor', 'accounts', ''] as $format) {
            try {
                $this->service->export([], $format);
                $this->fail('Expected invalid format: '.$format);
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAccount(array $overrides = []): FinanceAccount
    {
        return FinanceAccount::create(array_merge([
            'user_id' => $this->user->id,
            'slug' => 'jp_bank_rakuten',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '楽天銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ], $overrides));
    }
}
