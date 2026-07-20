<?php

namespace Tests\Unit;

use App\Models\FinanceAccount;
use App\Models\FinanceAccountSchedule;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinanceService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create([
            'email' => 'finance-unit@example.com',
            'display_name' => 'Finance Unit',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);
        $this->service = (new FinanceService)->actingAs($this->user->id);
    }

    /** @param array<string, mixed> $attrs */
    private function makeAccount(array $attrs = []): FinanceAccount
    {
        return FinanceAccount::query()->create(array_merge([
            'user_id' => $this->user->id,
            'is_active' => true,
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function makeTransaction(array $attrs = []): FinanceTransaction
    {
        return FinanceTransaction::query()->create(array_merge([
            'user_id' => $this->user->id,
        ], $attrs));
    }

    public function test_adjustment_amount_is_included_in_account_balance(): void
    {
        $account = $this->makeAccount([
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

        $this->makeTransaction([
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
        $account = $this->makeAccount([
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

    public function test_create_account_can_show_in_overview(): void
    {
        $account = $this->service->createAccount([
            'name' => '楽天銀行',
            'region' => 'jp',
            'kind' => 'bank',
            'initialBalance' => 12000,
            'showInOverview' => true,
        ]);

        $this->assertTrue($account->show_in_overview);
    }

    public function test_set_account_overview_visibility(): void
    {
        $account = $this->makeAccount([
            'slug' => 'jp_bank_overview',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '表示テスト銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
            'show_in_overview' => false,
        ]);

        $this->assertTrue($this->service->setAccountOverviewVisibility($account->id, true));
        $this->assertTrue($account->fresh()->show_in_overview);
    }

    public function test_filter_accounts_for_tab_limits_by_region(): void
    {
        $accounts = [
            ['id' => 1, 'region' => 'jp', 'kind' => 'bank', 'name' => 'JP Bank'],
            ['id' => 2, 'region' => 'ph', 'kind' => 'bank', 'name' => 'PH Bank'],
        ];

        $this->assertCount(1, $this->service->filterAccountsForTab($accounts, 'jp'));
        $this->assertSame('JP Bank', $this->service->filterAccountsForTab($accounts, 'jp')[0]['name']);
        $this->assertCount(2, $this->service->filterAccountsForTab($accounts, 'all'));
    }

    public function test_sanitize_account_filter_clears_foreign_region_account(): void
    {
        $accounts = [
            ['id' => 10, 'region' => 'ph', 'kind' => 'bank', 'name' => 'BPI'],
        ];

        $filters = [
            'tab' => 'jp',
            'year' => 2026,
            'month' => 7,
            'accountId' => 10,
        ];

        $sanitized = $this->service->sanitizeAccountFilter($filters, $accounts);

        $this->assertNull($sanitized['accountId']);
    }

    public function test_create_credit_card_with_linked_bank(): void
    {
        $bank = $this->makeAccount([
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
        $account = $this->makeAccount([
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
        $bank = $this->makeAccount([
            'slug' => 'jp_bank_del',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '削除対象銀行',
            'currency' => 'JPY',
            'sort_order' => 4,
            'is_active' => true,
        ]);
        $card = $this->makeAccount([
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
        $a = $this->makeAccount([
            'slug' => 'jp_bank_a',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'A銀行',
            'currency' => 'JPY',
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $b = $this->makeAccount([
            'slug' => 'jp_bank_b',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'B銀行',
            'currency' => 'JPY',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        $c = $this->makeAccount([
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
        $card = $this->makeAccount([
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
        $bank = $this->makeAccount([
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
        $cash = $this->makeAccount([
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
        $bank = $this->makeAccount([
            'slug' => 'jp_bank_total',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 10000,
            'is_active' => true,
        ]);
        $card = $this->makeAccount([
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

        $this->assertSame(10000.0, $totals['totals']['JPY']);
        $this->assertSame(10000.0, $totals['assets']['JPY']);
        $this->assertSame(3000.0, $totals['creditCards']['JPY']);
    }

    public function test_credit_card_balance_tracks_usage_not_assets(): void
    {
        $card = $this->makeAccount([
            'slug' => 'jp_card_usage',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => '利用額カード',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->makeTransaction([
            'transaction_date' => '2026-07-05',
            'type' => 'expense',
            'account_id' => $card->id,
            'amount' => 8000,
            'currency' => 'JPY',
        ]);

        $this->assertSame(8000.0, $this->service->calculateAccountBalance($card->fresh()));
    }

    public function test_due_payment_schedule_materializes_on_linked_bank_at_payment_date(): void
    {
        $bank = $this->makeAccount([
            'slug' => 'jp_bank_due',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '引落銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 20000,
            'is_active' => true,
        ]);
        $card = $this->makeAccount([
            'slug' => 'jp_card_due',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => '引落カード',
            'currency' => 'JPY',
            'sort_order' => 2,
            'linked_bank_id' => $bank->id,
            'is_active' => true,
        ]);

        $this->makeTransaction([
            'transaction_date' => '2026-07-01',
            'type' => 'expense',
            'account_id' => $card->id,
            'amount' => 5000,
            'currency' => 'JPY',
        ]);

        $this->service->createSchedule($card->id, [
            'scheduledDate' => '2026-07-01',
            'amount' => 5000,
        ]);

        $this->service->materializeDueSchedules();

        $this->assertSame(15000.0, $this->service->calculateAccountBalance($bank->fresh()));
        $this->assertSame(0.0, $this->service->calculateAccountBalance($card->fresh()));
        $this->assertSame(1, FinanceTransaction::query()->where('memo', 'like', '%[schedule:%')->count());
    }

    public function test_future_payment_schedule_does_not_affect_bank_balance_yet(): void
    {
        $bank = $this->makeAccount([
            'slug' => 'jp_bank_future',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '未来引落銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 20000,
            'is_active' => true,
        ]);
        $card = $this->makeAccount([
            'slug' => 'jp_card_future',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => '未来引落カード',
            'currency' => 'JPY',
            'sort_order' => 2,
            'linked_bank_id' => $bank->id,
            'is_active' => true,
        ]);

        $this->service->createSchedule($card->id, [
            'scheduledDate' => '2099-12-31',
            'amount' => 5000,
        ]);

        $this->service->materializeDueSchedules();

        $this->assertSame(20000.0, $this->service->calculateAccountBalance($bank->fresh()));
        $this->assertSame(0, FinanceTransaction::query()->where('memo', 'like', '%[schedule:%')->count());
    }

    public function test_delete_schedule_removes_materialized_transaction(): void
    {
        $bank = $this->makeAccount([
            'slug' => 'jp_bank_del_sched',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '削除テスト銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 20000,
            'is_active' => true,
        ]);
        $card = $this->makeAccount([
            'slug' => 'jp_card_del_sched',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => '削除テストカード',
            'currency' => 'JPY',
            'sort_order' => 2,
            'linked_bank_id' => $bank->id,
            'is_active' => true,
        ]);

        $schedule = $this->service->createSchedule($card->id, [
            'scheduledDate' => '2026-07-01',
            'amount' => 4000,
        ]);

        $this->service->materializeDueSchedules();
        $this->assertSame(1, FinanceTransaction::query()->where('memo', 'like', '%[schedule:%')->count());

        $this->assertTrue($this->service->deleteSchedule($schedule->id));

        $this->assertSame(0, FinanceTransaction::query()->where('memo', 'like', '%[schedule:%')->count());
        $this->assertSame(20000.0, $this->service->calculateAccountBalance($bank->fresh()));
    }

    public function test_delete_materialized_transaction_also_deletes_schedule(): void
    {
        $bank = $this->makeAccount([
            'slug' => 'jp_bank_del_txn',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '取引削除銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 20000,
            'is_active' => true,
        ]);
        $card = $this->makeAccount([
            'slug' => 'jp_card_del_txn',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => '取引削除カード',
            'currency' => 'JPY',
            'sort_order' => 2,
            'linked_bank_id' => $bank->id,
            'is_active' => true,
        ]);

        $schedule = $this->service->createSchedule($card->id, [
            'scheduledDate' => '2026-07-01',
            'amount' => 4000,
        ]);

        $this->service->materializeDueSchedules();
        $transaction = FinanceTransaction::query()->where('memo', 'like', '%[schedule:%')->first();
        $this->assertNotNull($transaction);

        $this->assertTrue($this->service->deleteTransaction($transaction->id));

        $this->assertNull(FinanceAccountSchedule::query()->find($schedule->id));
        $this->assertSame(20000.0, $this->service->calculateAccountBalance($bank->fresh()));
        $this->service->materializeDueSchedules();
        $this->assertSame(0, FinanceTransaction::query()->where('memo', 'like', '%[schedule:%')->count());
    }

    public function test_update_schedule_reapplies_materialized_transaction(): void
    {
        $bank = $this->makeAccount([
            'slug' => 'jp_bank_upd_sched',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '更新テスト銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 20000,
            'is_active' => true,
        ]);
        $card = $this->makeAccount([
            'slug' => 'jp_card_upd_sched',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => '更新テストカード',
            'currency' => 'JPY',
            'sort_order' => 2,
            'linked_bank_id' => $bank->id,
            'is_active' => true,
        ]);

        $schedule = $this->service->createSchedule($card->id, [
            'scheduledDate' => '2026-07-01',
            'amount' => 4000,
        ]);

        $this->service->materializeDueSchedules();
        $this->assertSame(16000.0, $this->service->calculateAccountBalance($bank->fresh()));

        $this->service->updateSchedule($schedule->id, [
            'scheduledDate' => '2026-07-02',
            'amount' => 3000,
        ]);

        $this->assertSame(17000.0, $this->service->calculateAccountBalance($bank->fresh()));
        $transaction = FinanceTransaction::query()->where('memo', 'like', '%[schedule:'.$schedule->id.']%')->first();
        $this->assertNotNull($transaction);
        $this->assertSame('2026-07-02', $transaction->transaction_date->format('Y-m-d'));
        $this->assertSame(3000.0, (float) $transaction->amount);
    }

    public function test_upsert_next_schedule_updates_existing_entry(): void
    {
        $card = $this->makeAccount([
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

    public function test_due_deposit_schedule_materializes_on_bank_at_deposit_date(): void
    {
        $bank = $this->makeAccount([
            'slug' => 'ph_bank_deposit',
            'region' => 'ph',
            'kind' => 'bank',
            'name' => '入金テスト銀行',
            'currency' => 'PHP',
            'sort_order' => 1,
            'initial_balance' => 1000,
            'is_active' => true,
        ]);

        $this->service->createSchedule($bank->id, [
            'scheduledDate' => '2026-07-01',
            'amount' => 182,
        ]);

        $this->service->materializeDueSchedules();

        $this->assertSame(1182.0, $this->service->calculateAccountBalance($bank->fresh()));
        $this->assertSame(1, FinanceTransaction::query()->where('memo', 'like', '%[schedule:%')->count());
        $transaction = FinanceTransaction::query()->where('account_id', $bank->id)->where('type', 'income')->first();
        $this->assertNotNull($transaction);
        $this->assertSame(182.0, (float) $transaction->amount);
    }

    public function test_future_deposit_schedule_does_not_affect_bank_balance_yet(): void
    {
        $bank = $this->makeAccount([
            'slug' => 'ph_bank_deposit_future',
            'region' => 'ph',
            'kind' => 'bank',
            'name' => '未来入金銀行',
            'currency' => 'PHP',
            'sort_order' => 1,
            'initial_balance' => 1000,
            'is_active' => true,
        ]);

        $this->service->createSchedule($bank->id, [
            'scheduledDate' => '2099-12-31',
            'amount' => 182,
        ]);

        $this->service->materializeDueSchedules();

        $this->assertSame(1000.0, $this->service->calculateAccountBalance($bank->fresh()));
        $this->assertSame(0, FinanceTransaction::query()->where('memo', 'like', '%[schedule:%')->count());
    }

    public function test_build_credit_card_usage_breakdown_separates_configured_balance(): void
    {
        $card = $this->makeAccount([
            'slug' => 'jp_card_configured',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => '設定残高カード',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 1.03,
            'adjustment_amount' => 0,
            'is_active' => true,
        ]);

        $breakdown = $this->service->buildCreditCardUsageBreakdown($card->fresh());

        $this->assertNotNull($breakdown['configured']);
        $this->assertSame(1.03, $breakdown['configured']['total']);
        $this->assertSame([], $breakdown['items']);
        $this->assertSame(1.03, $this->service->calculateAccountBalance($card->fresh()));
    }

    public function test_build_credit_card_outstanding_charges_uses_fifo_payments(): void
    {
        $card = $this->makeAccount([
            'slug' => 'jp_card_history',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => '履歴カード',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->makeTransaction([
            'transaction_date' => '2026-07-01',
            'type' => 'expense',
            'account_id' => $card->id,
            'amount' => 3760,
            'currency' => 'JPY',
            'memo' => 'cursor支払い',
        ]);

        $this->makeTransaction([
            'transaction_date' => '2026-07-07',
            'type' => 'expense',
            'account_id' => $card->id,
            'amount' => 2435,
            'currency' => 'JPY',
            'memo' => 'Ceb Pacific Ticket 支払い',
        ]);

        $history = $this->service->buildCreditCardOutstandingCharges($card->fresh());

        $this->assertCount(2, $history);
        $this->assertSame('Ceb Pacific Ticket 支払い', $history[0]['label']);
        $this->assertSame('2026/7/7', $history[0]['displayDate']);
        $this->assertSame(2435.0, $history[0]['amount']);
        $this->assertSame('cursor支払い', $history[1]['label']);
        $this->assertSame(6195.0, $this->service->calculateAccountBalance($card->fresh()));
    }

    public function test_build_credit_card_outstanding_charges_hides_paid_items(): void
    {
        $bank = $this->makeAccount([
            'slug' => 'jp_bank_hist',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '履歴銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $card = $this->makeAccount([
            'slug' => 'jp_card_hist_paid',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => '履歴カード2',
            'currency' => 'JPY',
            'sort_order' => 2,
            'linked_bank_id' => $bank->id,
            'is_active' => true,
        ]);

        $this->makeTransaction([
            'transaction_date' => date('Y-m-d', strtotime($this->service->todayIso().' -8 days')),
            'type' => 'expense',
            'account_id' => $card->id,
            'amount' => 3760,
            'currency' => 'JPY',
            'memo' => 'cursor支払い',
        ]);

        $this->makeTransaction([
            'transaction_date' => date('Y-m-d', strtotime($this->service->todayIso().' -2 days')),
            'type' => 'expense',
            'account_id' => $card->id,
            'amount' => 2435,
            'currency' => 'JPY',
            'memo' => 'Ceb Pacific Ticket 支払い',
        ]);

        $this->makeTransaction([
            'transaction_date' => $this->service->todayIso(),
            'type' => 'transfer',
            'account_id' => $bank->id,
            'to_account_id' => $card->id,
            'amount' => 3760,
            'to_amount' => 3760,
            'currency' => 'JPY',
            'to_currency' => 'JPY',
            'memo' => 'カード引落',
        ]);

        $history = $this->service->buildCreditCardOutstandingCharges($card->fresh());

        $this->assertCount(1, $history);
        $this->assertSame('Ceb Pacific Ticket 支払い', $history[0]['label']);
        $this->assertSame(2435.0, $this->service->calculateAccountBalance($card->fresh()));
    }

    public function test_build_report_data_filters_by_month_and_tab(): void
    {
        $account = $this->makeAccount([
            'slug' => 'jp_report_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'レポート銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->makeTransaction([
            'transaction_date' => '2026-07-10',
            'type' => 'income',
            'account_id' => $account->id,
            'amount' => 5000,
            'currency' => 'JPY',
        ]);

        $this->makeTransaction([
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

    public function test_format_display_memo_strips_schedule_marker_and_system_prefix(): void
    {
        $this->assertSame('BPI 給与', $this->service->formatDisplayMemo('入金予定: BPI 給与 [schedule:12]'));
        $this->assertSame('Rakuten Card Amazon', $this->service->formatDisplayMemo('カード引落: Rakuten Card Amazon [schedule:3]'));
        $this->assertSame('スーパー', $this->service->formatDisplayMemo('スーパー'));
        $this->assertSame('', $this->service->formatDisplayMemo(''));
        $this->assertSame('', $this->service->formatDisplayMemo('[予算CSV] カード利用 楽天VISAプレミアム', '楽天VISAプレミアム'));
        $this->assertSame('コンビニ', $this->service->formatDisplayMemo('[予算CSV] カード利用 楽天VISAプレミアム コンビニ', '楽天VISAプレミアム'));
        $this->assertSame('', $this->service->formatDisplayMemo('[予算CSV] 残高 Petty Cash'));
        $this->assertSame('給与', $this->service->formatDisplayMemo('[予算CSV] IN 給与'));
        $this->assertSame('', $this->service->formatDisplayMemo('[予算CSV] IN'));
    }

    public function test_future_transactions_do_not_affect_balance_until_due(): void
    {
        $account = $this->makeAccount([
            'slug' => 'future_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '未来銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 1000,
            'is_active' => true,
        ]);

        $futureDate = date('Y-m-d', strtotime($this->service->todayIso().' +10 days'));

        $this->makeTransaction([
            'transaction_date' => $futureDate,
            'type' => 'income',
            'account_id' => $account->id,
            'amount' => 5000,
            'currency' => 'JPY',
            'memo' => '給与予定',
        ]);

        $this->assertSame(1000.0, $this->service->calculateAccountBalance($account->fresh()));
    }

    public function test_future_transaction_is_marked_scheduled_in_display_array(): void
    {
        $account = $this->makeAccount([
            'slug' => 'future_display_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '表示銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $futureDate = date('Y-m-d', strtotime($this->service->todayIso().' +5 days'));
        $transaction = $this->makeTransaction([
            'transaction_date' => $futureDate,
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 1200,
            'currency' => 'JPY',
            'memo' => '来月支払',
        ]);

        $row = $this->service->transactionToArray($transaction->fresh(['account']));

        $this->assertTrue($row['isScheduled']);
        $this->assertSame('予定', $row['scheduledLabel']);
        $this->assertSame($futureDate, $row['displayDate']);
    }

    public function test_credit_card_expense_uses_payment_schedule_date_for_display(): void
    {
        $bank = $this->makeAccount([
            'slug' => 'cc_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '引落銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $card = $this->makeAccount([
            'slug' => 'cc_card',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => 'テストカード',
            'currency' => 'JPY',
            'sort_order' => 2,
            'linked_bank_id' => $bank->id,
            'is_active' => true,
        ]);

        $paymentDate = date('Y-m-d', strtotime($this->service->todayIso().' +12 days'));
        FinanceAccountSchedule::create([
            'account_id' => $card->id,
            'schedule_type' => 'payment',
            'scheduled_date' => $paymentDate,
            'amount' => 3000,
            'memo' => '引落',
        ]);

        $purchaseDate = $this->service->todayIso();
        $transaction = $this->makeTransaction([
            'transaction_date' => $purchaseDate,
            'type' => 'expense',
            'account_id' => $card->id,
            'amount' => 1500,
            'currency' => 'JPY',
            'memo' => 'Amazon',
        ]);

        $context = $this->service->buildTransactionDisplayContext(
            [['id' => $card->id, 'kind' => 'credit_card']],
            FinanceTransaction::query()->get()
        );
        $row = $this->service->transactionToArray($transaction->fresh(['account']), $context);

        $this->assertTrue($row['isScheduled']);
        $this->assertSame('予定支払', $row['scheduledLabel']);
        $this->assertSame($paymentDate, $row['displayDate']);
        $this->assertSame($purchaseDate, $row['purchaseDate']);
    }

    public function test_build_balance_after_map_tracks_running_balance_for_bank_account(): void
    {
        $account = $this->makeAccount([
            'slug' => 'balance_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '残高銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 10000,
            'adjustment_amount' => 0,
            'is_active' => true,
        ]);

        $today = $this->service->todayIso();
        $income = $this->makeTransaction([
            'transaction_date' => date('Y-m-d', strtotime($today.' -7 days')),
            'type' => 'income',
            'account_id' => $account->id,
            'amount' => 3000,
            'currency' => 'JPY',
        ]);
        $expense = $this->makeTransaction([
            'transaction_date' => date('Y-m-d', strtotime($today.' -3 days')),
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 1500,
            'currency' => 'JPY',
        ]);

        $transactions = $this->service->filterEffectiveTransactions(FinanceTransaction::query()->get());
        $map = $this->service->buildBalanceAfterMapForAccount($account, $transactions, $account->id);

        $this->assertSame(13000.0, $map[$income->id]);
        $this->assertSame(11500.0, $map[$expense->id]);
    }

    public function test_attach_balances_to_display_rows_adds_balance_after_per_transaction(): void
    {
        $account = $this->makeAccount([
            'slug' => 'attach_balance_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '付与銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 5000,
            'adjustment_amount' => 0,
            'is_active' => true,
        ]);

        $transaction = $this->makeTransaction([
            'transaction_date' => '2026-07-02',
            'type' => 'income',
            'account_id' => $account->id,
            'amount' => 2000,
            'currency' => 'JPY',
        ]);

        $rows = [[
            'id' => $transaction->id,
            'transactionDate' => '2026-07-02',
            'accountId' => $account->id,
            'currency' => 'JPY',
            'type' => 'income',
        ]];

        $result = $this->service->attachBalancesToDisplayRows(
            $rows,
            FinanceTransaction::query()->get(),
            $account->id
        );

        $this->assertSame(7000.0, $result[0]['balanceAfter']);
        $this->assertSame('JPY', $result[0]['balanceCurrency']);
    }

    public function test_calculate_account_balance_up_to_date_returns_month_opening_balance(): void
    {
        $account = $this->makeAccount([
            'slug' => 'opening_bank',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '月初銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 1000,
            'adjustment_amount' => 0,
            'is_active' => true,
        ]);

        $this->makeTransaction([
            'transaction_date' => '2026-06-28',
            'type' => 'income',
            'account_id' => $account->id,
            'amount' => 500,
            'currency' => 'JPY',
        ]);
        $this->makeTransaction([
            'transaction_date' => '2026-07-03',
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 200,
            'currency' => 'JPY',
        ]);

        $opening = $this->service->calculateAccountBalanceUpToDate(
            $account,
            FinanceTransaction::query()->get(),
            $account->id,
            '2026-07-01'
        );

        $this->assertSame(1500.0, $opening);
    }
}
