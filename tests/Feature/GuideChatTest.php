<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\UsageLimitExceededException;
use App\Models\GuideTopic;
use App\Models\User;
use App\Services\CloudflareWorkersAiConfigService;
use App\Services\UserUsageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuideChatTest extends TestCase
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

    public function test_light_user_can_open_the_life_guide(): void
    {
        $user = $this->makeUser(UserRole::Light, 'guide-light@example.com');

        $this->actingAs($user)->get('/guide')
            ->assertOk()
            ->assertSee(__('生活ガイド'), false)
            ->assertSee(__('生活の知恵'), false)
            ->assertSee(__('料理レシピ'), false)
            ->assertSee(__('カレンダー'), false)
            ->assertSee(__('＋ 話題を追加'), false)
            ->assertDontSee(__('AI に路線を相談'), false)
            ->assertDontSee(__('AI に渡航を相談'), false);
    }

    public function test_user_can_add_edit_and_delete_a_topic(): void
    {
        $user = $this->makeUser(UserRole::Standard, 'guide-topics@example.com');

        $this->actingAs($user)->post('/guide/topics', [
            'label' => '家庭菜園',
            'icon' => '🌱',
            'instruction' => 'ベランダ栽培を前提に短く答えて',
        ])->assertRedirect();

        $row = GuideTopic::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('家庭菜園', $row->label);

        $this->actingAs($user)->get('/guide?topic='.$row->topicId())
            ->assertOk()
            ->assertSee('家庭菜園', false)
            ->assertSee(__('この話題を編集'), false);

        $this->actingAs($user)->post('/guide/topics/'.$row->id.'/update', [
            'label' => 'ベランダ菜園',
            'icon' => '🌿',
        ])->assertRedirect();
        $this->assertSame('ベランダ菜園', $row->fresh()->label);

        $this->actingAs($user)->post('/guide/topics/'.$row->id.'/delete')->assertRedirect();
        $this->assertDatabaseMissing('guide_topics', ['id' => $row->id]);
    }

    public function test_topics_belong_to_their_owner(): void
    {
        $owner = $this->makeUser(UserRole::Standard, 'guide-owner@example.com');
        $other = $this->makeUser(UserRole::Standard, 'guide-other@example.com');

        $this->actingAs($owner)->post('/guide/topics', ['label' => '秘密の話題'])->assertRedirect();
        $row = GuideTopic::query()->where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($other)->get('/guide')->assertOk()->assertDontSee('秘密の話題', false);
        $this->actingAs($other)->post('/guide/topics/'.$row->id.'/delete')->assertNotFound();
        $this->assertDatabaseHas('guide_topics', ['id' => $row->id]);
    }

    public function test_custom_topic_can_be_asked(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['response' => '水やりは朝がおすすめです。'],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'guide-custom-admin@example.com');
        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => '7d9d8a36d8aacca7fc28fea91d0945c9',
            'api_token' => 'cf-token-test',
        ])->assertRedirect();

        $user = $this->makeUser(UserRole::Standard, 'guide-custom@example.com');
        $this->actingAs($user)->post('/guide/topics', ['label' => '家庭菜園'])->assertRedirect();
        $row = GuideTopic::query()->where('user_id', $user->id)->firstOrFail();

        $this->actingAs($user)
            ->postJson('/guide/ask', ['topic' => $row->topicId(), 'prompt' => '水やりの時間は？'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_daily_limit_applies_below_super_admin_but_not_to_super_admin(): void
    {
        config(['usage_limits.workers_ai_requests_per_day' => 3]);
        $limits = app(UserUsageLimitService::class);

        $admin = $this->makeUser(UserRole::Admin, 'guide-limit-admin@example.com');
        $superAdmin = $this->makeUser(UserRole::SuperAdmin, 'guide-limit-super@example.com');

        $this->assertSame(3, $limits->limitForUser($admin, UserUsageLimitService::FEATURE_WORKERS_AI));
        $this->assertSame(0, $limits->limitForUser($superAdmin, UserUsageLimitService::FEATURE_WORKERS_AI));

        for ($i = 0; $i < 5; $i++) {
            $limits->consume($superAdmin, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
        }
        $this->assertSame(5, $limits->usedToday($superAdmin, UserUsageLimitService::FEATURE_WORKERS_AI));

        $limits->consume($admin, UserUsageLimitService::FEATURE_WORKERS_AI, 3);
        $this->expectException(UsageLimitExceededException::class);
        $limits->consume($admin, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
    }

    public function test_super_admin_guide_screen_shows_no_limit(): void
    {
        $superAdmin = $this->makeUser(UserRole::SuperAdmin, 'guide-super-view@example.com');

        $this->actingAs($superAdmin)->get('/guide')
            ->assertOk()
            ->assertSee(__('上限なし'), false);
    }

    public function test_explicit_empty_menus_block_the_guide(): void
    {
        $user = User::create([
            'email' => 'guide-none@example.com',
            'display_name' => 'None',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
            'menu_features' => [],
        ]);

        $this->actingAs($user)->get('/guide')->assertForbidden();
    }

    public function test_life_guide_asks_workers_ai_and_records_usage(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['response' => '窓を少し開けて換気してください。'],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'guide-admin@example.com');
        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => '7d9d8a36d8aacca7fc28fea91d0945c9',
            'api_token' => 'cf-token-test',
            'model' => CloudflareWorkersAiConfigService::DEFAULT_MODEL,
        ])->assertRedirect();

        $user = $this->makeUser(UserRole::Light, 'guide-user@example.com');
        $this->actingAs($user)
            ->postJson('/guide/ask', [
                'topic' => 'life',
                'prompt' => '部屋が蒸しています。',
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'text' => '窓を少し開けて換気してください。',
            ]);

        $this->assertDatabaseHas('user_daily_usages', [
            'user_id' => $user->id,
            'feature' => 'workers_ai',
            'amount' => 1,
        ]);

        // 2 回目は集計行を加算する（upsert の式が壊れていないこと）
        $this->actingAs($user)
            ->postJson('/guide/ask', ['topic' => 'life', 'prompt' => 'もう一度'])
            ->assertOk();

        $this->assertDatabaseHas('integration_usage_dailies', [
            'provider' => 'workers_ai',
            'metric' => 'requests',
            'usage_date' => now()->toDateString(),
            'amount' => 2,
        ]);
    }

    public function test_transit_and_travel_moved_out_of_the_guide(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['response' => '地下鉄が早いです。'],
            ], 200),
        ]);

        $admin = $this->makeUser(UserRole::Admin, 'guide-move-admin@example.com');
        $this->actingAs($admin)->post('/settings/ai/workers-ai', [
            'enabled' => '1',
            'account_id' => '7d9d8a36d8aacca7fc28fea91d0945c9',
            'api_token' => 'cf-token-test',
        ])->assertRedirect();

        $user = $this->makeUser(UserRole::Standard, 'guide-transit@example.com');

        // 生活ガイドの話題としては受け付けない
        $this->actingAs($user)
            ->postJson('/guide/ask', ['topic' => 'transit', 'prompt' => '天神まで'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        // 路線検索 / Travel の画面からは使える
        $this->actingAs($user)->get('/transit')->assertOk()->assertSee(__('AI に路線を相談'), false);
        $this->actingAs($user)
            ->postJson('/transit/ai-ask', ['prompt' => '天神から博多まで'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Travel は Standard では既定オフなので管理者で確認する
        $this->actingAs($admin)->get('/travel')->assertOk()->assertSee(__('AI に渡航を相談'), false);
        $this->actingAs($admin)
            ->postJson('/travel/ai-ask', ['prompt' => 'いつ買うと安い？'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
