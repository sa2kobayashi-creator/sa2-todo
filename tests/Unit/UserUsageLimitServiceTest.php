<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Exceptions\UsageLimitExceededException;
use App\Models\User;
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
            'usage_limits.enhance_requests_per_day' => 1,
        ]);

        $user = User::create([
            'email' => 'limit@example.com',
            'display_name' => 'Limit',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);

        $svc = app(UserUsageLimitService::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 6);
        $svc->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 4);
        $this->assertSame(10, $svc->usedToday($user, UserUsageLimitService::FEATURE_TRANSLATE));

        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_TRANSLATE, 1);
    }

    public function test_voice_and_enhance_counters_are_separate(): void
    {
        config([
            'usage_limits.llm_voice_requests_per_day' => 1,
            'usage_limits.enhance_requests_per_day' => 1,
        ]);

        $user = User::create([
            'email' => 'sep@example.com',
            'display_name' => 'Sep',
            'password' => Hash::make('password'),
            'role' => UserRole::SuperAdmin,
        ]);

        $svc = app(UserUsageLimitService::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_FINANCE, 1);
        $svc->consume($user, UserUsageLimitService::FEATURE_ENHANCE, 1);

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
            'role' => UserRole::SuperAdmin,
        ]);

        $svc = app(UserUsageLimitService::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_FINANCE, 1);
        $svc->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_NOTE, 1);

        $this->assertSame(2, $svc->usedTodayLlmVoice($user));

        $this->expectException(UsageLimitExceededException::class);
        $svc->consume($user, UserUsageLimitService::FEATURE_LLM_VOICE_TODO, 1);
    }
}
