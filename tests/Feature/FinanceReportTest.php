<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'finance-report@example.com',
            'display_name' => 'Finance Reporter',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);
    }

    public function test_finance_report_page_loads_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/finance/report?period=2026-07&tab=jp');

        $response->assertOk();
        $response->assertSee('入出金経費レポート');
        $response->assertSee('2026年7月');
    }

    public function test_finance_report_redirects_guest_to_login(): void
    {
        $response = $this->get('/finance/report');

        $response->assertRedirect();
    }
}
