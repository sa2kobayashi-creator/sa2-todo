<?php

namespace Tests\Unit;

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

    public function test_imports_budget_monitor_flow_columns(): void
    {
        $content = file_get_contents(base_path('tests/fixtures/budget_monitor_july_sample.csv'));

        $result = $this->service->import($content, ['includeCardDeltas' => false]);

        $this->assertSame(FinanceCsvService::FORMAT_BUDGET_MONITOR, $result['format']);
        $this->assertSame(8, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame('2026-07-01', $result['from']);
        $this->assertSame('2026-07-07', $result['to']);

        $this->assertDatabaseHas('finance_transactions', [
            'transaction_date' => '2026-07-01',
            'type' => 'expense',
            'amount' => 1000,
            'memo' => FinanceCsvService::IMPORT_MEMO_MARKER.' PH Bank Out',
        ]);

        $this->assertDatabaseHas('finance_transactions', [
            'transaction_date' => '2026-07-02',
            'type' => 'income',
            'amount' => 78823,
            'memo' => FinanceCsvService::IMPORT_MEMO_MARKER.' IN PH(PLDT,MERALCO 4000) 送金(30000)',
        ]);

        $rakuten = FinanceTransaction::query()
            ->whereDate('transaction_date', '2026-07-07')
            ->where('type', 'expense')
            ->where('amount', 106900)
            ->first();

        $this->assertNotNull($rakuten);
        $this->assertStringContainsString('国保(6900)', (string) $rakuten->memo);
    }

    public function test_replace_option_deletes_previous_budget_csv_imports(): void
    {
        $content = file_get_contents(base_path('tests/fixtures/budget_monitor_july_sample.csv'));
        $this->service->import($content, ['includeCardDeltas' => false]);
        $this->assertSame(8, FinanceTransaction::query()->count());

        $result = $this->service->import($content, ['replace' => true, 'includeCardDeltas' => false]);

        $this->assertSame(8, $result['created']);
        $this->assertSame(8, $result['deleted']);
        $this->assertSame(8, FinanceTransaction::query()->count());
    }

    public function test_exports_transactions_csv(): void
    {
        $content = file_get_contents(base_path('tests/fixtures/budget_monitor_july_sample.csv'));
        $this->service->import($content, ['includeCardDeltas' => false]);

        $csv = $this->service->export(['year' => 2026, 'month' => 7], FinanceCsvService::FORMAT_TRANSACTIONS);

        $this->assertStringContainsString('日付,種別,口座,金額', $csv);
        $this->assertStringContainsString('2026-07-02', $csv);
        $this->assertStringContainsString('78823', $csv);
    }

    public function test_exports_budget_monitor_csv(): void
    {
        $content = file_get_contents(base_path('tests/fixtures/budget_monitor_july_sample.csv'));
        $this->service->import($content, ['includeCardDeltas' => false]);

        $csv = $this->service->export(['year' => 2026, 'month' => 7], FinanceCsvService::FORMAT_BUDGET_MONITOR);

        $this->assertStringContainsString('Date,Day,Balance,IN,OUT', $csv);
        $this->assertStringContainsString('2026/7/2', $csv);
        $this->assertStringContainsString('78,823', $csv);
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
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);

        $this->assertDatabaseHas('finance_accounts', [
            'slug' => 'jp_bank_rakuten',
            'kind' => 'bank',
            'name' => '楽天銀行',
            'initial_balance' => 500000,
            'show_in_overview' => true,
        ]);

        $rakutenCard = \App\Models\FinanceAccount::query()->where('slug', 'jp_card_rakuten')->first();
        $rakutenBank = \App\Models\FinanceAccount::query()->where('slug', 'jp_bank_rakuten')->first();
        $this->assertNotNull($rakutenCard);
        $this->assertNotNull($rakutenBank);
        $this->assertSame($rakutenBank->id, $rakutenCard->linked_bank_id);
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
        $this->assertStringContainsString('jp_card_rakuten,jp,credit_card,Rakuten,JPY,110,jp_bank_rakuten', $csv);
    }

    public function test_imports_japanese_header_account_csv_with_empty_slug(): void
    {
        $content = "識別子,地域,種別,口座名\n,フィリピン,銀行,BPI\n,日本,クレカ,楽天VISA\n,フィリピン,ウォレット,Maya\n";

        $result = $this->service->import($content, ['format' => FinanceCsvService::FORMAT_ACCOUNTS]);

        $this->assertSame(3, $result['created']);
        $this->assertDatabaseHas('finance_accounts', [
            'name' => 'BPI',
            'region' => 'ph',
            'kind' => 'bank',
        ]);
        $this->assertDatabaseHas('finance_accounts', [
            'name' => '楽天VISA',
            'region' => 'jp',
            'kind' => 'credit_card',
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
}
