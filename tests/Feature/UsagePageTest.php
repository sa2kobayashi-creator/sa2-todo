<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsagePageTest extends TestCase
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

    public function test_usage_page_shows_free_tier_values_for_integrations_and_storage(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'usage-free-tier@example.com');

        $this->actingAs($admin)
            ->get('/settings?section=usage')
            ->assertOk()
            ->assertSee('無料枠', false)
            ->assertSee('ユーザーあたり1日 30 回', false)
            ->assertSee('1日 50,000 文字', false)
            ->assertSee('月 500,000 文字', false)
            ->assertSee('10,000 単位/日', false)
            ->assertSee('月 200 通', false)
            ->assertSee('月 1,000 参加者分', false)
            ->assertSee('Light', false)
            ->assertSee('Standard', false)
            ->assertSee('公式APIの使用量', false)
            ->assertSee('Gemini の API キーでは使用量を取得できません', false)
            ->assertSee('単価（目安）', false)
            ->assertSee('今月見込', false)
            ->assertSee('現在の料金一覧（目安）', false)
            ->assertSee('スタンダード（月額）', false)
            ->assertSee('運営原価の見積単価', false)
            ->assertDontSee('アプリ上限 ユーザーあたり1日 10 回。Stability', false);
    }

    public function test_usage_meter_shows_estimated_yen_from_unit_price(): void
    {
        $user = $this->makeUser(UserRole::Admin, 'usage-yen@example.com');
        $policies = app(\App\Services\UsageLimitPolicyService::class);
        $templates = $policies->suggestedTemplates();
        $platform = $policies->suggestedPlatform();
        $platform['yen_per_translate_1000'] = 2;
        $policies->save($templates, $platform);

        app(\App\Services\UserUsageLimitService::class)
            ->consume($user, \App\Services\UserUsageLimitService::FEATURE_TRANSLATE, 2500);

        $summary = $policies->remainingSummary($user, app(\App\Services\UserUsageLimitService::class));
        $this->assertSame(2, $summary['meters']['translate']['unit_yen']);
        $this->assertSame(6, $summary['meters']['translate']['estimated_yen']); // ceil(2500/1000)*2
        $this->assertGreaterThanOrEqual(6, $summary['estimated_yen_total']);

        $this->actingAs($user)
            ->get('/settings?section=usage')
            ->assertOk()
            ->assertSee('¥6', false);
    }
}
