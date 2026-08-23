<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSetting;
use App\Models\User;
use App\Services\GoogleCalendarConfigService;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarOauthSettingsTest extends TestCase
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

    public function test_api_settings_page_shows_google_calendar_oauth_form_to_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-gcal-ui@example.com');

        $this->actingAs($admin)->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee('Google カレンダー（OAuth アプリ）', false)
            ->assertSee('google-calendar-oauth-settings', false);
    }

    public function test_admin_can_save_google_calendar_oauth_and_connect_uses_it(): void
    {
        config([
            'services.google.client_id' => '',
            'services.google.client_secret' => '',
            'services.google.redirect' => '',
        ]);
        $admin = $this->makeUser(UserRole::Admin, 'admin-gcal-save@example.com');

        $this->actingAs($admin)
            ->post('/settings/api/google-calendar', [
                'enabled' => '1',
                'client_id' => 'saved-from-api.apps.googleusercontent.com',
                'client_secret' => 'GOCSPX-saved-from-api-settings',
                'redirect_uri' => 'https://example.test/auth/google/calendar/callback',
            ])
            ->assertRedirect('/settings?section=enhance#google-calendar-oauth-settings');

        $oauth = app(GoogleCalendarOAuthService::class);
        $this->assertTrue($oauth->isConfigured());
        $this->assertSame('saved-from-api.apps.googleusercontent.com', $oauth->clientId());
        $this->assertSame('https://example.test/auth/google/calendar/callback', $oauth->redirectUri());
    }

    public function test_standard_user_cannot_save_google_calendar_oauth(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'std-gcal-save@example.com');

        $this->actingAs($user)
            ->post('/settings/api/google-calendar', [
                'enabled' => '1',
                'client_id' => 'should-not-save.apps.googleusercontent.com',
                'client_secret' => 'GOCSPX-should-not-save',
            ])
            ->assertForbidden();
    }

    public function test_env_credentials_are_used_until_database_values_are_saved(): void
    {
        config([
            'services.google.client_id' => 'from-env.apps.googleusercontent.com',
            'services.google.client_secret' => 'GOCSPX-from-env',
            'services.google.redirect' => 'http://localhost/auth/google/calendar/callback',
        ]);

        $oauth = app(GoogleCalendarOAuthService::class);
        $this->assertTrue($oauth->isConfigured());
        $this->assertSame('from-env.apps.googleusercontent.com', $oauth->clientId());
        $this->assertTrue(app(GoogleCalendarConfigService::class)->usesEnvFallback());
    }

    public function test_connection_test_treats_invalid_grant_as_success(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'admin-gcal-test@example.com');
        app(GoogleCalendarConfigService::class)->saveConfig(true, [
            'client_id' => 'test-client.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-test-connection',
        ]);

        $this->actingAs($admin)
            ->postJson('/settings/api/google-calendar/test')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_GOOGLE_CALENDAR);
        $this->assertSame('ok', $row->last_test_status);
    }

    public function test_connection_test_fails_on_invalid_client(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_client',
            ], 401),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'admin-gcal-bad@example.com');
        app(GoogleCalendarConfigService::class)->saveConfig(true, [
            'client_id' => 'bad-client.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-bad',
        ]);

        $this->actingAs($admin)
            ->postJson('/settings/api/google-calendar/test')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }
}
