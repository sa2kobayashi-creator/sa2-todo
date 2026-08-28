<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Http\Controllers\Controller;
use App\Mail\WelcomeInitialPasswordMail;
use App\Models\User;
use App\Services\PasswordResetService;
use App\Services\RegistrationApplicationService;
use App\Support\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(
        private PasswordResetService $passwords,
        private RegistrationApplicationService $applications,
    ) {}

    public function show(Request $request)
    {
        return view('auth.register', array_merge($this->flashFromQuery($request), [
            'registrationOpen' => Registration::isOpen(),
            'purposeMinLength' => $this->applications->purposeMinLength(),
            'lightWeeklyCap' => $this->applications->lightWeeklyCap(),
            'lightQuotaGb' => max(1, (int) round(((int) config('photos.user_free_quota_bytes', 20 * 1024 * 1024 * 1024)) / (1024 * 1024 * 1024))),
        ]));
    }

    public function register(Request $request)
    {
        if (! Registration::isOpen()) {
            return $this->redirectWithMessage('/register', __('現在、新規登録は受け付けていません。管理者に依頼してください。'), 'error');
        }

        $email = strtolower(trim((string) $request->input('email')));
        $request->merge([
            'email' => $email,
            'message' => trim((string) $request->input('message')),
        ]);

        $minPurpose = $this->applications->purposeMinLength();

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'displayName' => ['nullable', 'string', 'max:100'],
            'inviteCode' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:'.$minPurpose, 'max:2000'],
            'agreeTerms' => ['accepted'],
        ], [
            'agreeTerms.accepted' => __('利用規約とプライバシーポリシーへの同意が必要です。'),
            'message.required' => __('利用目的を記入してください。'),
            'message.min' => __('利用目的を:min文字以上で記入してください。お試しの範囲や使いたい機能を書いてください。', [
                'min' => $minPurpose,
            ]),
        ]);

        if ($validator->fails()) {
            return redirect('/register')->withErrors($validator)->withInput();
        }

        if (! Registration::codeMatches($request->input('inviteCode'))) {
            return redirect('/register')
                ->withErrors(['inviteCode' => __('招待コードが正しくありません。')])
                ->withInput();
        }

        $block = $this->applications->lightTrialBlockReason(
            email: $email,
            purpose: (string) $request->input('message'),
            enforceWeeklyCap: true,
        );
        if ($block !== null) {
            return $this->redirectWithMessage('/register', $block, 'error');
        }

        $initialPassword = $this->passwords->generateInitialPassword();

        $user = User::create([
            'email' => $email,
            'display_name' => trim((string) $request->input('displayName')) ?: explode('@', $email)[0],
            'password' => Hash::make($initialPassword),
            // 自己登録は最小権限から始め、必要に応じて管理者が引き上げる
            'role' => UserRole::Light,
            'must_change_password' => true,
            'last_seen_at' => now(),
        ]);

        Mail::to($user->email)->send(new WelcomeInitialPasswordMail(
            displayName: (string) $user->display_name,
            email: $user->email,
            initialPassword: $initialPassword,
            loginUrl: url('/login'),
        ));

        return $this->redirectWithMessage(
            '/login',
            __('初期パスワードを:emailに送信しました。メールを確認してログインしてください。', ['email' => $user->email])
        );
    }
}
