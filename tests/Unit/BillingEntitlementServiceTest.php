<?php

namespace Tests\Unit;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\BillingEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BillingEntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private BillingEntitlementService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = app(BillingEntitlementService::class);
        config([
            'photos.user_free_quota_bytes' => 20 * 1024 * 1024 * 1024,
            'photos.standard_quota_bytes' => 100 * 1024 * 1024 * 1024,
            'photos.paid_overage_enabled' => true,
        ]);
    }

    private function makeUser(UserRole $role = UserRole::Light, array $attrs = []): User
    {
        return User::create(array_merge([
            'email' => uniqid('bill-', true).'@example.com',
            'display_name' => 'Bill',
            'password' => Hash::make('password'),
            'role' => $role,
        ], $attrs));
    }

    public function test_light_user_gets_base_quota(): void
    {
        $user = $this->makeUser(UserRole::Light);

        $this->assertSame(20 * 1024 * 1024 * 1024, $this->billing->storageFreeQuotaBytes($user));
        $this->assertFalse($this->billing->hasActiveSubscription($user));
    }

    public function test_standard_role_gets_standard_quota_without_subscription_flag(): void
    {
        $user = $this->makeUser(UserRole::Standard);

        $this->assertSame(100 * 1024 * 1024 * 1024, $this->billing->storageFreeQuotaBytes($user));
    }

    public function test_light_with_active_subscription_gets_standard_quota(): void
    {
        $user = $this->makeUser(UserRole::Light);
        $this->billing->apply($user, ['subscription_status' => SubscriptionStatus::Active]);

        $this->assertTrue($this->billing->hasActiveSubscription($user->fresh()));
        $this->assertSame(100 * 1024 * 1024 * 1024, $this->billing->storageFreeQuotaBytes($user->fresh()));
    }

    public function test_expired_trial_does_not_grant_paid_access(): void
    {
        $user = $this->makeUser(UserRole::Light);
        $this->billing->apply($user, [
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse($this->billing->hasActiveSubscription($user->fresh()));
    }

    public function test_future_trial_grants_paid_access(): void
    {
        $user = $this->makeUser(UserRole::Light);
        $this->billing->apply($user, [
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(14)->toDateString(),
        ]);

        $this->assertTrue($this->billing->hasActiveSubscription($user->fresh()));
    }

    public function test_storage_overage_requires_flag_and_feature_toggle(): void
    {
        $user = $this->makeUser(UserRole::Standard);
        $this->assertFalse($this->billing->allowsPaidStorageOverage($user));

        $this->billing->apply($user, ['storage_overage_active' => true]);
        $this->assertTrue($this->billing->allowsPaidStorageOverage($user->fresh()));

        config(['photos.paid_overage_enabled' => false]);
        $this->assertFalse($this->billing->allowsPaidStorageOverage($user->fresh()));
    }

    public function test_mailbox_addon_flag(): void
    {
        $user = $this->makeUser(UserRole::Light);
        $this->assertFalse($this->billing->hasMailboxAddon($user));

        $this->billing->apply($user, ['mailbox_addon_active' => true]);
        $this->assertTrue($this->billing->hasMailboxAddon($user->fresh()));
    }
}
