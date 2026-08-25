<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSetting;
use App\Models\User;
use App\Services\NavitimeConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NavitimeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.navitime.api_key' => '',
            'services.navitime.base_url' => '',
            'services.navitime.node_host' => '',
        ]);
    }

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => $role->label(),
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_api_settings_page_shows_navitime_form_to_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-navitime-ui@example.com');

        $this->actingAs($admin)->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee('NAVITIME（路線検索の経路探索）', false)
            ->assertSee('navitime-api-settings', false);
    }

    public function test_admin_can_save_rapidapi_contract(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-navitime@example.com');

        $this->actingAs($admin)
            ->post('/settings/api/navitime', [
                'enabled' => '1',
                'mode' => 'rapidapi',
                'api_key' => 'rapid-key-000',
                'route_host' => 'https://navitime-route-totalnavi.p.rapidapi.com/route_transit',
            ])
            ->assertRedirect('/settings?section=enhance#navitime-api-settings');

        $navitime = app(NavitimeConfigService::class);
        $this->assertTrue($navitime->isReady());
        $this->assertSame('rapid-key-000', $navitime->apiKey());
        $this->assertSame('navitime-route-totalnavi.p.rapidapi.com', $navitime->routeHost());
        $this->assertSame('https://navitime-route-totalnavi.p.rapidapi.com', $navitime->routeBaseUrl());
    }

    public function test_direct_contract_requires_a_base_url(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-navitime-direct@example.com');

        $this->actingAs($admin)
            ->post('/settings/api/navitime', [
                'enabled' => '1',
                'mode' => 'direct',
                'api_key' => 'direct-key',
                'base_url' => '',
            ])
            ->assertRedirect();

        $this->assertFalse(app(NavitimeConfigService::class)->isReady());
    }

    public function test_direct_contract_uses_base_url_and_auth_header(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-navitime-direct2@example.com');

        $this->actingAs($admin)
            ->post('/settings/api/navitime', [
                'enabled' => '1',
                'mode' => 'direct',
                'api_key' => 'direct-key',
                'base_url' => 'example.navitime.biz/abcdef/v1/',
                'auth_header' => 'x-navitime-key',
            ])
            ->assertRedirect('/settings?section=enhance#navitime-api-settings');

        $navitime = app(NavitimeConfigService::class);
        $this->assertSame('https://example.navitime.biz/abcdef/v1', $navitime->routeBaseUrl());
        $this->assertSame('x-navitime-key', $navitime->authHeader());
    }

    public function test_saved_key_is_kept_when_the_masked_value_is_posted_back(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-navitime-mask@example.com');

        $this->actingAs($admin)->post('/settings/api/navitime', [
            'enabled' => '1',
            'mode' => 'rapidapi',
            'api_key' => 'rapid-key-keepme',
        ])->assertRedirect();

        $this->actingAs($admin)->post('/settings/api/navitime', [
            'enabled' => '1',
            'mode' => 'rapidapi',
            'api_key' => '••••••••',
        ])->assertRedirect();

        $this->assertSame('rapid-key-keepme', app(NavitimeConfigService::class)->apiKey());
    }

    public function test_connection_test_reports_success(): void
    {
        Http::fake([
            'navitime-route-totalnavi.p.rapidapi.com/*' => Http::response([
                'items' => [['summary' => ['move' => ['time' => 20]]]],
            ], 200),
        ]);

        $super = $this->makeUser(UserRole::SuperAdmin, 'super-navitime-test@example.com');
        app(NavitimeConfigService::class)->save(true, ['mode' => 'rapidapi'], ['api_key' => 'rapid-key-test']);

        $this->actingAs($super)
            ->postJson('/settings/api/navitime/test')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_NAVITIME);
        $this->assertSame('ok', $row->last_test_status);
    }

    public function test_connection_test_explains_an_authentication_failure(): void
    {
        Http::fake([
            'navitime-route-totalnavi.p.rapidapi.com/*' => Http::response(['message' => 'invalid key'], 403),
        ]);

        $super = $this->makeUser(UserRole::SuperAdmin, 'super-navitime-fail@example.com');
        app(NavitimeConfigService::class)->save(true, ['mode' => 'rapidapi'], ['api_key' => 'bad-key']);

        $response = $this->actingAs($super)->postJson('/settings/api/navitime/test');

        $response->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('認証に失敗', (string) $response->json('message'));
    }

    public function test_disabled_settings_are_not_used(): void
    {
        $navitime = app(NavitimeConfigService::class);
        $navitime->save(true, ['mode' => 'rapidapi'], ['api_key' => 'rapid-key-disabled']);
        $navitime->save(false, ['mode' => 'rapidapi'], []);

        $this->assertSame('', $navitime->apiKey());
        $this->assertFalse($navitime->isReady());
    }
}
