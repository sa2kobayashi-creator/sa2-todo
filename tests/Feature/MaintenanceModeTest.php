<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MaintenanceModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
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

    public function test_super_admin_can_enable_and_disable_from_settings(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-maint@example.com');

        $this->actingAs($super)->get('/settings?section=limits')
            ->assertOk()
            ->assertSee('メンテナンスモード', false)
            ->assertSee('メンテナンスを開始', false);

        $this->actingAs($super)
            ->post('/settings/maintenance', [
                'enabled' => '1',
                'message' => '今夜メンテします',
            ])
            ->assertRedirect('/settings?section=limits');

        $this->assertTrue(app(MaintenanceModeService::class)->isEnabled());
        $this->assertSame('今夜メンテします', app(MaintenanceModeService::class)->message());

        $this->actingAs($super)->get('/settings?section=limits')
            ->assertOk()
            ->assertSee('メンテナンスを終了', false)
            ->assertSee('今夜メンテします', false)
            ->assertSee('メンテナンス中です。運営者以外の画面は停止しています。', false);

        $this->actingAs($super)
            ->post('/settings/maintenance', ['enabled' => '0'])
            ->assertRedirect('/settings?section=limits');

        $this->assertFalse(app(MaintenanceModeService::class)->isEnabled());
    }

    public function test_standard_user_cannot_toggle_maintenance(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'std-maint@example.com');

        $this->actingAs($user)
            ->post('/settings/maintenance', ['enabled' => '1'])
            ->assertForbidden();

        $this->assertFalse(app(MaintenanceModeService::class)->isEnabled());
    }

    public function test_guests_and_standard_users_see_maintenance_page(): void
    {
        app(MaintenanceModeService::class)->enable('停止中');
        $user = $this->makeUser(UserRole::Standard, 'blocked-maint@example.com');

        $this->get('/')
            ->assertStatus(503)
            ->assertSee('メンテナンス中です', false)
            ->assertSee('停止中', false);

        $this->get('/apply')->assertStatus(503);
        $this->get('/register')->assertStatus(503);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->actingAs($user)->get('/dashboard')->assertStatus(503);
        $this->actingAs($user)->get('/todos')->assertStatus(503);
    }

    public function test_login_and_legal_pages_stay_open(): void
    {
        app(MaintenanceModeService::class)->enable();

        $this->get('/login')
            ->assertOk()
            ->assertSee('ただいまメンテナンス中です。運営者のみログインできます。', false);

        $this->get('/terms')->assertOk();
        $this->get('/privacy')->assertOk();
        $this->get('/tokushoho')->assertOk();
        $this->post('/locale', ['locale' => 'en', 'redirect' => '/login'])->assertRedirect();
    }

    public function test_non_operator_cannot_sign_in_during_maintenance(): void
    {
        app(MaintenanceModeService::class)->enable();
        $user = $this->makeUser(UserRole::Standard, 'login-blocked@example.com');

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_super_admin_can_sign_in_and_use_app_during_maintenance(): void
    {
        app(MaintenanceModeService::class)->enable();
        $super = $this->makeUser(UserRole::SuperAdmin, 'ops-login@example.com');

        $this->post('/login', [
            'email' => $super->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->actingAs($super)->get('/dashboard')
            ->assertOk()
            ->assertSee('メンテナンス中です。運営者以外の画面は停止しています。', false);
    }

    public function test_webhooks_are_not_blocked(): void
    {
        app(MaintenanceModeService::class)->enable();

        $this->post('/webhooks/stripe', [])
            ->assertStatus(400)
            ->assertDontSee('メンテナンス中です', false);
        $this->post('/webhooks/line', [])
            ->assertStatus(403)
            ->assertDontSee('メンテナンス中です', false);
    }

    public function test_json_clients_get_503_payload(): void
    {
        app(MaintenanceModeService::class)->enable('JSON停止');
        $user = $this->makeUser(UserRole::Standard, 'json-maint@example.com');

        $this->actingAs($user)
            ->getJson('/dashboard')
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'JSON停止');
    }
}
