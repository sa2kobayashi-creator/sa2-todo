<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaStorageSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CommercialSettingsTest extends TestCase
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

    public function test_super_admin_sees_the_public_sales_tab(): void
    {
        $owner = $this->makeUser(UserRole::SuperAdmin, 'owner-sales@example.com');

        $this->actingAs($owner)->get('/settings?section=sales')
            ->assertOk()
            ->assertSee(__('公開販売'), false)
            ->assertSee('id="legal-operator-settings"', false)
            ->assertSee('id="stripe-billing-settings"', false)
            ->assertSee('TRUSTED_PROXIES', false)
            ->assertSee('Webhook URL', false);
    }

    public function test_tenant_admin_does_not_see_the_public_sales_tab(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-sales@example.com');

        $this->actingAs($admin)->get('/settings?section=sales')
            ->assertOk()
            ->assertDontSee('id="legal-operator-settings"', false)
            ->assertDontSee('id="stripe-billing-settings"', false);
    }

    public function test_super_admin_can_save_operator_details_used_on_tokushoho(): void
    {
        $owner = $this->makeUser(UserRole::SuperAdmin, 'owner-legal-save@example.com');

        $this->actingAs($owner)
            ->post('/settings/sales/legal', [
                'operator_name' => '山田 太郎',
                'address' => '東京都千代田区1-1-1',
                'phone' => '03-0000-0000',
                'contact_email' => 'support@example.com',
            ])
            ->assertRedirect('/settings?section=sales#legal-operator-settings');

        $this->get('/tokushoho')
            ->assertOk()
            ->assertDontSee('legal-unset', false)
            ->assertSee('山田 太郎', false)
            ->assertSee('support@example.com', false);

        auth()->logout();
        $this->get('/')
            ->assertOk()
            ->assertSee('support@example.com', false);
    }

    public function test_enabling_stripe_before_legal_details_is_rejected(): void
    {
        $owner = $this->makeUser(UserRole::SuperAdmin, 'owner-stripe-early@example.com');

        $this->actingAs($owner)
            ->post('/settings/sales/stripe', [
                'enabled' => '1',
                'stripe_secret' => 'sk_test_dummy',
            ])
            ->assertRedirect('/settings?section=sales#stripe-billing-settings');

        $this->assertSame(
            __('オンライン申し込みを始める前に、上の事業者情報（氏名・住所・電話・メール）を保存してください。'),
            session('error')
        );
        $this->assertFalse((bool) config('billing.enabled'));
    }

    public function test_enabling_stripe_without_webhook_secret_is_rejected(): void
    {
        $owner = $this->makeUser(UserRole::SuperAdmin, 'owner-stripe-webhook@example.com');

        $this->actingAs($owner)->post('/settings/sales/legal', [
            'operator_name' => '山田 太郎',
            'address' => '東京都千代田区1-1-1',
            'phone' => '03-0000-0000',
            'contact_email' => 'support@example.com',
        ]);

        $this->actingAs($owner)
            ->post('/settings/sales/stripe', [
                'enabled' => '1',
                'stripe_secret' => 'sk_test_dummy',
                'price_standard_monthly' => 'price_standard_1',
            ])
            ->assertRedirect('/settings?section=sales#stripe-billing-settings');

        $this->assertSame(
            __('Webhook 署名シークレット（whsec_）を保存してください。決済完了を受け取れません。'),
            session('error')
        );
    }

    public function test_enabling_stripe_with_complete_setup_succeeds(): void
    {
        $owner = $this->makeUser(UserRole::SuperAdmin, 'owner-stripe-ready@example.com');

        $this->actingAs($owner)->post('/settings/sales/legal', [
            'operator_name' => '山田 太郎',
            'address' => '東京都千代田区1-1-1',
            'phone' => '03-0000-0000',
            'contact_email' => 'support@example.com',
        ]);

        $this->actingAs($owner)
            ->post('/settings/sales/stripe', [
                'enabled' => '1',
                'stripe_key' => 'pk_test_abc',
                'stripe_secret' => 'sk_test_abc',
                'webhook_secret' => 'whsec_abc',
                'price_standard_monthly' => 'price_standard_1',
            ])
            ->assertRedirect('/settings?section=sales#stripe-billing-settings');

        $this->assertTrue((bool) config('billing.enabled'));
        $this->assertSame(
            __('Stripe 設定を保存し、オンライン申し込みを開始しました。'),
            session('notice')
        );
    }

    public function test_super_admin_can_save_stripe_keys_without_enabling_checkout(): void
    {
        $owner = $this->makeUser(UserRole::SuperAdmin, 'owner-stripe-save@example.com');

        $this->actingAs($owner)->post('/settings/sales/stripe', [
            'stripe_key' => 'pk_test_abc',
            'stripe_secret' => 'sk_test_abc',
            'webhook_secret' => 'whsec_abc',
            'price_standard_monthly' => 'price_standard_1',
        ])->assertRedirect('/settings?section=sales#stripe-billing-settings');

        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_STRIPE);
        $this->assertFalse((bool) $row->enabled);
        $this->assertSame('sk_test_abc', $row->secret('stripe_secret', ''));
        $this->assertSame('price_standard_1', $row->setting('price_standard_monthly', ''));
        $this->assertFalse((bool) config('billing.enabled'));
    }

    public function test_admin_cannot_post_sales_settings(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-sales-post@example.com');

        $this->actingAs($admin)
            ->post('/settings/sales/legal', ['operator_name' => '誰か'])
            ->assertForbidden();
    }
}
