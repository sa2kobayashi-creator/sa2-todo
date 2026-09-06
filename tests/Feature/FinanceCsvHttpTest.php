<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\FinanceCsvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinanceCsvHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_export_or_import(): void
    {
        $this->get('/finance/export')->assertRedirect();
        $this->post('/finance/import')->assertRedirect();
    }

    public function test_standard_user_sees_simple_csv_and_quick_entry_without_import(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'finance-csv-ui@example.com');

        $this->actingAs($user)->get('/finance')
            ->assertOk()
            ->assertSee('Excel / CSV', false)
            ->assertSee('今月の取引をエクスポート', false)
            ->assertSee('ひな形をダウンロード', false)
            ->assertSee('かんたん入力', false)
            ->assertSee('日付・種別・口座・金額・メモを入れて登録', false)
            ->assertSee('列は「日付,種別,口座,カテゴリー,金額,メモ」です', false)
            ->assertDontSee('取引インポート（運営）', false)
            ->assertDontSee('予算監視形式でエクスポート', false)
            ->assertDontSee('口座マスターをエクスポート', false)
            ->assertDontSee('口座マスターインポート', false)
            ->assertDontSee('クレカ欄の金額も支出として取り込む', false);
    }

    public function test_operator_sees_import_form(): void
    {
        $user = $this->makeUser(UserRole::SuperAdmin, 'finance-csv-operator-ui@example.com');

        $this->actingAs($user)->get('/finance')
            ->assertOk()
            ->assertSee('取引インポート（運営）', false)
            ->assertSee('以前のCSVインポート分を置き換え', false)
            ->assertSee('action="/finance/import"', false);
    }

    public function test_standard_user_can_export_transactions_and_template(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'finance-csv-export@example.com');
        $account = $this->makeAccount($user, '現金');
        FinanceTransaction::create([
            'user_id' => $user->id,
            'transaction_date' => '2026-07-15',
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 4321,
            'currency' => 'JPY',
            'memo' => 'エクスポート確認',
        ]);

        $transactions = $this->actingAs($user)->get('/finance/export?format=transactions&period=2026-07');
        $transactions->assertOk();
        $transactions->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('finance-transactions_2026-07.csv', (string) $transactions->headers->get('content-disposition'));
        $csv = $transactions->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('日付,種別,口座,カテゴリー,金額', $csv);
        $this->assertStringContainsString('4321', $csv);
        $this->assertStringContainsString('エクスポート確認', $csv);

        $template = $this->actingAs($user)->get('/finance/export?format=template');
        $template->assertOk();
        $this->assertStringContainsString('finance-transactions-template.csv', (string) $template->headers->get('content-disposition'));
        $templateCsv = $template->streamedContent();
        $this->assertStringContainsString('コンビニ', $templateCsv);
        $this->assertStringContainsString('給与', $templateCsv);
    }

    public function test_removed_export_formats_are_rejected(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'finance-csv-bad-format@example.com');

        $this->actingAs($user)->get('/finance/export?format=budget_monitor')->assertStatus(400);
        $this->actingAs($user)->get('/finance/export?format=accounts')->assertStatus(400);
        $this->actingAs($user)->get('/finance/export?format=unknown')->assertStatus(400);
    }

    public function test_non_operators_cannot_import(): void
    {
        foreach ([UserRole::Standard, UserRole::Admin, UserRole::Light] as $role) {
            $user = $this->makeUser($role, 'finance-csv-import-'.$role->value.'@example.com');
            if ($role === UserRole::Light) {
                $user->menu_features = ['finance'];
                $user->save();
            }

            $csv = UploadedFile::fake()->createWithContent(
                'transactions.csv',
                "日付,種別,口座,金額,メモ\n2026-07-02,支出,現金,100,禁止\n"
            );

            $this->actingAs($user)
                ->post('/finance/import', ['csv_file' => $csv, 'returnTo' => '/finance'])
                ->assertForbidden();

            $this->assertSame(0, FinanceTransaction::query()->where('user_id', $user->id)->count());
        }
    }

    public function test_operator_can_import_transactions_csv(): void
    {
        $user = $this->makeUser(UserRole::SuperAdmin, 'finance-csv-operator-import@example.com');
        $this->makeAccount($user, '現金');
        $file = UploadedFile::fake()->createWithContent(
            'transactions.csv',
            "日付,種別,口座,カテゴリー,金額,メモ\n2026-07-02,支出,現金,食費,1500,スーパー\n"
        );

        $this->actingAs($user)
            ->from('/finance')
            ->post('/finance/import', [
                'csv_file' => $file,
                'returnTo' => '/finance',
            ])
            ->assertRedirect('/finance');

        $this->assertDatabaseHas('finance_transactions', [
            'user_id' => $user->id,
            'amount' => 1500,
            'type' => 'expense',
        ]);
        $this->assertStringContainsString(
            FinanceCsvService::IMPORT_MEMO_MARKER,
            (string) FinanceTransaction::query()->where('user_id', $user->id)->value('memo')
        );
    }

    public function test_operator_import_replace_only_deletes_previous_csv_rows(): void
    {
        $user = $this->makeUser(UserRole::SuperAdmin, 'finance-csv-replace@example.com');
        $account = $this->makeAccount($user, '現金');
        FinanceTransaction::create([
            'user_id' => $user->id,
            'transaction_date' => '2026-07-01',
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 200,
            'currency' => 'JPY',
            'memo' => '手入力',
        ]);
        FinanceTransaction::create([
            'user_id' => $user->id,
            'transaction_date' => '2026-07-01',
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 300,
            'currency' => 'JPY',
            'memo' => FinanceCsvService::IMPORT_MEMO_MARKER.' 旧CSV',
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'transactions.csv',
            "日付,種別,口座,金額,メモ\n2026-07-02,支出,現金,400,新CSV\n"
        );

        $this->actingAs($user)
            ->post('/finance/import', [
                'csv_file' => $file,
                'replace' => '1',
                'returnTo' => '/finance',
            ])
            ->assertRedirect('/finance');

        $this->assertDatabaseHas('finance_transactions', [
            'user_id' => $user->id,
            'memo' => '手入力',
        ]);
        $this->assertDatabaseMissing('finance_transactions', [
            'user_id' => $user->id,
            'memo' => FinanceCsvService::IMPORT_MEMO_MARKER.' 旧CSV',
        ]);
        $this->assertDatabaseHas('finance_transactions', [
            'user_id' => $user->id,
            'amount' => 400,
        ]);
    }

    public function test_operator_import_does_not_touch_other_users(): void
    {
        $operator = $this->makeUser(UserRole::SuperAdmin, 'finance-csv-iso-op@example.com');
        $other = $this->makeUser(UserRole::Standard, 'finance-csv-iso-other@example.com');
        $this->makeAccount($operator, '現金');
        $otherAccount = $this->makeAccount($other, '他人現金', 'other_cash');
        FinanceTransaction::create([
            'user_id' => $other->id,
            'transaction_date' => '2026-07-02',
            'type' => 'expense',
            'account_id' => $otherAccount->id,
            'amount' => 888,
            'currency' => 'JPY',
            'memo' => '他人の取引',
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'transactions.csv',
            "日付,種別,口座,金額,メモ\n2026-07-02,支出,現金,111,運営\n"
        );

        $this->actingAs($operator)
            ->post('/finance/import', ['csv_file' => $file, 'returnTo' => '/finance'])
            ->assertRedirect('/finance');

        $this->assertSame(1, FinanceTransaction::query()->where('user_id', $operator->id)->count());
        $this->assertSame(1, FinanceTransaction::query()->where('user_id', $other->id)->count());
        $this->assertDatabaseHas('finance_transactions', [
            'user_id' => $other->id,
            'memo' => '他人の取引',
        ]);
    }

    public function test_operator_import_rejects_empty_and_invalid_csv(): void
    {
        $user = $this->makeUser(UserRole::SuperAdmin, 'finance-csv-invalid@example.com');

        $this->actingAs($user)
            ->from('/finance')
            ->post('/finance/import', [
                'csv_file' => UploadedFile::fake()->createWithContent('empty.csv', ''),
                'returnTo' => '/finance',
            ])
            ->assertRedirect('/finance');

        $this->actingAs($user)
            ->from('/finance')
            ->post('/finance/import', [
                'csv_file' => UploadedFile::fake()->createWithContent(
                    'legacy.csv',
                    "Date,Day,Balance,IN,OUT\n2026/7/1,水,0,100,0\n"
                ),
                'returnTo' => '/finance',
            ])
            ->assertRedirect('/finance');

        $this->assertSame(0, FinanceTransaction::query()->where('user_id', $user->id)->count());
    }

    public function test_light_user_without_finance_cannot_reach_csv_endpoints(): void
    {
        $user = $this->makeUser(UserRole::Light, 'finance-csv-light@example.com');

        $this->actingAs($user)->get('/finance/export')->assertForbidden();
        $this->actingAs($user)->post('/finance/import')->assertForbidden();
    }

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    private function makeAccount(User $user, string $name, string $slug = 'jp_cash_csv'): FinanceAccount
    {
        return FinanceAccount::create([
            'user_id' => $user->id,
            'slug' => $slug,
            'region' => 'jp',
            'kind' => 'cash',
            'name' => $name,
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }
}
