<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSetting;
use App\Models\User;
use App\Services\GoogleMapsConfigService;
use App\Services\GoogleRoutesConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleRoutesSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_routes.api_key' => '',
            'services.google_maps.api_key' => '',
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

    public function test_api_settings_page_shows_google_routes_form_to_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-google-routes-ui@example.com');

        $this->actingAs($admin)->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee('Google Maps Routes API（路線検索の経路探索）', false)
            ->assertSee('google-routes-api-settings', false)
            ->assertDontSee('Google は日本の交通機関ルートが API の提供対象外', false);
    }

    public function test_admin_can_save_google_routes_key(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-google-routes@example.com');

        $this->actingAs($admin)
            ->post('/settings/api/google-routes', [
                'enabled' => '1',
                'api_key' => 'AIzaSyRoutesKeyForTransit00000000',
            ])
            ->assertRedirect('/settings?section=enhance#google-routes-api-settings');

        $routes = app(GoogleRoutesConfigService::class);
        $this->assertTrue($routes->isReady());
        $this->assertSame('AIzaSyRoutesKeyForTransit00000000', $routes->apiKey());
    }

    public function test_enabled_without_own_key_reuses_the_maps_key(): void
    {
        app(GoogleMapsConfigService::class)->saveConfig(true, [
            'api_key' => 'AIzaSyMapsKeySharedWithRoutes0000',
        ]);
        app(GoogleRoutesConfigService::class)->save(true, []);

        $routes = app(GoogleRoutesConfigService::class);
        $this->assertTrue($routes->isReady());
        $this->assertTrue($routes->usesMapsKeyFallback());
        $this->assertSame('AIzaSyMapsKeySharedWithRoutes0000', $routes->apiKey());
    }

    public function test_connection_test_reports_success_even_without_a_route(): void
    {
        Http::fake([
            'routes.googleapis.com/*' => Http::response(['routes' => []], 200),
        ]);

        $admin = $this->makeUser(UserRole::SuperAdmin, 'super-google-routes-test@example.com');
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyRoutesTestKey000000000000']);

        $this->actingAs($admin)
            ->postJson('/settings/api/google-routes/test')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_GOOGLE_ROUTES);
        $this->assertSame('ok', $row->last_test_status);
    }

    public function test_connection_test_explains_an_authentication_failure(): void
    {
        Http::fake([
            'routes.googleapis.com/*' => Http::response([
                'error' => ['message' => 'API key not valid', 'status' => 'PERMISSION_DENIED'],
            ], 403),
        ]);

        $admin = $this->makeUser(UserRole::SuperAdmin, 'super-google-routes-fail@example.com');
        app(GoogleRoutesConfigService::class)->save(true, ['api_key' => 'AIzaSyBadRoutesKey00000000000000']);

        $this->actingAs($admin)
            ->postJson('/settings/api/google-routes/test')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }
}
