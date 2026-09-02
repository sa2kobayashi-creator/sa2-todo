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
        $finance = (new FinanceService)->actingAs($this->user->id);
        $this->service = (new FinanceCsvService($finance))->actingAs($this->user->id);
    }

    public function test_imports_and_exports_transactions_csv(): void
    {
        FinanceAccount::create([
            'user_id' => $this->user->id,
            'slug' => 'jp_bank_rakuten',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '楽天銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $csv = "日付,種別,口座,カテゴリー,金額,メモ\n"
            ."2026-07-02,収入,楽天銀行,,78823,給与\n"
            ."2026-07-02,支出,楽天銀行,食費,1200,コンビニ\n";

        $result = $this->service->import($csv, ['replace' => true]);

        $this->assertSame(FinanceCsvService::FORMAT_TRANSACTIONS, $result['format']);
        $this->assertSame(2, $result['created']);
        $this->assertSame('2026-07-02', $result['from']);

        $export = $this->service->export(['year' => 2026, 'month' => 7], FinanceCsvService::FORMAT_TRANSACTIONS);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $export);
        $this->assertStringContainsString('日付,種別,口座,カテゴリー,金額', $export);
        $this->assertStringContainsString('2026-07-02', $export);
        $this->assertStringContainsString('78823', $export);
        $this->assertStringContainsString('1200', $export);
    }

    public function test_replace_removes_previous_csv_imports(): void
    {
        FinanceAccount::create([
            'user_id' => $this->user->id,
            'slug' => 'jp_bank_rakuten',
            'region' => 'jp',
            'kind' => 'bank',
            'name' => '楽天銀行',
            'currency' => 'JPY',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $csv = "日付,種別,口座,カテゴリー,金額,メモ\n2026-07-02,支出,楽天銀行,,1000,テスト\n";
        $this->service->import($csv, ['replace' => true]);
        $this->assertSame(1, FinanceTransaction::query()->count());

        $result = $this->service->import($csv, ['replace' => true]);
        $this->assertSame(1, FinanceTransaction::query()->count());
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $result['created']);
    }

    public function test_exports_template_csv(): void
    {
        $csv = $this->service->export([], FinanceCsvService::FORMAT_TEMPLATE);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('日付,種別,口座,カテゴリー,金額', $csv);
        $this->assertStringContainsString('コンビニ', $csv);
    }

    public function test_rejects_unknown_export_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->export([], 'budget_monitor');
    }
}
