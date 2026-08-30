<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Exceptions\UsageLimitExceededException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UsageLimitPolicyService;
use App\Services\UserUsageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserUsageLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_consume_blocks_when_daily_limit_reached(): void
    {
        config([
            'usage_limits.translate_chars_per_day' => 10,
            'usage_limits.llm_voice_requests_per_day' => 2,
        ]);

        $user = User::create([
            'email' => 'limit@example.com',
            'display_name' => 'Limit',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $svc = app(UserUsageLimitService::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 6);
        $svc->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 4);
        $this->assertSame(10, $svc->usedToday($user, UserUsageLimitService::FEATURE_TRANSLATE));

        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 1);
    }

    public function test_voice_and_guide_counters_are_separate(): void
    {
        config([
            'usage_limits.llm_voice_requests_per_day' => 1,
            'usage_limits.workers_ai_requests_per_day' => 1,
        ]);

        $user = User::create([
            'email' => 'sep@example.com',
            'display_name' => 'Sep',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $svc = app(UserUsageLimitService::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_FINANCE, 1);
        $svc->consume($user, UserUsageLimitService::FEATURE_WORKERS_AI, 1);

        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_TODO, 1);
    }

    public function test_llm_voice_purpose_counters_share_daily_limit(): void
    {
        config(['usage_limits.llm_voice_requests_per_day' => 2]);

        $user = User::create([
            'email' => 'voice@example.com',
            'display_name' => 'Voice',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $svc = app(UserUsageLimitService::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_FINANCE, 1);
        $svc->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_NOTE, 1);

        $this->assertSame(2, $svc->usedTodayLlmVoice($user));

        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_TODO, 1);
    }

    public function test_super_admin_is_not_blocked_by_daily_limits(): void
    {
        config(['usage_limits.translate_chars_per_day' => 1]);

        $user = User::create([
            'email' => 'ops-unlimited@example.com',
            'display_name' => 'Ops',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);

        $svc = app(UserUsageLimitService::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 5);
        $this->assertSame(5, $svc->usedToday($user, UserUsageLimitService::FEATURE_TRANSLATE));
    }

    public function test_saved_templates_apply_per_plan(): void
    {
        $policies = app(UsageLimitPolicyService::class);
        $templates = $policies->suggestedTemplates();
        $templates['light']['translate_chars_per_day'] = 10;
        $templates['light']['translate_chars_per_month'] = 12;
        $templates['standard']['translate_chars_per_day'] = 100;
        $templates['standard']['translate_chars_per_month'] = 1000;
        $policies->save($templates, $policies->suggestedPlatform());

        $light = User::create([
            'email' => 'tpl-light@example.com',
            'display_name' => 'L',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);
        $standard = User::create([
            'email' => 'tpl-std@example.com',
            'display_name' => 'S',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        $svc = app(UserUsageLimitService::class);
        $this->assertSame(10, $svc->limitForUser($light, UserUsageLimitService::FEATURE_TRANSLATE));
        $this->assertSame(100, $svc->limitForUser($standard, UserUsageLimitService::FEATURE_TRANSLATE));

        $svc->consume($light, UserUsageLimitService::FEATURE_TRANSLATE, 10);
        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($light, UserUsageLimitService::FEATURE_TRANSLATE, 1);
    }

    public function test_monthly_cap_blocks_when_daily_still_has_room(): void
    {
        $policies = app(UsageLimitPolicyService::class);
        $templates = $policies->suggestedTemplates();
        $templates['light']['translate_chars_per_day'] = 100;
        $templates['light']['translate_chars_per_month'] = 8;
        $policies->save($templates, $policies->suggestedPlatform());

        $light = User::create([
            'email' => 'month-light@example.com',
            'display_name' => 'M',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $svc = app(UserUsageLimitService::class);
        $svc->consume($light, UserUsageLimitService::FEATURE_TRANSLATE, 8);
        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($light, UserUsageLimitService::FEATURE_TRANSLATE, 1);
    }

    public function test_tenant_members_share_a_pool(): void
    {
        $policies = app(UsageLimitPolicyService::class);
        $templates = $policies->suggestedTemplates();
        $templates['tenant']['workers_ai_requests_per_day'] = 2;
        $templates['tenant']['workers_ai_requests_per_month'] = 20;
        $policies->save($templates, $policies->suggestedPlatform());

        $tenant = Tenant::query()->create([
            'name' => 'Pool家',
            'slug' => 'pool-house',
            'status' => Tenant::STATUS_ACTIVE,
            'max_users' => 5,
            'allow_own_keys' => true,
        ]);

        $a = User::create([
            'email' => 'pool-a@example.com',
            'display_name' => 'A',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
            'tenant_id' => $tenant->id,
        ]);
        $b = User::create([
            'email' => 'pool-b@example.com',
            'display_name' => 'B',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
            'tenant_id' => $tenant->id,
        ]);
        $tenant->owner_user_id = $a->id;
        $tenant->save();

        $svc = app(UserUsageLimitService::class);
        $svc->consume($a, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
        $svc->consume($b, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
        $this->assertSame(2, $svc->usedToday($a, UserUsageLimitService::FEATURE_WORKERS_AI));
        $this->assertSame(2, $svc->usedToday($b, UserUsageLimitService::FEATURE_WORKERS_AI));

        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($b, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
    }

    public function test_platform_hard_stop_blocks_non_super_admin(): void
    {
        $policies = app(UsageLimitPolicyService::class);
        $platform = $policies->suggestedPlatform();
        $platform['hard_stop_all'] = true;
        $policies->save($policies->suggestedTemplates(), $platform);

        $user = User::create([
            'email' => 'stopped@example.com',
            'display_name' => 'Stop',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
        ]);

        $this->expectException(UsageLimitExceededException::class);
        app(UserUsageLimitService::class)->consume($user, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
    }

    public function test_special_quota_uses_special_template_even_for_standard_role(): void
    {
        $policies = app(UsageLimitPolicyService::class);
        $templates = $policies->suggestedTemplates();
        $templates['light']['translate_chars_per_day'] = 10;
        $templates['light']['translate_chars_per_month'] = 100;
        $templates['standard']['translate_chars_per_day'] = 100;
        $templates['standard']['translate_chars_per_month'] = 1000;
        $templates['special']['translate_chars_per_day'] = 7;
        $templates['special']['translate_chars_per_month'] = 70;
        $policies->save($templates, $policies->suggestedPlatform());

        $lightSpecial = User::create([
            'email' => 'special-light@example.com',
            'display_name' => 'SL',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
            'special_quota' => true,
        ]);
        $standardSpecial = User::create([
            'email' => 'special-std@example.com',
            'display_name' => 'SS',
            'password' => Hash::make('password'),
            'role' => UserRole::Standard,
            'special_quota' => true,
        ]);

        $svc = app(UserUsageLimitService::class);
        $this->assertSame(7, $svc->limitForUser($lightSpecial, UserUsageLimitService::FEATURE_TRANSLATE));
        $this->assertSame(7, $svc->limitForUser($standardSpecial, UserUsageLimitService::FEATURE_TRANSLATE));

        $svc->consume($lightSpecial, UserUsageLimitService::FEATURE_TRANSLATE, 7);
        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($lightSpecial, UserUsageLimitService::FEATURE_TRANSLATE, 1);
    }

    public function test_tenant_membership_overrides_special_quota_flag(): void
    {
        $policies = app(UsageLimitPolicyService::class);
        $templates = $policies->suggestedTemplates();
        $templates['special']['workers_ai_requests_per_day'] = 99;
        $templates['special']['workers_ai_requests_per_month'] = 990;
        $templates['tenant']['workers_ai_requests_per_day'] = 2;
        $templates['tenant']['workers_ai_requests_per_month'] = 20;
        $policies->save($templates, $policies->suggestedPlatform());

        $tenant = Tenant::query()->create([
            'name' => '特別枠無視',
            'slug' => 'ignore-special',
            'status' => Tenant::STATUS_ACTIVE,
            'max_users' => 5,
            'allow_own_keys' => true,
        ]);

        $member = User::create([
            'email' => 'tenant-special@example.com',
            'display_name' => 'TS',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
            'tenant_id' => $tenant->id,
            'special_quota' => true,
        ]);
        $tenant->owner_user_id = $member->id;
        $tenant->save();

        $svc = app(UserUsageLimitService::class);
        $this->assertSame(2, $svc->limitForUser($member, UserUsageLimitService::FEATURE_WORKERS_AI));
        $svc->consume($member, UserUsageLimitService::FEATURE_WORKERS_AI, 2);
        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($member, UserUsageLimitService::FEATURE_WORKERS_AI, 1);
    }

    public function test_unsaved_policies_use_suggested_monthly_limits_on_mypage_summary(): void
    {
        $user = User::create([
            'email' => 'unsaved-month@example.com',
            'display_name' => 'Unsaved',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $policies = app(UsageLimitPolicyService::class);
        $this->assertSame(200_000, $policies->monthlyLimit($user, UsageLimitPolicyService::FEATURE_TRANSLATE));
        $this->assertSame(100, $policies->monthlyLimit($user, UsageLimitPolicyService::FEATURE_LLM_VOICE));
        $this->assertSame(80, $policies->monthlyLimit($user, UsageLimitPolicyService::FEATURE_WORKERS_AI));

        $summary = $policies->remainingSummary($user, app(UserUsageLimitService::class));
        $this->assertSame(200_000, $summary['meters']['translate']['monthly_limit']);
        $this->assertSame('light', $summary['plan']);
        $this->assertSame(100, $summary['meters'][UsageLimitPolicyService::FEATURE_ROUTE_SEARCH]['monthly_limit']);
        $this->assertSame(50, $summary['meters'][UsageLimitPolicyService::FEATURE_YOUTUBE]['monthly_limit']);
        $this->assertSame(30, $summary['meters'][UsageLimitPolicyService::FEATURE_CLOUDINARY]['monthly_limit']);
        $this->assertSame(20, $summary['meters'][UsageLimitPolicyService::FEATURE_LIVEKIT]['monthly_limit']);
        $this->assertSame(2000, $summary['meters'][UsageLimitPolicyService::FEATURE_MAPS]['monthly_limit']);
        $this->assertSame(2000, $summary['meters'][UsageLimitPolicyService::FEATURE_NOTIFY]['monthly_limit']);
        $this->assertSame(5000, $summary['meters'][UsageLimitPolicyService::FEATURE_VIDEO_PLAY]['monthly_limit']);
        $this->assertSame(500, $summary['meters'][UsageLimitPolicyService::FEATURE_ATTACHMENT]['monthly_limit']);
        $this->assertSame([], $summary['warnings']);
    }

    public function test_monthly_usage_warnings_at_80_90_and_100_percent(): void
    {
        $policies = app(UsageLimitPolicyService::class);
        $templates = $policies->suggestedTemplates();
        $templates['light']['translate_chars_per_month'] = 100;
        $policies->save($templates, $policies->suggestedPlatform());

        $user = User::create([
            'email' => 'warn-month@example.com',
            'display_name' => 'Warn',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $svc = app(UserUsageLimitService::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 80);
        $summary = $policies->remainingSummary($user, $svc);
        $this->assertSame('warn', $summary['meters']['translate']['warn_level']);
        $this->assertStringContainsString('80%', (string) $summary['meters']['translate']['warn_message']);
        $this->assertCount(1, $summary['warnings']);

        $svc->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 10);
        $summary = $policies->remainingSummary($user, $svc);
        $this->assertSame('critical', $summary['meters']['translate']['warn_level']);
        $this->assertStringContainsString('残り', (string) $summary['meters']['translate']['warn_message']);

        $svc->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 10);
        $summary = $policies->remainingSummary($user, $svc);
        $this->assertSame('stopped', $summary['meters']['translate']['warn_level']);
        $this->assertStringContainsString('停止', (string) $summary['meters']['translate']['warn_message']);
    }

    public function test_route_search_consume_is_blocked_by_daily_limit(): void
    {
        config(['usage_limits.route_search_requests_per_day' => 1]);

        $user = User::create([
            'email' => 'route-limit@example.com',
            'display_name' => 'Route',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $svc = app(UserUsageLimitService::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_ROUTE_SEARCH, 1);
        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_ROUTE_SEARCH, 1);
    }

    public function test_maps_notify_video_and_attachment_consume_respect_daily_limits(): void
    {
        config([
            'usage_limits.maps_requests_per_day' => 1,
            'usage_limits.notify_requests_per_day' => 1,
            'usage_limits.video_play_requests_per_day' => 1,
            'usage_limits.attachment_requests_per_day' => 1,
        ]);

        $user = User::create([
            'email' => 'extra-meters@example.com',
            'display_name' => 'Extra',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $svc = app(UserUsageLimitService::class);
        foreach ([
            UserUsageLimitService::FEATURE_MAPS,
            UserUsageLimitService::FEATURE_NOTIFY,
            UserUsageLimitService::FEATURE_VIDEO_PLAY,
            UserUsageLimitService::FEATURE_ATTACHMENT,
        ] as $feature) {
            $svc->consume($user, $feature, 1);
            $blocked = false;
            try {
                $svc->consume($user, $feature, 1);
            } catch (UsageLimitExceededException) {
                $blocked = true;
            }
            $this->assertTrue($blocked, 'Expected UsageLimitExceededException for '.$feature);
        }
    }

    public function test_explicit_zero_monthly_limit_stays_unlimited(): void
    {
        $policies = app(UsageLimitPolicyService::class);
        $templates = $policies->suggestedTemplates();
        $templates['light']['translate_chars_per_month'] = 0;
        $policies->save($templates, $policies->suggestedPlatform());

        $user = User::create([
            'email' => 'zero-month@example.com',
            'display_name' => 'Zero',
            'password' => Hash::make('password'),
            'role' => UserRole::Light,
        ]);

        $this->assertSame(0, $policies->monthlyLimit($user, UsageLimitPolicyService::FEATURE_TRANSLATE));
    }
}
