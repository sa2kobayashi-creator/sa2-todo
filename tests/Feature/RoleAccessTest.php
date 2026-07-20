<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_admin_can_access_settings_and_user_management(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-role@example.com');

        $this->actingAs($admin)->get('/settings')->assertOk();
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('ユーザー管理');
        $this->actingAs($admin)->get('/finance')->assertOk();
    }

    public function test_standard_user_cannot_access_settings_but_can_use_apps(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'standard-role@example.com');

        $this->actingAs($user)->get('/settings')->assertForbidden();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->get('/finance')->assertOk();
        $this->actingAs($user)->get('/transit')->assertOk();
        $this->actingAs($user)->get('/map')->assertOk();
        $this->actingAs($user)->get('/mypage')->assertOk();
    }

    public function test_light_user_is_limited_to_core_features(): void
    {
        $user = $this->makeUser(UserRole::Light, 'light-role@example.com');

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/todos')->assertOk();
        $this->actingAs($user)->get('/notes')->assertOk();
        $this->actingAs($user)->get('/photos')->assertOk();
        $this->actingAs($user)->get('/mypage')->assertOk();

        $this->actingAs($user)->get('/finance')->assertForbidden();
        $this->actingAs($user)->get('/transit')->assertForbidden();
        $this->actingAs($user)->get('/map')->assertForbidden();
        $this->actingAs($user)->get('/settings')->assertForbidden();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
    }

    public function test_mypage_profile_can_be_updated(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'profile@example.com');

        $this->actingAs($user)->post('/mypage', [
            'displayName' => 'Updated Name',
            'email' => 'profile-updated@example.com',
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('Updated Name', $user->display_name);
        $this->assertSame('profile-updated@example.com', $user->email);
    }

    public function test_admin_can_create_user_with_role(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-create@example.com');

        $this->actingAs($admin)->post('/admin/users', [
            'displayName' => 'Light Member',
            'email' => 'light-member@example.com',
            'password' => 'password123',
            'role' => UserRole::Light->value,
        ])->assertRedirect();

        $created = User::query()->where('email', 'light-member@example.com')->first();
        $this->assertNotNull($created);
        $this->assertSame(UserRole::Light, $created->role);
    }
}
