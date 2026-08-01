<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Services\EmailChangeService;
use App\Support\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmailChangeController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(private EmailChangeService $emailChange) {}

    public function showVerify(Request $request)
    {
        $user = $request->user();

        if (! $this->emailChange->hasPendingChange($user)) {
            return $this->redirectWithMessage(
                '/mypage',
                __('確認待ちのメールアドレスはありません。'),
                'error'
            );
        }

        return view('mypage.verify-email', [
            'pendingEmail' => $user->pending_email,
            'currentEmail' => $user->email,
            'ttlMinutes' => $this->emailChange->codeTtlMinutes(),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function verify(Request $request)
    {
        $user = $request->user();

        $request->merge([
            'code' => VerificationCode::normalize((string) $request->input('code')),
        ]);

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'digits:6'],
        ]);

        if ($validator->fails()) {
            return redirect('/mypage/email/verify')->withErrors($validator);
        }

        $newEmail = (string) $user->pending_email;
        $status = $this->emailChange->verifyCode($user, (string) $request->input('code'));

        if ($status === 'expired') {
            return $this->redirectWithMessage(
                '/mypage',
                __('確認コードの有効期限が切れました。もう一度メールアドレスを変更してください。'),
                'error'
            );
        }

        if ($status === 'too_many_attempts') {
            return $this->redirectWithMessage(
                '/mypage',
                __('入力回数の上限に達しました。もう一度メールアドレスを変更してください。'),
                'error'
            );
        }

        if ($status === 'taken') {
            return $this->redirectWithMessage(
                '/mypage',
                __('このメールアドレスはすでに使用されています。'),
                'error'
            );
        }

        if ($status !== 'ok') {
            return redirect('/mypage/email/verify')
                ->withErrors(['code' => __('確認コードが正しくありません。')]);
        }

        $this->emailChange->applyChange($user);

        return $this->redirectWithMessage(
            '/mypage',
            __('メールアドレスを:emailに変更しました。', ['email' => $newEmail])
        );
    }

    public function resend(Request $request)
    {
        $user = $request->user();

        if (! $this->emailChange->hasPendingChange($user)) {
            return $this->redirectWithMessage(
                '/mypage',
                __('確認待ちのメールアドレスはありません。'),
                'error'
            );
        }

        $wait = $this->emailChange->secondsUntilResend($user);
        if ($wait > 0) {
            return $this->redirectWithMessage(
                '/mypage/email/verify',
                __('確認コードは送信済みです。再送信は:seconds秒後に可能です。', ['seconds' => $wait])
            );
        }

        $this->emailChange->resend($user);

        return $this->redirectWithMessage(
            '/mypage/email/verify',
            __('確認コードを:emailに送信しました。', ['email' => $user->pending_email])
        );
    }

    public function cancel(Request $request)
    {
        $this->emailChange->cancel($request->user());

        return $this->redirectWithMessage('/mypage', __('メールアドレスの変更を取り消しました。'));
    }
}
