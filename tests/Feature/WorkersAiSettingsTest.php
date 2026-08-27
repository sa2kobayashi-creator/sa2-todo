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

    public function test_connection_test_passes_when_thinking_model_returns_empty_text(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['response' => ''],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'wai-think@example.com');
        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => '7d9d8a36d8aacca7fc28fea91d0945c9',
            'api_token' => 'workers-ai-token',
            'model' => '@cf/qwen/qwen3-30b-a3b-fp8',
        ])->assertRedirect();

        $this->actingAs($admin)->postJson('/settings/ai/workers-ai/test')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', __('Workers AI に接続できました'));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/ai/run/')) {
                return false;
            }
            $tokens = (int) $request->data()['max_tokens'];

            return $tokens >= 1024;
        });
    }

    public function test_complete_strips_think_tags_from_response(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['response' => "<think>\nplan\n</think>\nこんにちは"],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'wai-strip@example.com');
        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => '7d9d8a36d8aacca7fc28fea91d0945c9',
            'api_token' => 'workers-ai-token',
            'model' => '@cf/qwen/qwen3-30b-a3b-fp8',
        ]);

        $result = app(CloudflareWorkersAiConfigService::class)->complete([
            ['role' => 'user', 'content' => 'hi'],
        ], 700);

        $this->assertTrue($result['ok']);
        $this->assertSame('こんにちは', $result['text']);
    }

    public function test_admin_can_refresh_workers_ai_text_models(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/*/ai/models/search*' => Http::response([
                'success' => true,
                'result' => [
                    [
                        'id' => '429b9e8b-d99e-44de-91ad-706cf8183658',
                        'name' => '@cf/meta/llama-3.1-8b-instruct-fp8',
                        'task' => ['name' => 'Text Generation'],
                    ],
                    [
                        'id' => '7f9a76e1-d120-48dd-a565-101d328bbb02',
                        'name' => '@cf/meta/llama-4-scout-17b-16e-instruct',
                        'task' => ['name' => 'Text Generation'],
                    ],
                ],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'wai-models@example.com');
        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => '7d9d8a36d8aacca7fc28fea91d0945c9',
            'api_token' => 'workers-ai-token',
            'model' => '@cf/meta/llama-3.1-8b-instruct-fp8',
        ])->assertRedirect();

        $this->actingAs($admin)->postJson('/settings/ai/workers-ai/models')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['@cf/meta/llama-4-scout-17b-16e-instruct']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/ai/models/search')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ! array_key_exists('hide_experimental', $query);
        });
    }

    public function test_workers_ai_usage_from_graphql_is_stored(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/graphql' => Http::response([
                'data' => [
                    'viewer' => [
                        'accounts' => [
                            [
                                'aiInferenceAdaptiveGroups' => [
                                    [
                                        'count' => 3,
                                        'sum' => [
                                            'totalInputTokens' => 80,
                                            'totalOutputTokens' => 20,
                                            'totalNeurons' => 12,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'wai-usage@example.com');
        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => '7d9d8a36d8aacca7fc28fea91d0945c9',
            'api_token' => 'workers-ai-token',
        ]);

        $snapshot = app(CloudflareWorkersAiConfigService::class)->fetchOfficialUsage();
        $this->assertTrue($snapshot['ok']);
        $this->assertSame(100, $snapshot['total_tokens']);
        $this->assertSame(3, $snapshot['requests']);
        $this->assertSame(12, $snapshot['neurons']);
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
