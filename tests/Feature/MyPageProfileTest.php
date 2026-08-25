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
            ->assertSee('プラン・容量', false)
            ->assertSee('契約状態', false)
            ->assertSee('写真の容量', false)
            ->assertSee('メールボックス', false)
            ->assertSee('/mail?tab=domain', false)
            ->assertSee('自分の休日', false)
            ->assertSee('href="/mypage/holidays"', false)
            ->assertSee('休日を設定する', false);
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
            ->assertSee('通話の着信通知を登録', false)
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
