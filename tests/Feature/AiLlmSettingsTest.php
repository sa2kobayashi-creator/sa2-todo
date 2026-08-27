<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSetting;
use App\Models\TranslationApiKey;
use App\Models\User;
use App\Services\AiLlmConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiLlmSettingsTest extends TestCase
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

    public function test_ai_settings_show_model_selects_and_refresh_buttons(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'llm-ui@example.com');

        $this->actingAs($admin)->get('/settings?section=ai')
            ->assertOk()
            ->assertSee('id="openai-model-select"', false)
            ->assertSee('id="gemini-model-select"', false)
            ->assertSee('最新取得', false)
            ->assertSee('gpt-4o-mini', false);
    }

    public function test_admin_can_refresh_openai_chat_models(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-4o-mini'],
                    ['id' => 'gpt-4o'],
                    ['id' => 'whisper-1'],
                    ['id' => 'text-embedding-3-small'],
                ],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'llm-models@example.com');
        $this->actingAs($admin)->post('/settings/ai/llm', [
            'enabled' => '1',
            'active_provider' => 'openai',
            'openai_api_key' => 'sk-test-openai',
            'openai_model' => 'gpt-4o-mini',
            'gemini_model' => 'gemini-2.0-flash',
        ])->assertRedirect();

        $this->actingAs($admin)->postJson('/settings/ai/llm/models', ['provider' => 'openai'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['gpt-4o-mini'])
            ->assertJsonFragment(['gpt-4o'])
            ->assertJsonMissing(['whisper-1']);

        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_LLM);
        $this->assertContains('gpt-4o', $row->setting('openai_model_options'));
        $this->assertNotContains('whisper-1', $row->setting('openai_model_options'));
    }

    public function test_admin_can_refresh_gemini_models(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models*' => Http::response([
                'models' => [
                    [
                        'name' => 'models/gemini-2.0-flash',
                        'supportedGenerationMethods' => ['generateContent'],
                    ],
                    [
                        'name' => 'models/embedding-001',
                        'supportedGenerationMethods' => ['embedContent'],
                    ],
                ],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'llm-gemini@example.com');
        $this->actingAs($admin)->post('/settings/ai/llm', [
            'enabled' => '1',
            'active_provider' => 'gemini',
            'gemini_api_key' => 'AIza-test',
            'openai_model' => 'gpt-4o-mini',
            'gemini_model' => 'gemini-2.0-flash',
        ])->assertRedirect();

        $this->actingAs($admin)->postJson('/settings/ai/llm/models', ['provider' => 'gemini'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['gemini-2.0-flash']);

        $options = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_LLM)
            ->setting('gemini_model_options');
        $this->assertContains('gemini-2.0-flash', $options);
        $this->assertNotContains('embedding-001', $options);
        $this->assertNotContains('models/gemini-2.0-flash', $options);
    }

    public function test_saving_llm_keeps_cached_model_list(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'llm-keep@example.com');
        $this->actingAs($admin)->post('/settings/ai/llm', [
            'enabled' => '1',
            'active_provider' => 'openai',
            'openai_api_key' => 'sk-keep',
            'openai_model' => 'gpt-4o-mini',
        ]);

        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_LLM);
        $row->fill([
            'settings' => array_merge($row->settingsArray(), [
                'openai_model_options' => ['gpt-4o-mini', 'gpt-4.1'],
                'openai_models_fetched_at' => '2026-08-27 12:00',
            ]),
        ]);
        $row->save();

        $this->actingAs($admin)->post('/settings/ai/llm', [
            'enabled' => '1',
            'active_provider' => 'openai',
            'openai_model' => 'gpt-4.1',
        ])->assertRedirect();

        $saved = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_LLM);
        $this->assertSame('gpt-4.1', $saved->setting('openai_model'));
        $this->assertSame(['gpt-4o-mini', 'gpt-4.1'], $saved->setting('openai_model_options'));
    }

    public function test_openai_usage_without_admin_key_explains_unavailable(): void
    {
        Http::fake([
            'https://api.openai.com/v1/organization/usage/completions*' => Http::response([
                'error' => ['message' => 'You are not allowed to access this endpoint'],
            ], 401),
            'https://api.cloudflare.com/client/v4/graphql' => Http::response(['errors' => [['message' => 'not authorized']]], 200),
            'https://api-free.deepl.com/v2/usage' => Http::response([
                'character_count' => 1234,
                'character_limit' => 500000,
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'llm-usage@example.com');
        $this->actingAs($admin)->post('/settings/ai/llm', [
            'enabled' => '1',
            'openai_api_key' => 'sk-user-key',
        ]);
        TranslationApiKey::create([
            'name' => 'DeepL Free',
            'api_key' => 'deepl-key:fx',
            'provider' => 'deepl',
            'is_active' => true,
            'priority' => 1,
        ]);

        $this->actingAs($admin)->post('/settings/ai/usage/refresh')
            ->assertRedirect('/settings?section=usage#official-ai-usage');

        $this->actingAs($admin)->get('/settings?section=usage')
            ->assertOk()
            ->assertSee('公式APIの使用量', false)
            ->assertSee('この API キーでは公式の使用量を読めません', false)
            ->assertSee('Gemini の API キーでは使用量を取得できません', false)
            ->assertSee('1,234', false)
            ->assertSee('DeepL Free', false);
    }

    public function test_openai_usage_with_admin_key_shows_monthly_tokens(): void
    {
        Http::fake([
            'https://api.openai.com/v1/organization/usage/completions*' => Http::response([
                'object' => 'page',
                'has_more' => false,
                'data' => [
                    [
                        'results' => [
                            [
                                'input_tokens' => 1000,
                                'output_tokens' => 200,
                                'num_model_requests' => 4,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'llm-usage-ok@example.com');
        $this->actingAs($admin)->post('/settings/ai/llm', [
            'enabled' => '1',
            'openai_api_key' => 'sk-admin-key',
        ]);

        $snapshot = app(AiLlmConfigService::class)->fetchOfficialUsage(AiLlmConfigService::PROVIDER_OPENAI);
        $this->assertTrue($snapshot['ok']);
        $this->assertSame(1200, $snapshot['total_tokens']);
        $this->assertSame(4, $snapshot['requests']);
    }
}
