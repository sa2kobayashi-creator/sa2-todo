<?php

namespace App\Services;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use App\Support\VerificationCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PasswordResetService
{
    public function codeTtlMinutes(): int
    {
        return max(1, (int) config('password_reset.code_ttl_minutes', 15));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('password_reset.max_attempts', 5));
    }

    public function resendIntervalSeconds(): int
    {
        return max(0, (int) config('password_reset.resend_interval_seconds', 60));
    }

    /** 直近の送信からの待ち時間。0 なら送信可能。 */
    public function secondsUntilResend(User $user): int
    {
        $lastSentAt = $user->reset_last_sent_at;
        if (! $lastSentAt) {
            return 0;
        }

        $elapsed = $lastSentAt->diffInSeconds(Carbon::now());

        return (int) max(0, $this->resendIntervalSeconds() - $elapsed);
    }

    /**
     * 6桁コードを発行してメール送信する。送信できたら true。
     * 連投を防ぐため、待ち時間内は送信せず false を返す。
     */
    public function sendCode(User $user): bool
    {
        if ($this->secondsUntilResend($user) > 0) {
            return false;
        }

        $code = VerificationCode::generate();

        $user->reset_token = Hash::make($code);
        $user->reset_token_expires_at = Carbon::now()->addMinutes($this->codeTtlMinutes());
        $user->reset_attempts = 0;
        $user->reset_last_sent_at = Carbon::now();
        $user->save();

        Mail::to($user->email)->send(new PasswordResetCodeMail(
            displayName: (string) $user->display_name,
            code: $code,
            expiresInMinutes: $this->codeTtlMinutes(),
        ));

        if (config('mail.default') === 'log') {
            Log::info('パスワード再設定コードを発行しました', ['email' => $user->email]);
        }

        return true;
    }

    /**
     * コードを検証する。失敗時は試行回数を加算する。
     *
     * @return 'ok'|'expired'|'too_many_attempts'|'invalid'
     */
    public function verifyCode(User $user, string $code): string
    {
        if (! $user->reset_token || ! $user->reset_token_expires_at) {
            return 'expired';
        }

        if ($user->reset_token_expires_at->isPast()) {
            $this->clearCode($user);

            return 'expired';
        }

        if ((int) $user->reset_attempts >= $this->maxAttempts()) {
            $this->clearCode($user);

            return 'too_many_attempts';
        }

        if (! Hash::check($this->normalizeCode($code), $user->reset_token)) {
            $user->reset_attempts = (int) $user->reset_attempts + 1;
            $user->save();

            return 'invalid';
        }

        return 'ok';
    }

    /** 検証済みの状態で新しいパスワードを保存し、コードを破棄する。 */
    public function completeReset(User $user, string $newPassword): void
    {
        $user->password = Hash::make($newPassword);
        $user->must_change_password = false;
        $user->reset_token = null;
        $user->reset_token_expires_at = null;
        $user->reset_attempts = 0;
        $user->reset_last_sent_at = null;
        // 乗っ取られた端末の「ログイン状態を保持」を、パスワード変更で確実に切る
        $user->remember_token = null;
        $user->save();
    }

    public function clearCode(User $user): void
    {
        $user->reset_token = null;
        $user->reset_token_expires_at = null;
        $user->reset_attempts = 0;
        $user->save();
    }

    /** 会員登録時に配る初期パスワード。読み間違えやすい文字は使わない。 */
    public function generateInitialPassword(int $length = 12): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    public function normalizeCode(string $code): string
    {
        return VerificationCode::normalize($code);
    }
}
