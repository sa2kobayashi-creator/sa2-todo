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
            ->assertSee('id="app-install-settings"', false)
            ->assertSee(__('アプリインストール（Android APK）'), false)
            ->assertSee('id="registration-applications-settings"', false)
            ->assertSee(__('スタンダードの申請を開始する'), false)
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

    public function test_super_admin_can_toggle_application_intake(): void
    {
        $owner = $this->makeUser(UserRole::SuperAdmin, 'owner-apps-open@example.com');

        $this->actingAs($owner)
            ->post('/settings/sales/applications', [])
            ->assertRedirect('/settings?section=sales#registration-applications-settings');
        $this->assertFalse(\App\Support\Registration::applicationsOpen());
        $this->assertFalse(\App\Support\Registration::applicationsOpenFor('standard'));

        auth()->logout();
        $this->get('/')
            ->assertOk()
            ->assertSee(__('準備中'), false)
            ->assertDontSee('href="/apply?plan=standard"', false);

        $this->get('/apply')->assertRedirect('/');

        $this->actingAs($owner)
            ->post('/settings/sales/applications', [
                'applications_open_light' => '1',
                'applications_open_standard' => '1',
                'applications_open_tenant' => '1',
                'applications_open_dedicated' => '1',
            ])
            ->assertRedirect('/settings?section=sales#registration-applications-settings');
        $this->assertTrue(\App\Support\Registration::applicationsOpen());
        $this->assertTrue(\App\Support\Registration::applicationsOpenFor('standard'));

        auth()->logout();
        $this->get('/')
            ->assertOk()
            ->assertSee('href="/apply?plan=standard"', false);
    }

    public function test_super_admin_can_open_plans_independently(): void
    {
        $owner = $this->makeUser(UserRole::SuperAdmin, 'owner-apps-per-plan@example.com');

        $this->actingAs($owner)
            ->post('/settings/sales/applications', [
                'applications_open_standard' => '1',
            ])
            ->assertRedirect('/settings?section=sales#registration-applications-settings');

        $this->assertTrue(\App\Support\Registration::applicationsOpenFor('standard'));
        $this->assertFalse(\App\Support\Registration::applicationsOpenFor('light'));
        $this->assertFalse(\App\Support\Registration::applicationsOpenFor('tenant'));
        $this->assertFalse(\App\Support\Registration::applicationsOpenFor('dedicated'));

        auth()->logout();
        $this->get('/')
            ->assertOk()
            ->assertSee('href="/apply?plan=standard"', false)
            ->assertDontSee('href="/apply?plan=light"', false)
            ->assertDontSee('data-stat-event="cta.plan.dedicated"', false);

        $this->get('/apply?plan=light')->assertRedirect('/');
        $this->get('/apply?plan=standard')->assertOk();
    }

    public function test_top_shows_light_soft_launch_copy_only_when_light_alone_is_open(): void
    {
        $owner = $this->makeUser(UserRole::SuperAdmin, 'owner-light-soft@example.com');
        $softLaunch = __('現在試験的に運用をライトプランで開始しました。そのほかのプランは現在開発中で近日公開予定でございます。ご使用のご意見等をログイン後のダッシュボード上部の「ご使用後の意見はこちら」のご意見ボタンから頂けると幸いでございます。');

        $this->actingAs($owner)
            ->post('/settings/sales/applications', [
                'applications_open_light' => '1',
            ])
            ->assertRedirect('/settings?section=sales#registration-applications-settings');

        $this->assertTrue(\App\Support\Registration::isLightOnlyApplicationsOpen());

        auth()->logout();
        $this->get('/')
            ->assertOk()
            ->assertSee(__('まずはライトプランでお試しいただけます。'), false)
            ->assertSee($softLaunch, false);

        $this->actingAs($owner)
            ->post('/settings/sales/applications', [
                'applications_open_light' => '1',
                'applications_open_standard' => '1',
            ])
            ->assertRedirect('/settings?section=sales#registration-applications-settings');

        $this->assertFalse(\App\Support\Registration::isLightOnlyApplicationsOpen());

        auth()->logout();
        $this->get('/')
            ->assertOk()
            ->assertDontSee($softLaunch, false);
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

    public function test_super_admin_can_save_android_apk_url(): void
    {
        $owner = $this->makeUser(UserRole::SuperAdmin, 'owner-apk-url@example.com');

        $this->actingAs($owner)
            ->post('/settings/sales/app-install', [
                'apk_url' => 'https://cdn.example.com/sa2-plus.apk',
            ])
            ->assertRedirect('/settings?section=sales#app-install-settings');

        $this->assertSame('https://cdn.example.com/sa2-plus.apk', \App\Support\AppInstall::androidApkUrl());

        $this->actingAs($owner)->get('/dashboard')
            ->assertOk()
            ->assertSee('https://cdn.example.com/sa2-plus.apk', false)
            ->assertSee(__('Android：APK'), false)
            ->assertDontSee(__('Android：準備中'), false);
    }

    public function test_admin_cannot_post_app_install_settings(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin-apk-post@example.com');

        $this->actingAs($admin)
            ->post('/settings/sales/app-install', [
                'apk_url' => 'https://cdn.example.com/sa2-plus.apk',
            ])
            ->assertForbidden();
    }
}
