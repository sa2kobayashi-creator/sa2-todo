<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YoutubeSettingsTest extends TestCase
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

    public function test_api_settings_page_shows_youtube_form_to_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-yt-ui@example.com');

        $this->actingAs($admin)->get('/settings?section=enhance')
            ->assertOk()
            ->assertSee('YouTube検索（Data API）', false)
            ->assertSee('id="youtube-api-settings"', false);

        $this->actingAs($admin)->get('/settings?section=ai')
            ->assertOk()
            ->assertDontSee('id="youtube-api-settings"', false);
    }

    public function test_admin_can_save_youtube_key_from_api_settings(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-yt-save@example.com');

        $this->actingAs($admin)
            ->post('/settings/api/youtube', [
                'enabled' => '1',
                'api_key' => 'AIzaSyYoutubeSearchKeyForApiPage00',
            ])
            ->assertRedirect('/settings?section=enhance#youtube-api-settings');

        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_YOUTUBE);
        $this->assertTrue((bool) $row->enabled);
        $this->assertSame('AIzaSyYoutubeSearchKeyForApiPage00', $row->secret('api_key', ''));
    }

    public function test_connection_test_uses_the_saved_key(): void
    {
        Http::fake([
            'https://www.googleapis.com/youtube/v3/search*' => Http::response([
                'items' => [],
            ]),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'admin-yt-test@example.com');
        $this->actingAs($admin)->post('/settings/api/youtube', [
            'enabled' => '1',
            'api_key' => 'AIzaSyYoutubeSearchKeyForApiPage00',
        ]);

        $this->actingAs($admin)
            ->postJson('/settings/api/youtube/test')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
