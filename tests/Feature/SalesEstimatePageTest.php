<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SalesEstimatePageTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role): User
    {
        return User::create([
            'email' => $role->value.'-sales@example.com',
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_super_admin_can_open_estimate_preview(): void
    {
        $user = $this->makeUser(UserRole::SuperAdmin);

        $this->actingAs($user)
            ->get('/admin/sales/estimate?client_name='.rawurlencode('テスト株式会社').'&users=7&include_mailbox=1')
            ->assertOk()
            ->assertSee('専用インスタンス お見積書', false)
            ->assertSee('テスト株式会社', false)
            ->assertSee('¥50,000', false)
            ->assertSee('見積（専用）', false);
    }

    public function test_admin_cannot_open_estimate_preview(): void
    {
        $user = $this->makeUser(UserRole::Admin);

        $this->actingAs($user)
            ->get('/admin/sales/estimate')
            ->assertForbidden();
    }
}
