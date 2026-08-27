<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\BillingEvent;
use App\Models\User;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeBillingTest extends TestCase
{
    use RefreshDatabase;

    private const STANDARD_PRICE = 'price_standard_monthly_test';

    private const MAILBOX_PRICE = 'price_mailbox_monthly_test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.enabled' => true,
            'billing.plans.standard_monthly.price_id' => self::STANDARD_PRICE,
            'billing.plans.standard_monthly.role' => UserRole::Standard->value,
            'billing.addons.mailbox_monthly.price_id' => self::MAILBOX_PRICE,
            'cashier.secret' => 'sk_test_dummy',
        ]);
    }

    private function makeUser(string $email, UserRole $role = UserRole::Light): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Member',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    /** @param list<string> $priceIds */
    private function subscriptionPayload(string $status, array $priceIds, ?int $trialEnd = null): array
    {
        return [
            'status' => $status,
            'trial_end' => $trialEnd,
            'items' => [
                'data' => array_map(fn (string $id) => ['price' => ['id' => $id]], $priceIds),
            ],
        ];
    }

    public function test_an_active_subscription_grants_standard_and_the_mailbox_addon(): void
    {
        $user = $this->makeUser('billing-active@example.com');

        app(StripeBillingService::class)->applySubscription(
            $user,
            $this->subscriptionPayload('active', [self::STANDARD_PRICE, self::MAILBOX_PRICE])
        );

        $user->refresh();
        $this->assertSame(SubscriptionStatus::Active, $user->subscription_status);
        $this->assertSame(UserRole::Standard, $user->roleEnum());
        $this->assertTrue((bool) $user->mailbox_addon_active);
        $this->assertNull($user->trial_ends_at);
    }

    public function test_a_trialing_subscription_records_the_trial_end(): void
    {
        $user = $this->makeUser('billing-trial@example.com');
        $trialEnd = now()->addDays(14)->startOfSecond();

        app(StripeBillingService::class)->applySubscription(
            $user,
            $this->subscriptionPayload('trialing', [self::STANDARD_PRICE], $trialEnd->getTimestamp())
        );

        $user->refresh();
        $this->assertSame(SubscriptionStatus::Trial, $user->subscription_status);
        $this->assertSame($trialEnd->timestamp, $user->trial_ends_at->timestamp);
    }

    public function test_cancelling_drops_the_user_back_to_light_and_removes_addons(): void
    {
        $user = $this->makeUser('billing-cancel@example.com');
        $service = app(StripeBillingService::class);

        $service->applySubscription($user, $this->subscriptionPayload('active', [self::STANDARD_PRICE, self::MAILBOX_PRICE]));
        $service->applySubscription($user->refresh(), $this->subscriptionPayload('canceled', [self::STANDARD_PRICE, self::MAILBOX_PRICE]));

        $user->refresh();
        $this->assertSame(SubscriptionStatus::Canceled, $user->subscription_status);
        $this->assertSame(UserRole::Light, $user->roleEnum());
        $this->assertFalse((bool) $user->mailbox_addon_active);
    }

    public function test_billing_events_never_demote_staff_roles(): void
    {
        $admin = $this->makeUser('billing-admin@example.com', UserRole::Admin);

        app(StripeBillingService::class)->applySubscription(
            $admin,
            $this->subscriptionPayload('canceled', [self::STANDARD_PRICE])
        );

        $this->assertSame(UserRole::Admin, $admin->refresh()->roleEnum());
    }

    public function test_an_incomplete_subscription_changes_nothing(): void
    {
        $user = $this->makeUser('billing-incomplete@example.com');

        app(StripeBillingService::class)->applySubscription(
            $user,
            $this->subscriptionPayload('incomplete', [self::STANDARD_PRICE])
        );

        $user->refresh();
        $this->assertSame(SubscriptionStatus::None, $user->subscription_status);
        $this->assertSame(UserRole::Light, $user->roleEnum());
    }

    public function test_storage_overage_is_billed_in_whole_100gb_units(): void
    {
        $service = app(StripeBillingService::class);
        $gb = 1024 ** 3;

        $this->assertSame(0, $service->overageUnits(50 * $gb, 200 * $gb));
        $this->assertSame(0, $service->overageUnits(200 * $gb, 200 * $gb));
        $this->assertSame(1, $service->overageUnits(201 * $gb, 200 * $gb));
        $this->assertSame(1, $service->overageUnits(300 * $gb, 200 * $gb));
        $this->assertSame(2, $service->overageUnits(301 * $gb, 200 * $gb));
    }

    public function test_the_webhook_rejects_an_unsigned_request(): void
    {
        config(['cashier.webhook.secret' => 'whsec_test']);

        $this->postJson('/webhooks/stripe', ['id' => 'evt_1', 'type' => 'invoice.paid'])
            ->assertStatus(400);

        $this->assertSame(0, BillingEvent::query()->count());
    }

    public function test_the_webhook_rejects_everything_while_the_secret_is_missing(): void
    {
        config(['cashier.webhook.secret' => '']);

        $this->postJson('/webhooks/stripe', ['id' => 'evt_1', 'type' => 'invoice.paid'])
            ->assertStatus(400);
    }

    /** @param array<string, mixed> $event */
    private function postSignedWebhook(array $event, string $secret = 'whsec_test'): TestResponse
    {
        config(['cashier.webhook.secret' => $secret]);

        $body = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call(
            'POST',
            '/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $body
        );
    }

    public function test_a_signed_subscription_event_grants_access(): void
    {
        $user = $this->makeUser('billing-webhook@example.com');
        $user->forceFill(['stripe_id' => 'cus_webhook_test'])->save();

        $this->postSignedWebhook([
            'id' => 'evt_subscription_1',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'customer' => 'cus_webhook_test',
            ] + $this->subscriptionPayload('active', [self::STANDARD_PRICE])],
        ])->assertOk();

        $user->refresh();
        $this->assertSame(SubscriptionStatus::Active, $user->subscription_status);
        $this->assertSame(UserRole::Standard, $user->roleEnum());

        $event = BillingEvent::query()->where('stripe_event_id', 'evt_subscription_1')->firstOrFail();
        $this->assertSame(BillingEvent::STATUS_PROCESSED, $event->status);
        $this->assertSame($user->id, $event->user_id);
    }

    public function test_a_replayed_event_is_only_applied_once(): void
    {
        $user = $this->makeUser('billing-replay@example.com');
        $user->forceFill(['stripe_id' => 'cus_replay_test'])->save();

        $event = [
            'id' => 'evt_replay_1',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'customer' => 'cus_replay_test',
            ] + $this->subscriptionPayload('active', [self::STANDARD_PRICE])],
        ];

        $this->postSignedWebhook($event)->assertOk();

        // Stripe が再送しても、権限を戻した後に上書きされない
        $user->refresh()->forceFill(['subscription_status' => SubscriptionStatus::Canceled])->save();
        $this->postSignedWebhook($event)->assertOk();

        $this->assertSame(SubscriptionStatus::Canceled, $user->refresh()->subscription_status);
        $this->assertSame(1, BillingEvent::query()->where('stripe_event_id', 'evt_replay_1')->count());
    }

    public function test_a_signed_event_for_an_unknown_customer_is_recorded_but_grants_nothing(): void
    {
        $this->postSignedWebhook([
            'id' => 'evt_orphan_1',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'customer' => 'cus_nobody',
            ] + $this->subscriptionPayload('active', [self::STANDARD_PRICE])],
        ])->assertOk();

        $event = BillingEvent::query()->where('stripe_event_id', 'evt_orphan_1')->firstOrFail();
        $this->assertSame(BillingEvent::STATUS_PROCESSED, $event->status);
        $this->assertNull($event->user_id);
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $secret = 'whsec_test';
        config(['cashier.webhook.secret' => $secret]);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.'{"id":"evt_a"}', $secret);

        $this->call(
            'POST',
            '/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            '{"id":"evt_b"}'
        )->assertStatus(400);

        $this->assertSame(0, BillingEvent::query()->count());
    }

    public function test_a_failed_payment_marks_the_account_past_due(): void
    {
        $user = $this->makeUser('billing-pastdue@example.com', UserRole::Standard);
        $user->forceFill(['stripe_id' => 'cus_pastdue_test'])->save();

        $this->postSignedWebhook([
            'id' => 'evt_invoice_failed_1',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'customer' => 'cus_pastdue_test',
                'paid' => false,
                'lines' => ['data' => [['price' => ['id' => self::STANDARD_PRICE]]]],
            ]],
        ])->assertOk();

        $user->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $user->subscription_status);
        // 猶予期間中はまだ Light に落とさない
        $this->assertSame(UserRole::Light, $user->roleEnum());
    }

    public function test_the_plan_page_offers_the_configured_plans(): void
    {
        $user = $this->makeUser('billing-page@example.com');

        $this->actingAs($user)->get('/mypage/plan')
            ->assertOk()
            ->assertSee(__('プラン・お支払い'), false)
            ->assertSee('name="plan" value="standard_monthly"', false);
    }

    public function test_the_plan_page_hides_checkout_while_billing_is_disabled(): void
    {
        config(['billing.enabled' => false]);
        $user = $this->makeUser('billing-disabled@example.com');

        $this->actingAs($user)->get('/mypage/plan')
            ->assertOk()
            ->assertDontSee('name="plan"', false)
            ->assertSee(__('オンラインでのお申し込みは準備中です。ご希望の方はお問い合わせからご連絡ください。'), false);
    }

    public function test_checkout_refuses_a_plan_that_is_not_self_serve(): void
    {
        config(['billing.plans.tenant_monthly.price_id' => 'price_tenant_test']);
        $user = $this->makeUser('billing-tenant@example.com');

        $this->actingAs($user)
            ->post('/mypage/plan/checkout', ['plan' => 'tenant_monthly'])
            ->assertRedirect('/mypage/plan');

        $this->assertSame(
            __('選択されたプランは申し込めません。'),
            session('error')
        );
    }

    public function test_checkout_refuses_an_unknown_plan_key(): void
    {
        $user = $this->makeUser('billing-unknown@example.com');

        $this->actingAs($user)
            ->post('/mypage/plan/checkout', ['plan' => 'price_1SomethingArbitrary'])
            ->assertRedirect('/mypage/plan');

        $this->assertSame(__('選択されたプランは申し込めません。'), session('error'));
    }

    public function test_checkout_refuses_a_second_subscription(): void
    {
        $user = $this->makeUser('billing-double@example.com');
        $user->forceFill(['subscription_status' => SubscriptionStatus::Active])->save();

        $this->actingAs($user)
            ->post('/mypage/plan/checkout', ['plan' => 'standard_monthly'])
            ->assertRedirect('/mypage/plan');

        $this->assertSame(
            __('すでに有効なご契約があります。プランの変更は「契約内容を変更」からお願いします。'),
            session('error')
        );
    }

    public function test_the_plan_page_requires_login(): void
    {
        $this->get('/mypage/plan')->assertRedirect('/login');
    }
}
