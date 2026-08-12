<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\PasswordResetCodeMail;
use App\Mail\WelcomeInitialPasswordMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthPasswordFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, string $password = 'password123'): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Tester',
            'password' => Hash::make($password),
            'role' => UserRole::Standard,
        ]);
    }

    private function sentResetCode(): string
    {
        $code = '';
        Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    public function test_auth_pages_render(): void
    {
        $this->get('/login')->assertOk()->assertSee('パスワードをお忘れですか？');
        $this->get('/register')->assertOk()->assertDontSee('name="password"', false);
        $this->get('/password/forgot')->assertOk()->assertSee('確認コードを送信');
        $this->get('/password/reset')->assertOk()->assertSee('確認コード（6桁）');
    }

    public function test_register_uses_admin_saved_invite_code_over_env(): void
    {
        config(['registration.invite_code' => 'env-code']);
        \App\Support\Registration::setInviteCode('admin-code');
        Mail::fake();

        $this->post('/register', [
            'email' => 'db-code@example.com',
            'displayName' => 'Db',
            'inviteCode' => 'env-code',
            'agreeTerms' => '1',
        ])->assertRedirect();
        $this->assertNull(User::query()->where('email', 'db-code@example.com')->first());

        $this->post('/register', [
            'email' => 'db-code@example.com',
            'displayName' => 'Db',
            'inviteCode' => 'admin-code',
            'agreeTerms' => '1',
        ])->assertRedirect();
        $this->assertNotNull(User::query()->where('email', 'db-code@example.com')->first());
    }

    public function test_register_is_closed_without_an_invite_code(): void
    {
        config(['registration.invite_code' => '']);

        $this->get('/register')->assertOk()->assertSee('現在、新規登録は受け付けていません');
        $this->post('/register', [
            'email' => 'closed@example.com',
            'displayName' => 'Closed',
        ])->assertRedirect();

        $this->assertNull(User::query()->where('email', 'closed@example.com')->first());
    }

    public function test_register_rejects_a_wrong_invite_code(): void
    {
        config(['registration.invite_code' => 'family-secret']);
        Mail::fake();

        $this->followingRedirects()->post('/register', [
            'email' => 'wrong-code@example.com',
            'displayName' => 'Wrong',
            'inviteCode' => 'nope',
            'agreeTerms' => '1',
        ])->assertOk()->assertSee('招待コードが正しくありません');

        $this->assertNull(User::query()->where('email', 'wrong-code@example.com')->first());
    }

    public function test_register_emails_an_initial_password_without_logging_in(): void
    {
        config(['registration.invite_code' => 'family-secret']);
        Mail::fake();

        $this->post('/register', [
            'email' => 'Newbie@Example.com',
            'displayName' => 'Newbie',
            'inviteCode' => 'family-secret',
            'agreeTerms' => '1',
        ])->assertRedirect();

        $this->assertGuest();

        $user = User::query()->where('email', 'newbie@example.com')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertSame(UserRole::Light, $user->roleEnum());

        Mail::assertSent(WelcomeInitialPasswordMail::class, function (WelcomeInitialPasswordMail $mail) use ($user) {
            return $mail->email === $user->email
                && strlen($mail->initialPassword) >= 8
                && Hash::check($mail->initialPassword, $user->password);
        });
    }

    public function test_register_requires_terms_agreement(): void
    {
        config(['registration.invite_code' => 'family-secret']);
        Mail::fake();

        $this->from('/register')->post('/register', [
            'email' => 'no-agree@example.com',
            'displayName' => 'NoAgree',
            'inviteCode' => 'family-secret',
        ])->assertRedirect('/register');

        $this->assertNull(User::query()->where('email', 'no-agree@example.com')->first());
    }

    public function test_register_no_longer_accepts_a_password_field(): void
    {
        config(['registration.invite_code' => 'family-secret']);
        Mail::fake();

        $this->post('/register', [
            'email' => 'chosen@example.com',
            'displayName' => 'Chosen',
            'inviteCode' => 'family-secret',
            'agreeTerms' => '1',
            'password' => 'ignored-password',
            'password_confirmation' => 'ignored-password',
        ])->assertRedirect();

        $user = User::query()->where('email', 'chosen@example.com')->firstOrFail();
        $this->assertFalse(Hash::check('ignored-password', $user->password));
    }

    public function test_first_login_is_forced_to_the_password_setup_screen(): void
    {
        $user = $this->makeUser('first-login@example.com', 'initial-pass');
        $user->must_change_password = true;
        $user->save();

        $this->post('/login', [
            'email' => 'first-login@example.com',
            'password' => 'initial-pass',
        ])->assertRedirect('/password/setup');

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/password/setup');
        $this->actingAs($user)->get('/password/setup')->assertOk();
    }

    public function test_setting_a_password_releases_the_forced_screen(): void
    {
        $user = $this->makeUser('setup@example.com');
        $user->must_change_password = true;
        $user->save();

        $this->actingAs($user)->post('/password/setup', [
            'password' => 'my-new-password',
            'password_confirmation' => 'my-new-password',
        ])->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('my-new-password', $user->password));

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_forgot_password_sends_a_six_digit_code(): void
    {
        Mail::fake();
        $user = $this->makeUser('forgot@example.com');

        $this->post('/password/forgot', ['email' => 'forgot@example.com'])
            ->assertRedirect();

        $code = $this->sentResetCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $user->refresh();
        $this->assertNotNull($user->reset_token);
        $this->assertTrue(Hash::check($code, $user->reset_token));
    }

    public function test_unknown_email_does_not_reveal_whether_it_is_registered(): void
    {
        Mail::fake();

        $this->post('/password/forgot', ['email' => 'nobody@example.com'])
            ->assertRedirect();

        Mail::assertNothingSent();
    }

    public function test_correct_code_changes_the_password(): void
    {
        Mail::fake();
        $user = $this->makeUser('reset-ok@example.com');

        $this->post('/password/forgot', ['email' => $user->email]);
        $code = $this->sentResetCode();

        $this->post('/password/reset', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect();

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $user->password));
        $this->assertNull($user->reset_token);
    }

    public function test_wrong_code_is_rejected_and_counted(): void
    {
        Mail::fake();
        $user = $this->makeUser('reset-ng@example.com');

        $this->post('/password/forgot', ['email' => $user->email]);

        $response = $this->followingRedirects()->post('/password/reset', [
            'email' => $user->email,
            'code' => '000000',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ]);

        $response->assertOk()->assertSee('確認コードが正しくありません。');

        $user->refresh();
        $this->assertSame(1, (int) $user->reset_attempts);
        $this->assertFalse(Hash::check('brand-new-pass', $user->password));
    }

    public function test_expired_code_is_rejected(): void
    {
        Mail::fake();
        $user = $this->makeUser('reset-expired@example.com');

        $this->post('/password/forgot', ['email' => $user->email]);
        $code = $this->sentResetCode();

        $this->travelTo(Carbon::now()->addMinutes(config('password_reset.code_ttl_minutes') + 1));

        $response = $this->followingRedirects()->post('/password/reset', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ]);

        $response->assertOk()->assertSee('確認コードの有効期限が切れました。');
        $this->assertFalse(Hash::check('brand-new-pass', $user->fresh()->password));
    }

    public function test_mypage_password_change_uses_the_same_code_flow(): void
    {
        Mail::fake();
        $user = $this->makeUser('mypage-code@example.com');

        $this->actingAs($user)->post('/mypage/password/request-code')
            ->assertRedirect();

        $code = $this->sentResetCode();

        $this->actingAs($user)->post('/password/reset', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'from-mypage-pass',
            'password_confirmation' => 'from-mypage-pass',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('from-mypage-pass', $user->fresh()->password));
    }
}
