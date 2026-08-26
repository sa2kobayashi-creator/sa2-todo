<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinanceExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $email,
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);
    }

    public function test_public_finance_does_not_show_operator_example_categories(): void
    {
        $user = $this->makeUser('finance-cat-empty@example.com');

        $this->actingAs($user)->get('/finance')
            ->assertOk()
            ->assertSee('カテゴリー管理', false)
            ->assertSee('カテゴリーはまだありません', false)
            ->assertDontSee('たばこ/酒', false)
            ->assertDontSee('>'.e('医療費').'<', false);
    }

    public function test_expense_category_crud_via_http(): void
    {
        $user = $this->makeUser('finance-cat-crud@example.com');

        $create = $this->actingAs($user)
            ->postJson('/finance/categories', ['label' => '食費']);
        $create->assertOk()->assertJsonPath('ok', true);
        $slug = $create->json('category.slug');
        $this->assertNotEmpty($slug);

        $this->actingAs($user)->get('/finance')
            ->assertOk()
            ->assertSee('食費', false);

        $this->actingAs($user)
            ->postJson('/finance/categories/'.$slug.'/update', ['label' => 'スーパー'])
            ->assertOk()
            ->assertJsonPath('category.label', 'スーパー');

        $this->actingAs($user)
            ->postJson('/finance/categories/'.$slug.'/delete')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($user)->get('/finance')
            ->assertOk()
            ->assertDontSee('>'.e('スーパー').'<', false);
    }

    public function test_used_legacy_category_appears_for_existing_transactions(): void
    {
        $user = $this->makeUser('finance-cat-legacy@example.com');
        $account = FinanceAccount::query()->create([
            'user_id' => $user->id,
            'slug' => 'jp_bank_legacy',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => 'テスト銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        FinanceTransaction::query()->create([
            'user_id' => $user->id,
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 100,
            'currency' => 'JPY',
            'category' => 'medical',
        ]);

        $this->actingAs($user)->get('/finance')
            ->assertOk()
            ->assertSee('医療費', false);
    }
}
