<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    public function test_admin_can_set_registration_invite_code_from_user_management(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin-invite@example.com');

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertSee('新規登録（招待コード）');

        $this->actingAs($admin)->post('/admin/users/registration', [
            'inviteCode' => 'family-secret',
        ])->assertRedirect();

        $this->assertTrue(\App\Support\Registration::isOpen());
        $this->assertSame('family-secret', \App\Support\Registration::inviteCode());

        Mail::fake();
        auth()->logout();
        $this->post('/register', [
            'email' => 'invited@example.com',
            'displayName' => 'Invited',
            'inviteCode' => 'family-secret',
            'agreeTerms' => '1',
        ])->assertRedirect();
        $this->assertNotNull(User::query()->where('email', 'invited@example.com')->first());

        $this->actingAs($admin)->post('/admin/users/registration', [
            'clearInviteCode' => '1',
        ])->assertRedirect();

        $this->assertFalse(\App\Support\Registration::isOpen());
        auth()->logout();
        $this->post('/register', [
            'email' => 'blocked@example.com',
            'displayName' => 'Blocked',
            'inviteCode' => 'family-secret',
            'agreeTerms' => '1',
        ])->assertRedirect();
        $this->assertNull(User::query()->where('email', 'blocked@example.com')->first());
    }

    public function test_regular_admin_cannot_change_registration_invite_code(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-no-invite@example.com');

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertDontSee('name="inviteCode"', false);

        $this->actingAs($admin)->post('/admin/users/registration', [
            'inviteCode' => 'should-fail',
        ])->assertForbidden();
    }

    public function test_regular_admin_cannot_assign_super_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-assign@example.com');

        $this->actingAs($admin)->post('/admin/users', [
            'displayName' => 'Attempt Super',
            'email' => 'attempt-super@example.com',
            'password' => 'password123',
            'role' => UserRole::SuperAdmin->value,
        ])->assertSessionHasErrors('role');

        $this->assertNull(User::query()->where('email', 'attempt-super@example.com')->first());
    }

    public function test_standard_user_cannot_change_registration_invite_code(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'standard-invite@example.com');

        $this->actingAs($user)->post('/admin/users/registration', [
            'inviteCode' => 'hack',
        ])->assertForbidden();
    }

    public function test_standard_user_cannot_access_settings_but_can_use_apps(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'standard-role@example.com');

        $this->actingAs($user)->get('/settings')->assertForbidden();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->get('/finance')->assertOk();
        $this->actingAs($user)->get('/transit')->assertOk();
        $this->actingAs($user)->get('/travel')->assertOk();
        $this->actingAs($user)->get('/map')->assertOk();
        $this->actingAs($user)->get('/mypage')->assertOk();
        $this->actingAs($user)->get('/groups')->assertOk();
        $this->actingAs($user)->get('/translate')->assertForbidden();
    }

    public function test_light_user_is_limited_to_core_features(): void
    {
        $user = $this->makeUser(UserRole::Light, 'light-role@example.com');

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/todos')->assertOk();
        $this->actingAs($user)->get('/notes')->assertOk();
        $this->actingAs($user)->get('/photos')->assertOk();
        $this->actingAs($user)->get('/messages')->assertOk();
        $this->actingAs($user)->get('/mypage')->assertOk();
        $this->actingAs($user)->get('/music')->assertForbidden();
        $this->actingAs($user)->get('/video')->assertForbidden();
        $this->actingAs($user)->get('/translate')->assertForbidden();

        $this->actingAs($user)->get('/finance')->assertForbidden();
        $this->actingAs($user)->get('/transit')->assertForbidden();
        $this->actingAs($user)->get('/travel')->assertForbidden();
        $this->actingAs($user)->get('/map')->assertForbidden();
        $this->actingAs($user)->get('/settings')->assertForbidden();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->get('/groups')->assertForbidden();
    }

    public function test_mypage_profile_can_be_updated(): void
    {
        Mail::fake();
        $user = $this->makeUser(UserRole::Standard, 'profile@example.com');

        $this->actingAs($user)->post('/mypage', [
            'displayName' => 'Updated Name',
            'email' => 'profile-updated@example.com',
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('Updated Name', $user->display_name);
        // メールアドレスは新しい宛先で確認コードを入力するまで切り替わらない
        $this->assertSame('profile@example.com', $user->email);
        $this->assertSame('profile-updated@example.com', $user->pending_email);
    }

    public function test_admin_can_create_user_with_role(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin-create@example.com');

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

    public function test_admin_can_no_longer_set_another_users_password(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin-nopass@example.com');
        $member = $this->makeUser(UserRole::Light, 'member-nopass@example.com');

        $this->actingAs($admin)->get("/admin/users/{$member->id}/edit")
            ->assertOk()
            ->assertDontSee('name="password"', false);

        $this->actingAs($admin)->post("/admin/users/{$member->id}/password", [
            'password' => 'admin-chosen-pass',
            'password_confirmation' => 'admin-chosen-pass',
        ])->assertNotFound();

        $member->refresh();
        $this->assertTrue(Hash::check('password', $member->password));
    }
}
