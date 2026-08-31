<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\WebPushConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MyPageProfileTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Tester',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    public function test_mypage_shows_plan_and_storage_summary(): void
    {
        $user = $this->makeUser('mypage-plan@example.com');

        $this->actingAs($user)
            ->get('/mypage')
            ->assertOk()
            ->assertSee('現在の利用状況', false)
            ->assertSee('契約状態', false)
            ->assertSee('写真の容量', false)
            ->assertSee('メールボックス', false)
            ->assertSee('/mail?tab=domain', false)
            ->assertSee('このアカウントの枠', false)
            ->assertSee('翻訳', false)
            ->assertSee('自分の休日', false)
            ->assertSee('href="/mypage/holidays"', false)
            ->assertSee('休日を設定する', false)
            ->assertSee('data-accordion-key="mypage-features"', false)
            ->assertSee('data-accordion-key="mypage-billing-plan"', false)
            ->assertSee('id="billing-plan"', false)
            ->assertSee(__('プラン・お支払い'), false)
            ->assertSee(__('ご契約の状態'), false)
            ->assertSee('data-accordion-key="mypage-profile-edit"', false)
            ->assertSee('data-accordion-key="mypage-password"', false)
            ->assertSee('data-accordion-key="mypage-line"', false)
            ->assertSee('data-accordion-key="mypage-web-push"', false)
            ->assertSee('data-accordion-key="mypage-holidays"', false)
            ->assertSee('data-accordion-key="mypage-account-delete"', false)
            ->assertSee('Googleカレンダー設定', false);
    }

    public function test_mypage_shows_billing_portal_when_subscription_is_active(): void
    {
        config([
            'billing.enabled' => true,
            'billing.plans.standard_monthly.price_id' => 'price_standard_monthly_test',
            'cashier.secret' => 'sk_test_dummy',
            'cashier.webhook.secret' => 'whsec_test',
            'legal.operator_name' => 'テスト事業者',
            'legal.address' => '東京都千代田区1-1-1',
            'legal.phone' => '03-0000-0000',
            'legal.contact_email' => 'support@example.com',
        ]);

        $user = $this->makeUser('mypage-billing-portal@example.com');
        $user->forceFill([
            'subscription_status' => \App\Enums\SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(14),
            'stripe_id' => 'cus_test_mypage',
        ])->save();

        $this->actingAs($user)
            ->get('/mypage')
            ->assertOk()
            ->assertSee(__('契約内容を変更'), false)
            ->assertSee('action="/mypage/plan/portal"', false);
    }

    public function test_admin_sees_ai_usage_on_mypage_not_dashboard(): void
    {
        $admin = User::create([
            'email' => 'mypage-admin-usage@example.com',
            'display_name' => 'Admin',
            'password' => Hash::make('password123'),
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->get('/mypage')
            ->assertOk()
            ->assertSee('現在の利用状況', false)
            ->assertSee('AI使用料・翻訳使用料', false)
            ->assertSee('data-accordion-key="mypage-ai-usage"', false);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('dash-ai-usage', false);
    }

    public function test_timezone_can_be_updated_on_mypage(): void
    {
        $user = $this->makeUser('mypage-tz@example.com');

        $this->actingAs($user)->post('/mypage', [
            'displayName' => 'Tester',
            'email' => $user->email,
            'timezone' => 'America/New_York',
        ])->assertRedirect('/mypage');

        $user->refresh();
        $this->assertSame('America/New_York', $user->timezone);
    }

    public function test_email_is_normalized_before_verification_starts(): void
    {
        Mail::fake();
        $user = $this->makeUser('mypage-normalize@example.com');

        $this->actingAs($user)->post('/mypage', [
            'displayName' => 'Tester',
            'email' => '  MyPage-Normalized@Example.COM  ',
        ])->assertRedirect('/mypage/email/verify')
            ->assertSessionHas('notice', '確認コードをmypage-normalized@example.comに送信しました。コードを入力すると変更が完了します。');

        $user->refresh();
        $this->assertSame('mypage-normalize@example.com', $user->email);
        $this->assertSame('mypage-normalized@example.com', $user->pending_email);
    }

    public function test_display_name_is_saved_even_while_the_email_waits_for_verification(): void
    {
        Mail::fake();
        $user = $this->makeUser('mypage-name@example.com');

        $this->actingAs($user)->post('/mypage', [
            'displayName' => '新しい名前',
            'email' => 'mypage-name-new@example.com',
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('新しい名前', $user->display_name);
        $this->assertSame('mypage-name@example.com', $user->email);
    }

    public function test_duplicate_email_shows_error_on_mypage(): void
    {
        $user = $this->makeUser('mypage-dup-a@example.com');
        $this->makeUser('mypage-dup-b@example.com');

        $response = $this->actingAs($user)->followingRedirects()->post('/mypage', [
            'displayName' => 'Tester',
            'email' => 'mypage-dup-b@example.com',
        ]);

        $response->assertOk()->assertSee('このメールアドレスはすでに使用されています。');
        $this->assertSame('mypage-dup-a@example.com', $user->fresh()->email);
    }

    public function test_mypage_no_longer_changes_the_password_directly(): void
    {
        $user = $this->makeUser('mypage-pass@example.com');

        $this->actingAs($user)->post('/mypage', [
            'displayName' => 'Tester',
            'email' => 'mypage-pass@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }

    public function test_mypage_lists_the_groups_the_user_belongs_to(): void
    {
        $user = $this->makeUser('mypage-group@example.com');
        $group = Group::create([
            'name' => 'デザイン班',
            'owner_user_id' => $user->id,
            'status' => GroupStatus::Approved->value,
        ]);
        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->actingAs($user)->get('/mypage')
            ->assertOk()
            ->assertSee('所属グループ')
            ->assertSee('デザイン班');
    }

    public function test_standard_user_sees_web_push_subscribe_on_mypage_even_without_settings(): void
    {
        $user = $this->makeUser('mypage-push@example.com');

        $this->actingAs($user)
            ->get('/mypage')
            ->assertOk()
            ->assertSee('id="web-push-subscribe"', false)
            ->assertSee('この端末の通知を登録', false)
            ->assertSee('通知サーバーが未設定です。管理者に Web Push の設定を依頼してください。', false)
            ->assertDontSee('push-client.js', false);

        $this->actingAs($user)
            ->get('/settings')
            ->assertForbidden();
    }

    public function test_standard_user_can_register_a_device_from_mypage_when_web_push_is_configured(): void
    {
        $user = $this->makeUser('mypage-push-ready@example.com');
        $publicKey = rtrim(strtr(base64_encode("\x04".str_repeat("\0", 64)), '+/', '-_'), '=');
        $privateKey = rtrim(strtr(base64_encode(str_repeat("\1", 32)), '+/', '-_'), '=');

        app(WebPushConfigService::class)->saveConfig(true, [
            'subject' => 'mailto:admin@example.com',
            'public_key' => $publicKey,
            'private_key' => $privateKey,
        ]);

        $this->actingAs($user)
            ->get('/mypage')
            ->assertOk()
            ->assertSee('id="push-subscribe-btn"', false)
            ->assertSee('この端末で通知を許可してください。', false)
            ->assertSee('push-client.js', false)
            ->assertDontSee('id="push-subscribe-btn" disabled', false);
    }
}
