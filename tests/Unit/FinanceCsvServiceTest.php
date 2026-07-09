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
}
