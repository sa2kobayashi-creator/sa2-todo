<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\UsageLimitPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsageLimitSettingsTest extends TestCase
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

    public function test_super_admin_can_open_and_save_limit_templates(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'limits-ops@example.com');

        $this->actingAs($super)->get('/settings?section=limits')
            ->assertOk()
            ->assertSee('制限管理', false)
            ->assertSee('テナント（契約プール）', false)
            ->assertSee('特別枠', false)
            ->assertSee('運営全体の非常停止', false);

        $this->actingAs($super)->post('/settings/limits', [
            'templates' => [
                'light' => [
                    'storage_quota_gb' => 40,
                    'translate_chars_per_day' => 1000,
                    'translate_chars_per_month' => 10000,
                    'llm_voice_requests_per_day' => 4,
                    'llm_voice_requests_per_month' => 40,
                    'workers_ai_requests_per_day' => 3,
                    'workers_ai_requests_per_month' => 30,
                ],
                'standard' => [
                    'storage_quota_gb' => 200,
                    'translate_chars_per_day' => 50000,
                    'translate_chars_per_month' => 500000,
                    'llm_voice_requests_per_day' => 30,
                    'llm_voice_requests_per_month' => 300,
                    'workers_ai_requests_per_day' => 20,
                    'workers_ai_requests_per_month' => 200,
                ],
                'tenant' => [
                    'storage_quota_gb' => 200,
                    'translate_chars_per_day' => 50000,
                    'translate_chars_per_month' => 500000,
                    'llm_voice_requests_per_day' => 30,
                    'llm_voice_requests_per_month' => 300,
                    'workers_ai_requests_per_day' => 40,
                    'workers_ai_requests_per_month' => 400,
                ],
                'special' => [
                    'storage_quota_gb' => 80,
                    'translate_chars_per_day' => 8000,
                    'translate_chars_per_month' => 80000,
                    'llm_voice_requests_per_day' => 6,
                    'llm_voice_requests_per_month' => 60,
                    'workers_ai_requests_per_day' => 5,
                    'workers_ai_requests_per_month' => 50,
                ],
            ],
            'platform' => [
                'estimated_monthly_yen_cap' => 12000,
                'yen_per_llm_voice' => 5,
                'yen_per_workers_ai' => 3,
                'yen_per_translate_1000' => 2,
            ],
        ])->assertRedirect('/settings?section=limits');

        $light = UsageLimitPolicy::query()->where('plan', 'light')->first();
        $this->assertNotNull($light);
        $this->assertSame(40, (int) ($light->limits['storage_quota_gb'] ?? 0));
        $special = UsageLimitPolicy::query()->where('plan', 'special')->first();
        $this->assertNotNull($special);
        $this->assertSame(80, (int) ($special->limits['storage_quota_gb'] ?? 0));
        $platform = UsageLimitPolicy::query()->where('plan', 'platform')->first();
        $this->assertNotNull($platform);
        $this->assertSame(12000, (int) ($platform->limits['estimated_monthly_yen_cap'] ?? 0));
    }

    public function test_admin_cannot_open_or_save_limit_management(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'limits-admin@example.com');

        $this->actingAs($admin)->get('/settings?section=limits')
            ->assertOk()
            ->assertDontSee('href="/settings?section=limits"', false)
            ->assertDontSee('制限を編集', false)
            ->assertSee('使用量', false);

        $this->actingAs($admin)->post('/settings/limits', [
            'templates' => ['light' => ['storage_quota_gb' => 1]],
        ])->assertForbidden();
    }

    public function test_super_admin_can_invite_user_onto_special_quota(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'special-ops@example.com');

        $this->actingAs($super)->get('/admin/users')
            ->assertOk()
            ->assertSee('特別枠にする', false);

        $this->actingAs($super)->post('/admin/users', [
            'displayName' => 'Guest',
            'email' => 'special-guest@example.com',
            'password' => 'password123',
            'role' => UserRole::Light->value,
            'specialQuota' => '1',
        ])->assertRedirect('/admin/users');

        $guest = User::query()->where('email', 'special-guest@example.com')->first();
        $this->assertNotNull($guest);
        $this->assertTrue($guest->hasSpecialQuota());
        $this->assertSame(UsageLimitPolicy::PLAN_SPECIAL, app(\App\Services\UsageLimitPolicyService::class)->planForUser($guest));
    }

    public function test_platform_admin_cannot_assign_special_quota(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'special-admin@example.com');

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertDontSee('特別枠にする', false);

        $this->actingAs($admin)->post('/admin/users', [
            'displayName' => 'Sneaky',
            'email' => 'sneaky-special@example.com',
            'password' => 'password123',
            'role' => UserRole::Light->value,
            'specialQuota' => '1',
        ])->assertRedirect('/admin/users');

        $created = User::query()->where('email', 'sneaky-special@example.com')->first();
        $this->assertNotNull($created);
        $this->assertFalse($created->hasSpecialQuota());
    }

    public function test_tenant_owner_cannot_assign_special_quota(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'special-tenant-ops@example.com');
        $this->actingAs($super)->post('/admin/tenants', [
            'name' => '特別枠テナント',
            'max_users' => 5,
            'allow_own_keys' => '1',
            'owner_display_name' => '代表',
            'owner_email' => 'special-tenant-owner@example.com',
            'owner_password' => 'password123',
        ]);
        $owner = User::query()->where('email', 'special-tenant-owner@example.com')->firstOrFail();

        $this->actingAs($owner)->get('/admin/users')
            ->assertOk()
            ->assertDontSee('特別枠にする', false);

        $this->actingAs($owner)->post('/admin/users', [
            'displayName' => 'Member',
            'email' => 'special-tenant-member@example.com',
            'password' => 'password123',
            'role' => UserRole::Light->value,
            'specialQuota' => '1',
        ])->assertRedirect('/admin/users');

        $member = User::query()->where('email', 'special-tenant-member@example.com')->first();
        $this->assertNotNull($member);
        $this->assertFalse($member->hasSpecialQuota());
        $this->assertSame(
            UsageLimitPolicy::PLAN_TENANT,
            app(\App\Services\UsageLimitPolicyService::class)->planForUser($member)
        );
    }

    public function test_usage_page_shows_special_quota_plan_name(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'special-usage@example.com');
        $admin->forceFill(['special_quota' => true])->save();

        $this->actingAs($admin)->get('/settings?section=usage')
            ->assertOk()
            ->assertSee('特別枠', false)
            ->assertDontSee('制限を編集', false);
    }

    public function test_usage_page_links_to_limits_for_super_admin_only(): void
    {
        $super = $this->makeUser(UserRole::SuperAdmin, 'limits-link-ops@example.com');
        $admin = $this->makeUser(UserRole::Admin, 'limits-link-admin@example.com');

        $this->actingAs($super)->get('/settings?section=usage')
            ->assertOk()
            ->assertSee('制限を編集', false)
            ->assertSee('このアカウントの枠', false);

        $this->actingAs($admin)->get('/settings?section=usage')
            ->assertOk()
            ->assertDontSee('制限を編集', false)
            ->assertSee('このアカウントの枠', false);
    }
}
