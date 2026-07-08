<?php

namespace Tests\Unit;

use App\Models\TranslationApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_rates_are_calculated_from_limits(): void
    {
        $key = TranslationApiKey::create([
            'name' => 'Test Key',
            'api_key' => 'test-key:fx',
            'provider' => 'deepl',
            'daily_limit' => 1000,
            'monthly_limit' => 10000,
            'current_daily_usage' => 250,
            'current_monthly_usage' => 5000,
            'is_active' => true,
        ]);

        $this->assertSame(25.0, $key->getDailyUsageRate());
        $this->assertSame(50.0, $key->getMonthlyUsageRate());
    }

    public function test_usage_rates_return_null_when_no_limit(): void
    {
        $key = TranslationApiKey::create([
            'name' => 'Unlimited',
            'api_key' => 'test-key:fx',
            'provider' => 'deepl',
            'is_active' => true,
        ]);

        $this->assertNull($key->getDailyUsageRate());
        $this->assertNull($key->getMonthlyUsageRate());
    }

    public function test_get_available_keys_excludes_inactive_and_over_limit(): void
    {
        TranslationApiKey::create([
            'name' => 'Active',
            'api_key' => 'active:fx',
            'provider' => 'deepl',
            'daily_limit' => 100,
            'current_daily_usage' => 10,
            'priority' => 1,
            'is_active' => true,
        ]);
        TranslationApiKey::create([
            'name' => 'Over daily limit',
            'api_key' => 'over:fx',
            'provider' => 'deepl',
            'daily_limit' => 100,
            'current_daily_usage' => 100,
            'is_active' => true,
        ]);
        TranslationApiKey::create([
            'name' => 'Inactive',
            'api_key' => 'inactive:fx',
            'provider' => 'deepl',
            'is_active' => false,
        ]);

        $available = TranslationApiKey::getAvailableKeys('deepl');

        $this->assertCount(1, $available);
        $this->assertSame('Active', $available->first()->name);
    }

    public function test_reset_usage_if_needed_resets_daily_counters_on_new_day(): void
    {
        $key = TranslationApiKey::create([
            'name' => 'Reset test',
            'api_key' => 'reset:fx',
            'provider' => 'deepl',
            'current_daily_usage' => 500,
            'current_monthly_usage' => 800,
            'last_reset_date' => now()->subDay()->toDateString(),
            'last_monthly_reset_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
        ]);

        $key->resetUsageIfNeeded();
        $key->refresh();

        $this->assertSame(0, $key->current_daily_usage);
        $this->assertSame(0, $key->current_monthly_usage);
        $this->assertSame(now()->toDateString(), $key->last_reset_date->toDateString());
    }

    public function test_increment_usage_updates_daily_and_monthly_counters(): void
    {
        $key = TranslationApiKey::create([
            'name' => 'Increment',
            'api_key' => 'inc:fx',
            'provider' => 'deepl',
            'current_daily_usage' => 10,
            'current_monthly_usage' => 20,
            'last_reset_date' => now()->toDateString(),
            'last_monthly_reset_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $key->incrementUsage(15);
        $key->refresh();

        $this->assertSame(25, $key->current_daily_usage);
        $this->assertSame(35, $key->current_monthly_usage);
    }
}
