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

    public function test_connection_test_succeeds_with_places_ok(): void
    {
        Http::fake([
            'places.googleapis.com/*' => Http::response([
                'suggestions' => [['placePrediction' => ['text' => ['text' => '博多駅']]]],
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

    public function test_connection_test_explains_when_places_api_is_disabled(): void
    {
        Http::fake([
            'places.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'This API is not activated on your API project. You may need to enable this API in the Google Cloud Console.',
                    'status' => 'PERMISSION_DENIED',
                ],
            ], 403),
        ]);

        $super = $this->makeUser(UserRole::SuperAdmin, 'super-maps-places-off@example.com');
        app(GoogleMapsConfigService::class)->saveConfig(true, [
            'api_key' => 'AIzaSyTestPlacesDisabledKey0000000',
        ]);

        $this->actingAs($super)
            ->postJson('/settings/api/google-maps/test')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Places API (New) が未有効です。Google マップ用キーのプロジェクトで Places API (New) を有効にしてください。');
    }
}
