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

    /**
     * 販売時は顧客の管理者が API キーを自分で設定する。
     * 試作の鮮明化と、取得できない Facebook Messenger チャネルはスーパー管理者のまま。
     */
    public function test_admin_can_change_infrastructure_api_keys(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-infra@example.com');

        $this->actingAs($admin)->get('/settings')
            ->assertOk()
            ->assertDontSee('settings-subnav', false)
            ->assertDontSee('page-main-narrow', false);
        $this->actingAs($admin)->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee('初期設定', false)
            ->assertSee('API設定', false)
            ->assertSee('settings-setup-tabs', false)
            ->assertDontSee('settings-subnav', false)
            ->assertDontSee('page-main-narrow', false)
            ->assertSee('Google マップ（Map / Transit）', false)
            ->assertSee('Google カレンダー（OAuth アプリ）', false)
            ->assertDontSee('写真鮮明化', false);

        $this->actingAs($admin)
            ->post('/settings/api/google-maps', [
                'enabled' => '1',
                'api_key' => 'AIzaSyAdminSelfServeMapsKey000000',
            ])
            ->assertRedirect('/settings?section=enhance#google-maps-api-settings');

        $this->actingAs($admin)
            ->post('/settings/api/google-calendar', [
                'enabled' => '1',
                'client_id' => 'admin-calendar.apps.googleusercontent.com',
                'client_secret' => 'GOCSPX-admin-calendar-secret',
            ])
            ->assertRedirect('/settings?section=enhance#google-calendar-oauth-settings');

        $this->actingAs($admin)->post('/settings/storage/r2', [])->assertRedirect();
        $this->actingAs($admin)->post('/settings/ai/llm', [])->assertRedirect();
        $this->actingAs($admin)->post('/settings/api/livekit', [])->assertRedirect();
        $this->actingAs($admin)->post('/settings/api/web-push', [])->assertRedirect();
        $this->actingAs($admin)->post('/settings/messaging/line/channel', [])->assertRedirect();
        $this->actingAs($admin)->post('/settings/messaging/messenger/channel', [])->assertForbidden();
        $this->actingAs($admin)->get('/settings?section=integration')
            ->assertOk()
            ->assertSee('LINE連携設定', false)
            ->assertDontSee('Facebook Messenger 通知連携', false);
        $this->actingAs($admin)->post('/settings/enhance/stability', ['enabled' => '1'])->assertForbidden();
        $this->actingAs($admin)->post('/settings/enhance/active', ['active_provider' => 'stability'])->assertForbidden();
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

    public function test_regular_admin_can_change_registration_invite_code(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-invite-ok@example.com');

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertSee('name="inviteCode"', false)
            ->assertSee('運営者', false)
            ->assertSee('このアプリの運営を行っております', false)
            ->assertSee('問い合わせ', false)
            ->assertDontSee('見積など運営専用画面', false)
            ->assertDontSee('写真鮮明化はスーパー管理者向け', false);

        $this->actingAs($admin)->post('/admin/users/registration', [
            'inviteCode' => 'customer-secret',
        ])->assertRedirect();

        $this->assertSame('customer-secret', \App\Support\Registration::inviteCode());
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
        $this->actingAs($user)->get('/travel')->assertForbidden();
        $this->actingAs($user)->get('/map')->assertOk();
        $this->actingAs($user)->get('/mypage')->assertOk();
        $this->actingAs($user)->get('/groups')->assertOk();
        $this->actingAs($user)->get('/translate')->assertOk();
    }

    public function test_standard_user_cannot_keep_travel_even_when_menu_features_were_pinned(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'standard-travel-pin@example.com');
        $user->forceFill([
            'menu_features' => UserRole::legacyStandardMenuFeatures(),
        ])->save();

        $this->actingAs($user)->get('/travel')->assertForbidden();
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
        $this->actingAs($user)->get('/translate')->assertOk();

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
