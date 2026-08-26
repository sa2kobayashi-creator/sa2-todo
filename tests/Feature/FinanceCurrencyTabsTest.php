<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FinanceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinanceCurrencyTabsTest extends TestCase
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

    public function test_public_finance_hides_philippines_until_currency_is_added(): void
    {
        $user = $this->makeUser('finance-currency@example.com');

        $this->actingAs($user)->get('/finance')
            ->assertOk()
            ->assertSee('家計簿', false)
            ->assertSee('通貨を追加', false)
            ->assertDontSee('フィリピンの口座・取引を表示中', false)
            ->assertDontSee('PHP の口座・取引を表示中', false)
            ->assertDontSee('>'.e('フィリピン').'</a>', false);
    }

    public function test_adding_php_creates_a_php_tab_without_operator_accounts(): void
    {
        $user = $this->makeUser('finance-add-php@example.com');

        $this->actingAs($user)
            ->post('/finance/currencies', ['region' => 'ph'])
            ->assertRedirect();

        $this->actingAs($user)->get('/finance?tab=ph')
            ->assertOk()
            ->assertSee('PHP の口座・取引を表示中', false)
            ->assertSee('現金', false)
            ->assertDontSee('BPI', false)
            ->assertSee('id="finance-open-add-currency"', false);
    }

    public function test_adding_usd_creates_a_usd_tab(): void
    {
        $user = $this->makeUser('finance-add-usd@example.com');

        $this->actingAs($user)
            ->post('/finance/currencies', ['currency' => 'USD'])
            ->assertRedirect();

        $this->actingAs($user)->get('/finance?tab=usd')
            ->assertOk()
            ->assertSee('USD の口座・取引を表示中', false)
            ->assertSee('id="finance-open-add-currency"', false);
    }

    public function test_existing_philippines_accounts_show_the_tab_as_already_added(): void
    {
        $user = $this->makeUser('finance-existing-php@example.com');
        FinanceAccount::query()->create([
            'user_id' => $user->id,
            'slug' => 'ph_bank_bpi',
            'region' => 'ph',
            'kind' => 'bank',
            'name' => 'BPI',
            'currency' => 'PHP',
            'sort_order' => 210,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/finance?tab=ph')
            ->assertOk()
            ->assertSee('PHP の口座・取引を表示中', false)
            ->assertSee('BPI', false)
            ->assertSee('id="finance-open-add-currency"', false);
    }

    public function test_php_tab_can_be_removed_without_deleting_accounts(): void
    {
        $user = $this->makeUser('finance-remove-php@example.com');
        FinanceAccount::query()->create([
            'user_id' => $user->id,
            'slug' => 'ph_bank_bpi',
            'region' => 'ph',
            'kind' => 'bank',
            'name' => 'BPI',
            'currency' => 'PHP',
            'sort_order' => 210,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post('/finance/currencies/ph/delete')
            ->assertRedirect();

        $this->actingAs($user)->get('/finance')
            ->assertOk()
            ->assertDontSee('PHP の口座・取引を表示中', false)
            ->assertSee('通貨を追加', false);

        $this->assertTrue(
            FinanceAccount::query()->where('user_id', $user->id)->where('name', 'BPI')->exists()
        );

        $this->actingAs($user)
            ->post('/finance/currencies', ['region' => 'ph'])
            ->assertRedirect();

        $this->actingAs($user)->get('/finance?tab=ph')
            ->assertOk()
            ->assertSee('BPI', false);
    }

    public function test_finance_page_javascript_does_not_smash_statements_after_json(): void
    {
        $user = $this->makeUser('finance-js-syntax@example.com');
        $this->actingAs($user)->post('/finance/currencies', ['region' => 'ph']);
        $html = $this->actingAs($user)->get('/finance?tab=ph')->assertOk()->getContent();

        $this->assertStringContainsString("rename.textContent = '✎';", $html);
        $this->assertStringContainsString("del.textContent = '×';", $html);
        $this->assertDoesNotMatchRegularExpression('/"[^"\n]*"\s+rename\.textContent/', $html);
        $this->assertDoesNotMatchRegularExpression('/"[^"\n]*"\s+del\.textContent/', $html);
        $this->assertDoesNotMatchRegularExpression('/\)return\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/"[^"\n]*"return\b/', $html);
        $this->assertStringContainsString('setCustomValidity(', $html);
        $this->assertStringContainsString('onsubmit="return confirm(', $html);

        $this->assertFinanceInlineScriptParses($html);
    }

    private function assertFinanceInlineScriptParses(string $html): void
    {
        exec('node --version', $versionOut, $versionCode);
        if ($versionCode !== 0) {
            return;
        }

        preg_match_all('/<script>(.*?)<\/script>/s', $html, $matches);
        $script = null;
        foreach ($matches[1] as $candidate) {
            if (str_contains($candidate, 'function evaluateAmountExpression')) {
                $script = $candidate;
                break;
            }
        }
        $this->assertNotNull($script, '家計簿ページのインラインスクリプトが見つかりません');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'finance-js-'.uniqid('', true).'.js';
        file_put_contents($path, $script);
        $output = [];
        $code = 0;
        exec('node --check '.escapeshellarg($path).' 2>&1', $output, $code);
        @unlink($path);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}
