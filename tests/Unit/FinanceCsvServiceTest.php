<?php

namespace Tests\Unit;

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Services\FinanceCsvService;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceCsvServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinanceCsvService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinanceCsvService(new FinanceService);
    }

    public function test_detects_budget_monitor_format(): void
    {
        $content = file_get_contents(base_path('tests/fixtures/budget_monitor_july_sample.csv'));
        $this->assertSame(FinanceCsvService::FORMAT_BUDGET_MONITOR, $this->service->detectFormat($content));
    }

    public function test_imports_budget_monitor_by_account_name(): void
    {
        $this->seedUserStyleAccounts();
        $content = file_get_contents(base_path('tests/fixtures/budget_monitor_july_sample.csv'));

        $result = $this->service->import($content, [
            'replace' => true,
            'includeCardDeltas' => true,
        ]);

        $this->assertSame(FinanceCsvService::FORMAT_BUDGET_MONITOR, $result['format']);
        $this->assertGreaterThan(20, $result['created']);
        $this->assertSame('2026-07-01', $result['from']);
        $this->assertSame('2026-07-19', $result['to']);

        $rakuten = FinanceAccount::query()->where('name', '楽天銀行')->first();
        $this->assertNotNull($rakuten);

        $this->assertDatabaseHas('finance_transactions', [
            'transaction_date' => '2026-07-02',
            'type' => 'income',
            'account_id' => $rakuten->id,
            'amount' => 78823,
        ]);

        $this->assertDatabaseHas('finance_transactions', [
            'transaction_date' => '2026-07-02',
            'type' => 'expense',
            'account_id' => $rakuten->id,
            'amount' => 7761,
        ]);

        $bpi = FinanceAccount::query()->where('name', 'BPI')->first();
        $this->assertNotNull($bpi);
        $this->assertDatabaseHas('finance_transactions', [
            'transaction_date' => '2026-07-02',
            'type' => 'expense',
            'account_id' => $bpi->id,
            'amount' => 34000,
        ]);

        $visa = FinanceAccount::query()->where('name', '楽天VISA')->first();
        $this->assertNotNull($visa);
        $this->assertDatabaseHas('finance_transactions', [
            'transaction_date' => '2026-07-15',
            'type' => 'expense',
            'account_id' => $visa->id,
            'amount' => 42963,
        ]);

        $amazon = FinanceAccount::query()->where('name', 'Amazon Master')->first();
        $this->assertDatabaseHas('finance_transactions', [
            'transaction_date' => '2026-07-15',
            'type' => 'expense',
            'account_id' => $amazon->id,
            'amount' => 20150,
        ]);
    }

    public function test_replace_option_scopes_to_csv_date_range(): void
    {
        $this->seedUserStyleAccounts();
        $content = file_get_contents(base_path('tests/fixtures/budget_monitor_july_sample.csv'));
        $this->service->import($content, ['replace' => true, 'includeCardDeltas' => false]);
        $count = FinanceTransaction::query()->count();
        $this->assertGreaterThan(0, $count);

        $result = $this->service->import($content, ['replace' => true, 'includeCardDeltas' => false]);
        $this->assertSame($count, FinanceTransaction::query()->count());
        $this->assertSame($count, $result['deleted']);
    }

    public function test_exports_transactions_csv(): void
    {
        $this->seedUserStyleAccounts();
        $content = file_get_contents(base_path('tests/fixtures/budget_monitor_july_sample.csv'));
        $this->service->import($content, ['includeCardDeltas' => false]);

        $csv = $this->service->export(['year' => 2026, 'month' => 7], FinanceCsvService::FORMAT_TRANSACTIONS);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('日付,種別,口座,金額', $csv);
        $this->assertStringContainsString('2026-07-02', $csv);
        $this->assertStringContainsString('78823', $csv);
    }

    public function test_exports_budget_monitor_csv(): void
    {
        $this->seedUserStyleAccounts();
        $content = file_get_contents(base_path('tests/fixtures/budget_monitor_july_sample.csv'));
        $this->service->import($content, ['includeCardDeltas' => false]);

        $csv = $this->service->export(['year' => 2026, 'month' => 7], FinanceCsvService::FORMAT_BUDGET_MONITOR);

        $this->assertStringContainsString('Date,Day,Balance,IN,OUT,送金,楽天銀行', $csv);
        $this->assertStringContainsString('2026/7/2', $csv);
        $this->assertStringContainsString('78823', $csv);
    }

    public function test_detects_account_master_format(): void
    {
        $content = file_get_contents(base_path('tests/fixtures/account_master_sample.csv'));
        $this->assertSame(FinanceCsvService::FORMAT_ACCOUNTS, $this->service->detectFormat($content));
    }

    public function test_imports_account_master_csv(): void
    {
        $content = file_get_contents(base_path('tests/fixtures/account_master_sample.csv'));

        $result = $this->service->import($content, ['format' => FinanceCsvService::FORMAT_ACCOUNTS]);

        $this->assertSame(FinanceCsvService::FORMAT_ACCOUNTS, $result['format']);
        $this->assertSame(7, $result['created']);
        $this->assertDatabaseHas('finance_accounts', [
            'slug' => 'jp_bank_rakuten',
            'kind' => 'bank',
            'name' => '楽天銀行',
        ]);
    }

    public function test_updates_existing_accounts_on_account_master_import(): void
    {
        $content = file_get_contents(base_path('tests/fixtures/account_master_sample.csv'));
        $this->service->import($content, ['format' => FinanceCsvService::FORMAT_ACCOUNTS]);

        $updatedCsv = str_replace('500000', '550000', $content);
        $result = $this->service->import($updatedCsv, ['format' => FinanceCsvService::FORMAT_ACCOUNTS]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(7, $result['updated']);
        $this->assertDatabaseHas('finance_accounts', [
            'slug' => 'jp_bank_rakuten',
            'initial_balance' => 550000,
        ]);
    }

    public function test_exports_account_master_csv(): void
    {
        $content = file_get_contents(base_path('tests/fixtures/account_master_sample.csv'));
        $this->service->import($content, ['format' => FinanceCsvService::FORMAT_ACCOUNTS]);

        $csv = $this->service->export([], FinanceCsvService::FORMAT_ACCOUNTS);

        $this->assertStringContainsString('slug,region,kind,name,currency', $csv);
        $this->assertStringContainsString('jp_bank_rakuten,jp,bank,楽天銀行,JPY', $csv);
    }

    public function test_imports_japanese_header_account_csv_with_empty_slug(): void
    {
        $content = "識別子,地域,種別,口座名\n,フィリピン,銀行,BPI\n,日本,銀行,楽天銀行\n";
        $result = $this->service->import($content, ['format' => FinanceCsvService::FORMAT_ACCOUNTS]);

        $this->assertSame(2, $result['created']);
        $this->assertDatabaseHas('finance_accounts', [
            'name' => '楽天銀行',
            'region' => 'jp',
            'kind' => 'bank',
        ]);
    }

    public function test_imports_shift_jis_japanese_account_csv(): void
    {
        $utf8 = "識別子,地域,種別,口座名\n,フィリピン,銀行,BPI\n,日本,銀行,楽天銀行\n";
        $sjis = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');

        $this->assertSame(FinanceCsvService::FORMAT_ACCOUNTS, $this->service->detectFormat($sjis));

        $result = $this->service->import($sjis, ['format' => FinanceCsvService::FORMAT_ACCOUNTS]);

        $this->assertSame(2, $result['created']);
        $this->assertDatabaseHas('finance_accounts', [
            'name' => '楽天銀行',
            'region' => 'jp',
            'kind' => 'bank',
        ]);
    }

    /** ユーザー実データの口座名・スラッグ構成に近いシード */
    private function seedUserStyleAccounts(): void
    {
        $rows = [
            ['slug' => 'jp_bank', 'region' => 'jp', 'kind' => 'bank', 'name' => '楽天銀行', 'currency' => 'JPY'],
            ['slug' => 'jp_bank_paypay', 'region' => 'jp', 'kind' => 'bank', 'name' => 'PAYPAY銀行', 'currency' => 'JPY'],
            ['slug' => 'jp_bank_1', 'region' => 'jp', 'kind' => 'bank', 'name' => 'セブン銀行', 'currency' => 'JPY'],
            ['slug' => 'jp_bank_2', 'region' => 'jp', 'kind' => 'bank', 'name' => '三井住友銀行', 'currency' => 'JPY'],
            ['slug' => 'jp_cash_patty_cash', 'region' => 'jp', 'kind' => 'cash', 'name' => 'Petty Cash', 'currency' => 'JPY'],
            ['slug' => 'ph_bank_bpi', 'region' => 'ph', 'kind' => 'bank', 'name' => 'BPI', 'currency' => 'PHP'],
            ['slug' => 'ph_cash_patty_cash', 'region' => 'ph', 'kind' => 'cash', 'name' => 'Petty Cash', 'currency' => 'PHP'],
            ['slug' => 'ph_wallet_gcash', 'region' => 'ph', 'kind' => 'wallet', 'name' => 'Gcash', 'currency' => 'PHP'],
            ['slug' => 'jp_credit_card_visa_1', 'region' => 'jp', 'kind' => 'credit_card', 'name' => '楽天VISA', 'currency' => 'JPY'],
            ['slug' => 'jp_credit_card_visa', 'region' => 'jp', 'kind' => 'credit_card', 'name' => '楽天VISAプレミアム', 'currency' => 'JPY'],
            ['slug' => 'jp_credit_card_amazon_master', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'Amazon Master', 'currency' => 'JPY'],
            ['slug' => 'jp_credit_card_paypay_visa', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'PayPAY Visa', 'currency' => 'JPY'],
            ['slug' => 'jp_credit_card_jcb', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'セブンJCB', 'currency' => 'JPY'],
            ['slug' => 'jp_credit_card_jaljcb', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'JAL JCB', 'currency' => 'JPY'],
            ['slug' => 'jp_credit_card_eosvisa', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'EPOS VISA', 'currency' => 'JPY'],
            ['slug' => 'jp_credit_card_cl', 'region' => 'jp', 'kind' => 'credit_card', 'name' => '三井住友CL', 'currency' => 'JPY'],
            ['slug' => 'jp_credit_card_jcb_1', 'region' => 'jp', 'kind' => 'credit_card', 'name' => 'ファミJCB', 'currency' => 'JPY'],
            ['slug' => 'ph_credit_card_bpi_master', 'region' => 'ph', 'kind' => 'credit_card', 'name' => 'BPI Master', 'currency' => 'PHP'],
            ['slug' => 'ph_credit_card_bankard_gold_master', 'region' => 'ph', 'kind' => 'credit_card', 'name' => 'Bankard Gold Master', 'currency' => 'PHP'],
            ['slug' => 'ph_credit_card_bankard_airmiles_visa', 'region' => 'ph', 'kind' => 'credit_card', 'name' => 'Bankard Airmiles Visa', 'currency' => 'PHP'],
        ];

        foreach ($rows as $i => $row) {
            FinanceAccount::query()->create([
                ...$row,
                'sort_order' => ($i + 1) * 10,
                'initial_balance' => 0,
                'is_active' => true,
            ]);
        }
    }
}
