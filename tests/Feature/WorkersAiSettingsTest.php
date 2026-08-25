<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSetting;
use App\Models\User;
use App\Services\CloudflareWorkersAiConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkersAiSettingsTest extends TestCase
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

    public function test_ai_settings_show_workers_ai_form_to_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'wai-ui@example.com');

        $this->actingAs($admin)->get('/settings?section=ai')
            ->assertOk()
            ->assertSee('Cloudflare Workers AI', false)
            ->assertSee('workers-ai-settings', false);
    }

    public function test_admin_can_save_workers_ai_and_test_connection(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['response' => 'OK'],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'wai-save@example.com');
        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => '7d9d8a36d8aacca7fc28fea91d0945c9',
            'api_token' => 'workers-ai-token',
            'model' => '@cf/meta/llama-3.1-8b-instruct-fp8',
        ])->assertRedirect('/settings?section=ai#workers-ai-settings');

        $this->assertDatabaseHas('media_storage_settings', [
            'provider' => MediaStorageSetting::PROVIDER_WORKERS_AI,
            'enabled' => 1,
        ]);

        $this->actingAs($admin)->postJson('/settings/ai/workers-ai/test')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertTrue(app(CloudflareWorkersAiConfigService::class)->isReady());
    }

    public function test_pasted_curl_sample_is_reduced_to_the_account_id(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'wai-paste@example.com');
        $curl = 'curl https://api.cloudflare.com/client/v4/accounts/7d9d8a36d8aacca7fc28fea91d0945c9/ai/run/@cf/meta/llama-3.1-8b-instruct-fp8 '
            .'-H "Authorization: Bearer dummy-token-in-sample"';

        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => $curl,
            'api_token' => 'Bearer workers-ai-token',
            'model' => '',
        ])->assertRedirect('/settings?section=ai#workers-ai-settings');

        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_WORKERS_AI);
        $this->assertSame('7d9d8a36d8aacca7fc28fea91d0945c9', $row->setting('account_id'));
        $this->assertSame(CloudflareWorkersAiConfigService::DEFAULT_MODEL, $row->setting('model'));
        $this->assertSame('workers-ai-token', $row->secret('api_token'));
    }

    public function test_account_id_without_an_id_is_rejected(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'wai-bad@example.com');

        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => 'my cloudflare account',
            'api_token' => 'workers-ai-token',
        ])->assertRedirect('/settings?section=ai#workers-ai-settings')
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('media_storage_settings', [
            'provider' => MediaStorageSetting::PROVIDER_WORKERS_AI,
        ]);
    }

    public function test_authentication_error_is_explained(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'result' => null,
                'errors' => [['code' => 10000, 'message' => 'Authentication error']],
            ], 401),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'wai-auth@example.com');
        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => '7d9d8a36d8aacca7fc28fea91d0945c9',
            'api_token' => 'workers-ai-token',
        ]);

        $this->actingAs($admin)->postJson('/settings/ai/workers-ai/test')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonFragment(['message' => __('認証エラーです。API トークンに Workers AI の権限があるか、アカウント ID が合っているか確認してください。').'（Authentication error）']);
    }
}
