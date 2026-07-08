<?php

namespace Tests\Unit;

use App\Models\FinanceAccount;
use App\Models\FinanceAccountSchedule;
use App\Models\FinanceTransaction;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinanceService;
    }

    public function test_adjustment_amount_is_included_in_account_balance(): void
    {
        $account = FinanceAccount::create([
            'slug' => 'test_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'テスト銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 10000,
            'adjustment_amount' => -500,
            'is_active' => true,
        ]);

        FinanceTransaction::create([
            'transaction_date' => '2026-07-01',
            'type' => 'income',
            'account_id' => $account->id,
            'amount' => 3000,
            'currency' => 'JPY',
        ]);

        $balance = $this->service->calculateAccountBalance($account->fresh());

        $this->assertSame(12500.0, $balance);
    }

    public function test_update_account_initial_balance_saves_adjustment_amount(): void
    {
        $account = FinanceAccount::create([
            'slug' => 'test_bank_2',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'テスト銀行2',
            'currency' => 'JPY',
            'sort_order' => 2,
            'initial_balance' => 0,
            'adjustment_amount' => 0,
            'is_active' => true,
        ]);

        $this->assertTrue($this->service->updateAccountInitialBalance($account->id, 5000, -120.5));

        $account->refresh();
        $this->assertSame(5000.0, (float) $account->initial_balance);
        $this->assertSame(-120.5, (float) $account->adjustment_amount);
    }

    public function test_create_account_registers_bank_account(): void
    {
        $account = $this->service->createAccount([
            'name' => '新規銀行',
            'region' => 'jp',
            'kind' => 'bank',
            'initialBalance' => 1000,
        ]);

        $this->assertSame('新規銀行', $account->name);
        $this->assertSame('bank', $account->kind);
        $this->assertSame('JPY', $account->currency);
        $this->assertTrue($account->is_active);
    }

    public function test_create_credit_card_with_linked_bank(): void
    {
        $bank = FinanceAccount::create([
            'slug' => 'jp_bank_test',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '引落銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $card = $this->service->createAccount([
            'name' => '新規クレカ',
            'region' => 'jp',
            'kind' => 'credit_card',
            'linkedBankId' => $bank->id,
        ]);

        $this->assertSame('credit_card', $card->kind);
        $this->assertSame($bank->id, $card->linked_bank_id);
    }

    public function test_update_account_changes_name_and_kind(): void
    {
        $account = FinanceAccount::create([
            'slug' => 'jp_wallet_test',
            'region' => 'jp',
            'kind' => 'wallet',
            'name' => '旧名',
            'currency' => 'JPY',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        $this->assertTrue($this->service->updateAccount($account->id, [
            'name' => '新名',
            'kind' => 'bank',
        ]));

        $account->refresh();
        $this->assertSame('新名', $account->name);
        $this->assertSame('bank', $account->kind);
    }

    public function test_delete_account_soft_deletes_and_clears_linked_bank(): void
    {
        $bank = FinanceAccount::create([
            'slug' => 'jp_bank_del',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '削除対象銀行',
            'currency' => 'JPY',
            'sort_order' => 4,
            'is_active' => true,
        ]);
        $card = FinanceAccount::create([
            'slug' => 'jp_card_del',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => 'リンク済クレカ',
            'currency' => 'JPY',
            'sort_order' => 5,
            'linked_bank_id' => $bank->id,
            'is_active' => true,
        ]);

        $this->assertTrue($this->service->deleteAccount($bank->id));

        $bank->refresh();
        $card->refresh();
        $this->assertFalse($bank->is_active);
        $this->assertNull($card->linked_bank_id);

        $activeNames = array_column($this->service->listAccounts(), 'name');
        $this->assertNotContains('削除対象銀行', $activeNames);
        $this->assertContains('リンク済クレカ', $activeNames);
    }

    public function test_reorder_accounts_updates_sort_order_within_kind(): void
    {
        $a = FinanceAccount::create([
            'slug' => 'jp_bank_a',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'A銀行',
            'currency' => 'JPY',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $b = FinanceAccount::create([
            'slug' => 'jp_bank_b',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'B銀行',
            'currency' => 'JPY',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        $c = FinanceAccount::create([
            'slug' => 'jp_bank_c',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'C銀行',
            'currency' => 'JPY',
            'sort_order' => 30,
            'is_active' => true,
        ]);

        $this->assertTrue($this->service->reorderAccounts([$c->id, $a->id, $b->id]));

        $this->assertSame(10, $c->fresh()->sort_order);
        $this->assertSame(20, $a->fresh()->sort_order);
        $this->assertSame(30, $b->fresh()->sort_order);
    }

    public function test_create_payment_schedule_for_credit_card(): void
    {
        $card = FinanceAccount::create([
            'slug' => 'jp_card_sched',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => 'テストカード',
            'currency' => 'JPY',
            'sort_order' => 100,
            'is_active' => true,
        ]);

        $schedule = $this->service->createSchedule($card->id, [
            'scheduledDate' => '2026-07-20',
            'amount' => 50000,
            'memo' => '引落',
        ]);

        $this->assertSame('payment', $schedule->schedule_type);
        $this->assertSame(50000.0, (float) $schedule->amount);
    }

    public function test_create_deposit_schedule_for_bank(): void
    {
        $bank = FinanceAccount::create([
            'slug' => 'jp_bank_sched',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'テスト銀行',
            'currency' => 'JPY',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $schedule = $this->service->createSchedule($bank->id, [
            'scheduledDate' => '2026-07-25',
            'amount' => 300000,
        ]);

        $this->assertSame('deposit', $schedule->schedule_type);
    }

    public function test_cannot_create_schedule_for_cash_account(): void
    {
        $cash = FinanceAccount::create([
            'slug' => 'jp_cash_sched',
            'region' => 'jp',
            'kind' => 'cash',
            'name' => '現金',
            'currency' => 'JPY',
            'sort_order' => 50,
            'is_active' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->createSchedule($cash->id, [
            'scheduledDate' => '2026-07-20',
            'amount' => 1000,
        ]);
    }

    public function test_build_balance_totals_sums_visible_accounts(): void
    {
        $bank = FinanceAccount::create([
            'slug' => 'jp_bank_total',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 10000,
            'is_active' => true,
        ]);
        $card = FinanceAccount::create([
            'slug' => 'jp_card_total',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => 'カード',
            'currency' => 'JPY',
            'sort_order' => 2,
            'initial_balance' => -3000,
            'is_active' => true,
        ]);

        $accounts = [
            $this->service->attachSchedulesToAccountRow($this->service->accountToArray($bank->fresh()), collect()),
            $this->service->attachSchedulesToAccountRow($this->service->accountToArray($card->fresh()), collect()),
        ];

        $totals = $this->service->buildBalanceTotals($accounts);

        $this->assertSame(7000.0, $totals['totals']['JPY']);
        $this->assertSame(10000.0, $totals['assets']['JPY']);
        $this->assertSame(-3000.0, $totals['creditCards']['JPY']);
    }

    public function test_upsert_next_schedule_updates_existing_entry(): void
    {
        $card = FinanceAccount::create([
            'slug' => 'jp_card_upsert',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => 'Upsert Card',
            'currency' => 'JPY',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        $this->service->createSchedule($card->id, [
            'scheduledDate' => '2026-08-01',
            'amount' => 10000,
        ]);

        $updated = $this->service->upsertNextSchedule($card->id, [
            'scheduledDate' => '2026-08-05',
            'amount' => 12000,
        ]);

        $this->assertSame('2026-08-05', $updated->scheduled_date->format('Y-m-d'));
        $this->assertSame(12000.0, (float) $updated->amount);
        $this->assertSame(1, FinanceAccountSchedule::query()->where('account_id', $card->id)->count());
    }

    public function test_build_report_data_filters_by_month_and_tab(): void
    {
        $account = FinanceAccount::create([
            'slug' => 'jp_report_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'レポート銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        FinanceTransaction::create([
            'transaction_date' => '2026-07-10',
            'type' => 'income',
            'account_id' => $account->id,
            'amount' => 5000,
            'currency' => 'JPY',
        ]);

        FinanceTransaction::create([
            'transaction_date' => '2026-06-30',
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 1000,
            'currency' => 'JPY',
        ]);

        $report = $this->service->buildReportData([
            'tab' => 'jp',
            'year' => 2026,
            'month' => 7,
        ]);

        $this->assertSame('2026-07', $report['periodValue']);
        $this->assertSame(5000.0, $report['summary']['income']);
        $this->assertSame(0.0, $report['summary']['expense']);
        $this->assertCount(1, $report['transactions']);
        $this->assertCount(1, $report['groupedTransactions']['income']);
        $this->assertSame('レポート銀行', $report['accountBreakdown'][0]['accountName']);
    }

    public function test_build_finance_report_query_includes_period_and_tab(): void
    {
        $query = $this->service->buildFinanceReportQuery([
            'tab' => 'ph',
            'year' => 2026,
            'month' => 3,
        ]);

        $this->assertStringContainsString('/finance/report?', $query);
        $this->assertStringContainsString('tab=ph', $query);
        $this->assertStringContainsString('period=2026-03', $query);
    }
}
