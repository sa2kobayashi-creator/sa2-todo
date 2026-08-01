<?php

namespace App\Services;

use App\Mail\EmailChangeCodeMail;
use App\Mail\EmailChangedNoticeMail;
use App\Models\User;
use App\Support\VerificationCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class EmailChangeService
{
    public function codeTtlMinutes(): int
    {
        return max(1, (int) config('email_change.code_ttl_minutes', 30));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('email_change.max_attempts', 5));
    }

    public function resendIntervalSeconds(): int
    {
        return max(0, (int) config('email_change.resend_interval_seconds', 60));
    }

    public function secondsUntilResend(User $user): int
    {
        $lastSentAt = $user->pending_email_sent_at;
        if (! $lastSentAt) {
            return 0;
        }

        $elapsed = $lastSentAt->diffInSeconds(Carbon::now());

        return (int) max(0, $this->resendIntervalSeconds() - $elapsed);
    }

    public function hasPendingChange(User $user): bool
    {
        return (bool) $user->pending_email
            && $user->pending_email_expires_at
            && $user->pending_email_expires_at->isFuture();
    }

    /** 新しいアドレス宛にコードを送り、確認待ちの状態にする。 */
    public function startChange(User $user, string $newEmail): void
    {
        $code = VerificationCode::generate();

        $user->pending_email = $newEmail;
        $user->pending_email_token = Hash::make($code);
        $user->pending_email_expires_at = Carbon::now()->addMinutes($this->codeTtlMinutes());
        $user->pending_email_attempts = 0;
        $user->pending_email_sent_at = Carbon::now();
        $user->save();

        $this->deliverCode($user, $code);
    }

    /**
     * 確認待ちのアドレスに同じ手順でコードを送り直す。
     * 待ち時間内なら送らずに false。
     */
    public function resend(User $user): bool
    {
        if (! $this->hasPendingChange($user) || $this->secondsUntilResend($user) > 0) {
            return false;
        }

        $code = VerificationCode::generate();

        $user->pending_email_token = Hash::make($code);
        $user->pending_email_expires_at = Carbon::now()->addMinutes($this->codeTtlMinutes());
        $user->pending_email_attempts = 0;
        $user->pending_email_sent_at = Carbon::now();
        $user->save();

        $this->deliverCode($user, $code);

        return true;
    }

    /**
     * コードを検証する。失敗時は試行回数を加算する。
     *
     * @return 'ok'|'expired'|'too_many_attempts'|'taken'|'invalid'
     */
    public function verifyCode(User $user, string $code): string
    {
        if (! $this->hasPendingChange($user) || ! $user->pending_email_token) {
            $this->cancel($user);

            return 'expired';
        }

        if ((int) $user->pending_email_attempts >= $this->maxAttempts()) {
            $this->cancel($user);

            return 'too_many_attempts';
        }

        if (! Hash::check(VerificationCode::normalize($code), $user->pending_email_token)) {
            $user->pending_email_attempts = (int) $user->pending_email_attempts + 1;
            $user->save();

            return 'invalid';
        }

        // 確認待ちの間に他の人が同じアドレスを登録している可能性がある
        if ($this->emailTakenByOther($user, $user->pending_email)) {
            $this->cancel($user);

            return 'taken';
        }

        return 'ok';
    }

    /** 検証済みの状態で新しいアドレスを反映し、旧アドレスに通知する。 */
    public function applyChange(User $user): void
    {
        $previousEmail = $user->email;
        $newEmail = (string) $user->pending_email;

        $user->email = $newEmail;
        $user->pending_email = null;
        $user->pending_email_token = null;
        $user->pending_email_expires_at = null;
        $user->pending_email_attempts = 0;
        $user->pending_email_sent_at = null;
        $user->save();

        // 乗っ取り時に本人が気づけるよう、変更前のアドレスにも知らせる
        Mail::to($previousEmail)->send(new EmailChangedNoticeMail(
            displayName: (string) $user->display_name,
            previousEmail: $previousEmail,
            newEmail: $newEmail,
        ));
    }

    public function cancel(User $user): void
    {
        $user->pending_email = null;
        $user->pending_email_token = null;
        $user->pending_email_expires_at = null;
        $user->pending_email_attempts = 0;
        $user->pending_email_sent_at = null;
        $user->save();
    }

    public function emailTakenByOther(User $user, string $email): bool
    {
        return User::query()
            ->where('email', $email)
            ->whereKeyNot($user->id)
            ->exists();
    }

    private function deliverCode(User $user, string $code): void
    {
        Mail::to((string) $user->pending_email)->send(new EmailChangeCodeMail(
            displayName: (string) $user->display_name,
            newEmail: (string) $user->pending_email,
            code: $code,
            expiresInMinutes: $this->codeTtlMinutes(),
        ));
    }
}
