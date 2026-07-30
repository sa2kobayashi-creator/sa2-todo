<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarOAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create([
            'email' => 'admin-gcal@example.com',
            'display_name' => 'Admin',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
        ]);
    }

    public function test_guest_cannot_connect(): void
    {
        $this->get('/settings/google-calendar/connect')
            ->assertRedirect('/login');
    }

    public function test_connect_requires_oauth_config(): void
    {
        config([
            'services.google.client_id' => '',
            'services.google.client_secret' => '',
            'services.google.redirect' => '',
        ]);

        $user = $this->makeAdmin();
        $response = $this->actingAs($user)->get('/settings/google-calendar/connect');
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/settings?section=integration', $location);
        $this->assertStringContainsString('error=', $location);
    }

    public function test_connect_redirects_to_google_with_state(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/calendar/callback',
        ]);

        $user = $this->makeAdmin();
        $response = $this->actingAs($user)->get('/settings/google-calendar/connect');
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('accounts.google.com', $location);
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertStringContainsString('state=', $location);
        $this->assertNotEmpty(session(GoogleCalendarOAuthService::SESSION_STATE_KEY));
    }

    public function test_callback_rejects_invalid_state(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/calendar/callback',
        ]);

        $user = $this->makeAdmin();
        $this->actingAs($user)
            ->withSession([GoogleCalendarOAuthService::SESSION_STATE_KEY => 'expected-state'])
            ->get('/auth/google/calendar/callback?code=abc&state=wrong')
            ->assertRedirect();

        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_callback_saves_connection_and_probes_calendar(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/calendar/callback',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token-value',
                'refresh_token' => 'refresh-token-value',
                'expires_in' => 3600,
                'scope' => 'openid email https://www.googleapis.com/auth/calendar.events',
                'token_type' => 'Bearer',
            ], 200),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-sub-123',
                'email' => 'work@example.com',
            ], 200),
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [[
                    'id' => 'evt1',
                    'summary' => 'standup',
                    'start' => ['dateTime' => '2026-07-30T10:00:00+09:00'],
                    'end' => ['dateTime' => '2026-07-30T10:30:00+09:00'],
                ]],
            ], 200),
        ]);

        $user = $this->makeAdmin();
        $this->actingAs($user)
            ->withSession([GoogleCalendarOAuthService::SESSION_STATE_KEY => 'good-state'])
            ->get('/auth/google/calendar/callback?code=auth-code&state=good-state')
            ->assertRedirect();

        $row = GoogleCalendarConnection::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('google-sub-123', $row->google_user_id);
        $this->assertSame('work@example.com', $row->google_email);
        $this->assertSame('access-token-value', $row->access_token);
        $this->assertSame('refresh-token-value', $row->refresh_token);
    }

    public function test_disconnect_removes_only_own_connection(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/calendar/callback',
        ]);

        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $user = $this->makeAdmin();
        $other = User::create([
            'email' => 'other-gcal@example.com',
            'display_name' => 'Other',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
        ]);

        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'google_user_id' => 'sub-user',
            'google_email' => 'user@example.com',
            'access_token' => 'a1',
            'refresh_token' => 'r1',
            'token_expires_at' => now()->addHour(),
            'scopes' => 'openid email',
        ]);
        GoogleCalendarConnection::create([
            'user_id' => $other->id,
            'google_user_id' => 'sub-other',
            'google_email' => 'other@example.com',
            'access_token' => 'a2',
            'refresh_token' => 'r2',
            'token_expires_at' => now()->addHour(),
            'scopes' => 'openid email',
        ]);

        $this->actingAs($user)
            ->post('/settings/google-calendar/disconnect')
            ->assertRedirect();

        $this->assertDatabaseMissing('google_calendar_connections', ['user_id' => $user->id]);
        $this->assertDatabaseHas('google_calendar_connections', ['user_id' => $other->id]);
    }

    public function test_refresh_updates_access_token(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/calendar/callback',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $user = $this->makeAdmin();
        $connection = GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'google_user_id' => 'sub-refresh',
            'google_email' => 'user@example.com',
            'access_token' => 'old-access',
            'refresh_token' => 'refresh-me',
            'token_expires_at' => now()->subMinute(),
            'scopes' => 'openid email https://www.googleapis.com/auth/calendar.events',
        ]);

        $oauth = app(GoogleCalendarOAuthService::class);
        $fresh = $oauth->refreshAccessToken($connection);
        $this->assertSame('new-access', $fresh->access_token);
        $this->assertTrue($fresh->token_expires_at->isFuture());
    }
}
