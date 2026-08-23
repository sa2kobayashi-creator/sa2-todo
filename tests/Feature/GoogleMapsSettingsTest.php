<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSetting;
use App\Models\User;
use App\Services\GoogleMapsConfigService;
use App\Services\MapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleMapsSettingsTest extends TestCase
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

    public function test_api_settings_page_shows_google_maps_form_to_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-maps-ui@example.com');

        $this->actingAs($admin)->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee('Google マップ（Map / Transit）', false)
            ->assertSee('google-maps-api-settings', false);
    }

    public function test_admin_can_save_google_maps_key(): void
    {
        config(['services.google_maps.api_key' => '']);
        $admin = $this->makeUser(UserRole::Admin, 'admin-maps@example.com');

        $this->actingAs($admin)
            ->post('/settings/api/google-maps', [
                'enabled' => '1',
                'api_key' => 'AIzaSyAdminCanSaveThisKey000000000',
            ])
            ->assertRedirect('/settings?section=enhance#google-maps-api-settings');

        $this->assertSame('AIzaSyAdminCanSaveThisKey000000000', app(MapService::class)->getApiKey());
    }

    public function test_super_admin_can_save_key_and_map_uses_it(): void
    {
        config(['services.google_maps.api_key' => '']);
        $super = $this->makeUser(UserRole::SuperAdmin, 'super-maps-save@example.com');

        $this->actingAs($super)
            ->post('/settings/api/google-maps', [
                'enabled' => '1',
                'api_key' => 'AIzaSySavedFromApiSettings00000000',
            ])
            ->assertRedirect('/settings?section=enhance#google-maps-api-settings');

        $maps = app(MapService::class);
        $this->assertSame('AIzaSySavedFromApiSettings00000000', $maps->getApiKey());
        $this->assertTrue($maps->hasApiKey());
    }

    public function test_disabled_database_key_is_not_used(): void
    {
        config(['services.google_maps.api_key' => 'AIzaSyEnvFallbackShouldNotWin00000']);
        $service = app(GoogleMapsConfigService::class);
        $service->saveConfig(true, ['api_key' => 'AIzaSyDatabaseKeyForMaps000000000']);
        $service->saveConfig(false, []);

        $maps = app(MapService::class);
        $this->assertNull($maps->getApiKey());
        $this->assertFalse($maps->hasApiKey());
    }

    public function test_env_key_is_used_until_database_key_is_saved(): void
    {
        config(['services.google_maps.api_key' => 'AIzaSyFromEnvUntilSaved0000000000']);

        $maps = app(MapService::class);
        $this->assertSame('AIzaSyFromEnvUntilSaved0000000000', $maps->getApiKey());
        $this->assertTrue(app(GoogleMapsConfigService::class)->usesEnvFallback());
    }

    public function test_connection_test_succeeds_with_geocode_ok(): void
    {
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/*' => Http::response([
                'status' => 'OK',
                'results' => [['formatted_address' => 'Fukuoka']],
            ], 200),
        ]);

        $super = $this->makeUser(UserRole::SuperAdmin, 'super-maps-test@example.com');
        app(GoogleMapsConfigService::class)->saveConfig(true, [
            'api_key' => 'AIzaSyTestConnectionKey0000000000',
        ]);

        $this->actingAs($super)
            ->postJson('/settings/api/google-maps/test')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_GOOGLE_MAPS);
        $this->assertSame('ok', $row->last_test_status);
    }
}
