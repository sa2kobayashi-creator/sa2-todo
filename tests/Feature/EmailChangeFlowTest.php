<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\EmailChangeCodeMail;
use App\Mail\EmailChangedNoticeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailChangeFlowTest extends TestCase
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

    private function startChange(User $user, string $newEmail): string
    {
        $this->actingAs($user)->post('/mypage', [
            'displayName' => 'Tester',
            'email' => $newEmail,
        ])->assertRedirect();

        $code = '';
        Mail::assertSent(EmailChangeCodeMail::class, function (EmailChangeCodeMail $mail) use (&$code, $newEmail) {
            $code = $mail->code;

            return $mail->hasTo($newEmail);
        });

        return $code;
    }

    public function test_code_is_sent_to_the_new_address_and_email_is_not_changed_yet(): void
    {
        Mail::fake();
        $user = $this->makeUser('change-start@example.com');

        $code = $this->startChange($user, 'change-start-new@example.com');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $user->refresh();
        $this->assertSame('change-start@example.com', $user->email);
        $this->assertSame('change-start-new@example.com', $user->pending_email);
        $this->assertTrue(Hash::check($code, $user->pending_email_token));
    }

    public function test_correct_code_applies_the_new_address_and_notifies_the_old_one(): void
    {
        Mail::fake();
        $user = $this->makeUser('change-ok@example.com');
        $code = $this->startChange($user, 'change-ok-new@example.com');

        $this->actingAs($user)->post('/mypage/email/verify', ['code' => $code])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('change-ok-new@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->pending_email_token);

        Mail::assertSent(EmailChangedNoticeMail::class, function (EmailChangedNoticeMail $mail) {
            return $mail->hasTo('change-ok@example.com')
                && $mail->newEmail === 'change-ok-new@example.com';
        });
    }

    public function test_wrong_code_is_rejected_and_counted(): void
    {
        Mail::fake();
        $user = $this->makeUser('change-ng@example.com');
        $this->startChange($user, 'change-ng-new@example.com');

        $this->actingAs($user)->followingRedirects()
            ->post('/mypage/email/verify', ['code' => '000000'])
            ->assertOk()
            ->assertSee('確認コードが正しくありません。');

        $user->refresh();
        $this->assertSame('change-ng@example.com', $user->email);
        $this->assertSame(1, (int) $user->pending_email_attempts);
    }

    public function test_expired_code_is_rejected(): void
    {
        Mail::fake();
        $user = $this->makeUser('change-expired@example.com');
        $code = $this->startChange($user, 'change-expired-new@example.com');

        $this->travelTo(Carbon::now()->addMinutes(config('email_change.code_ttl_minutes') + 1));

        $this->actingAs($user)->followingRedirects()
            ->post('/mypage/email/verify', ['code' => $code])
            ->assertOk()
            ->assertSee('確認コードの有効期限が切れました。');

        $this->assertSame('change-expired@example.com', $user->fresh()->email);
    }

    public function test_address_taken_while_waiting_is_rejected(): void
    {
        Mail::fake();
        $user = $this->makeUser('change-race@example.com');
        $code = $this->startChange($user, 'change-race-new@example.com');

        $this->makeUser('change-race-new@example.com');

        $this->actingAs($user)->followingRedirects()
            ->post('/mypage/email/verify', ['code' => $code])
            ->assertOk()
            ->assertSee('このメールアドレスはすでに使用されています。');

        $user->refresh();
        $this->assertSame('change-race@example.com', $user->email);
        $this->assertNull($user->pending_email);
    }

    public function test_resend_issues_a_new_code_after_the_waiting_period(): void
    {
        Mail::fake();
        $user = $this->makeUser('change-resend@example.com');
        $firstCode = $this->startChange($user, 'change-resend-new@example.com');

        $this->actingAs($user)->followingRedirects()->post('/mypage/email/resend')
            ->assertOk()
            ->assertSee('再送信は');

        $this->travelTo(Carbon::now()->addSeconds(config('email_change.resend_interval_seconds') + 1));
        $this->actingAs($user)->post('/mypage/email/resend')->assertRedirect();

        $codes = [];
        Mail::assertSent(EmailChangeCodeMail::class, function (EmailChangeCodeMail $mail) use (&$codes) {
            $codes[] = $mail->code;

            return true;
        });
        $this->assertCount(2, $codes);

        $user->refresh();
        $this->assertFalse(Hash::check($firstCode, $user->pending_email_token));
        $this->assertTrue(Hash::check(end($codes), $user->pending_email_token));
    }

    public function test_change_can_be_cancelled(): void
    {
        Mail::fake();
        $user = $this->makeUser('change-cancel@example.com');
        $this->startChange($user, 'change-cancel-new@example.com');

        $this->actingAs($user)->post('/mypage/email/cancel')->assertRedirect();

        $user->refresh();
        $this->assertNull($user->pending_email);
        $this->assertSame('change-cancel@example.com', $user->email);
    }

    public function test_verify_screen_is_unavailable_without_a_pending_change(): void
    {
        $user = $this->makeUser('change-none@example.com');

        $this->actingAs($user)->followingRedirects()->get('/mypage/email/verify')
            ->assertOk()
            ->assertSee('確認待ちのメールアドレスはありません。');
    }

    public function test_mypage_shows_the_pending_address(): void
    {
        Mail::fake();
        $user = $this->makeUser('change-banner@example.com');
        $this->startChange($user, 'change-banner-new@example.com');

        $this->actingAs($user)->get('/mypage')
            ->assertOk()
            ->assertSee('change-banner-new@example.com');
    }
}
