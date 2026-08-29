<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PasswordResetController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(private PasswordResetService $reset) {}

    public function showForgot(Request $request)
    {
        return view('auth.forgot-password', [
            'email' => $request->old('email', $request->query('email', $request->session()->get('password_reset_email', ''))),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function sendCode(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email')));
        $request->merge(['email' => $email]);

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return redirect('/password/forgot')->withErrors($validator)->withInput();
        }

        $user = User::query()->where('email', $email)->first();

        // 登録の有無を知られないよう、結果に関わらず同じ画面へ進む
        if ($user) {
            try {
                $this->reset->sendCode($user);
            } catch (\RuntimeException) {
                // 送信失敗も列挙に使われないよう、成功時と同じ文言で進める
            }
        }

        $request->session()->put('password_reset_email', $email);

        return $this->redirectWithMessage(
            '/password/reset',
            __('登録済みのメールアドレスであれば、確認コードを送信しました。メールをご確認ください。')
        );
    }

    public function showReset(Request $request)
    {
        return view('auth.reset-password', [
            'email' => $request->old('email', $request->session()->get('password_reset_email', '')),
            'ttlMinutes' => $this->reset->codeTtlMinutes(),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function reset(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email')));
        $request->merge([
            'email' => $email,
            'code' => $this->reset->normalizeCode((string) $request->input('code')),
        ]);

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return redirect('/password/reset')->withErrors($validator)->withInput($request->except('password', 'password_confirmation'));
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return $this->backToResetWithError($request, __('確認コードが正しくありません。'));
        }

        $status = $this->reset->verifyCode($user, (string) $request->input('code'));

        if ($status === 'expired') {
            return $this->backToResetWithError($request, __('確認コードの有効期限が切れました。もう一度送信してください。'));
        }

        if ($status === 'too_many_attempts') {
            return $this->backToResetWithError($request, __('入力回数の上限に達しました。もう一度確認コードを送信してください。'));
        }

        if ($status !== 'ok') {
            return $this->backToResetWithError($request, __('確認コードが正しくありません。'));
        }

        $this->reset->completeReset($user, (string) $request->input('password'));
        $request->session()->forget('password_reset_email');

        if ($request->user()?->is($user)) {
            return $this->redirectWithMessage('/mypage', __('パスワードを変更しました。'));
        }

        return $this->redirectWithMessage('/login', __('パスワードを変更しました。新しいパスワードでログインしてください。'));
    }

    /** ログイン中のユーザーが自分宛にコードを送る（マイページからの変更導線） */
    public function requestForCurrentUser(Request $request)
    {
        $user = $request->user();

        $wait = $this->reset->secondsUntilResend($user);
        if ($wait > 0) {
            return $this->redirectWithMessage(
                '/password/reset',
                __('確認コードは送信済みです。再送信は:seconds秒後に可能です。', ['seconds' => $wait])
            );
        }

        try {
            $this->reset->sendCode($user);
        } catch (\RuntimeException $e) {
            return $this->redirectWithMessage('/mypage', $e->getMessage(), 'error');
        }
        $request->session()->put('password_reset_email', $user->email);

        return $this->redirectWithMessage(
            '/password/reset',
            __('確認コードを:emailに送信しました。', ['email' => $user->email])
        );
    }

    private function backToResetWithError(Request $request, string $message)
    {
        return redirect('/password/reset')
            ->withErrors(['code' => $message])
            ->withInput($request->except('password', 'password_confirmation'));
    }
}
