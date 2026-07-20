<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceAccountSchedule;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinanceTransactionDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'finance-delete@example.com',
            'display_name' => 'Finance Deleter',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);
    }

    public function test_transaction_delete_endpoint_removes_transaction(): void
    {
        $account = FinanceAccount::create([
            'user_id' => $this->user->id,
            'slug' => 'jp_bank_feature_del',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'Feature削除銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $transaction = FinanceTransaction::create([
            'user_id' => $this->user->id,
            'transaction_date' => '2026-07-05',
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 1200,
            'currency' => 'JPY',
            'memo' => 'テスト支出',
        ]);

        $response = $this->actingAs($this->user)
            ->post('/finance/'.$transaction->id.'/delete', [
                'returnTo' => '/finance',
            ]);

        $response->assertRedirect();
        $this->assertStringStartsWith('http://localhost:8000/finance', $response->headers->get('Location'));
        $this->assertNull(FinanceTransaction::query()->find($transaction->id));
    }

    public function test_schedule_delete_endpoint_removes_schedule_and_materialized_transaction(): void
    {
        $bank = FinanceAccount::create([
            'user_id' => $this->user->id,
            'slug' => 'jp_bank_feature_sched',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'Feature予定銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'initial_balance' => 10000,
            'is_active' => true,
        ]);
        $card = FinanceAccount::create([
            'user_id' => $this->user->id,
            'slug' => 'jp_card_feature_sched',
            'region' => 'jp',
            'kind' => 'credit_card',
            'name' => 'Feature予定カード',
            'currency' => 'JPY',
            'sort_order' => 2,
            'linked_bank_id' => $bank->id,
            'is_active' => true,
        ]);

        $service = (new FinanceService)->actingAs($this->user->id);
        $schedule = $service->createSchedule($card->id, [
            'scheduledDate' => '2026-07-01',
            'amount' => 2500,
        ]);
        $service->materializeDueSchedules();

        $response = $this->actingAs($this->user)
            ->post('/finance/schedules/'.$schedule->id.'/delete', [
                'returnTo' => '/finance',
            ]);

        $response->assertRedirect();
        $this->assertStringStartsWith('http://localhost:8000/finance', $response->headers->get('Location'));
        $this->assertNull(FinanceAccountSchedule::query()->find($schedule->id));
        $this->assertSame(0, FinanceTransaction::query()->where('memo', 'like', '%[schedule:%')->count());
    }
}
