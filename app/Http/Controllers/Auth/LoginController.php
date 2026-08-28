<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Models\User;
use App\Services\SiteStatsService;
use App\Support\SiteStatEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(private readonly SiteStatsService $stats) {}

    public function show(Request $request)
    {
        return view('auth.login', [
            'returnTo' => $this->safeReturnTo($request->query('returnTo'), '/dashboard'),
            'email' => $request->old('email'),
            'registrationOpen' => \App\Support\Registration::isOpen(),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::query()->where('email', strtolower(trim($credentials['email'])))->first();
        if (! $user || ! $this->verifyPassword($credentials['password'], $user->password)) {
            return back()->withInput(['email'])->with('error', __('メールアドレスまたはパスワードが正しくありません'));
        }

        if (str_starts_with($user->password, '$2b$') || str_starts_with($user->password, '$2a$')) {
            $user->password = Hash::make($credentials['password']);
            $user->save();
        }

        // 未チェックのチェックボックスはそもそも送られてこないので、既定を true にすると解除できない
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill([
            'last_seen_at' => now(),
            'dormant_warned_at' => null,
        ])->save();

        $this->stats->increment(SiteStatEvent::LOGIN);

        if ($user->must_change_password) {
            return redirect('/password/setup');
        }

        return redirect($this->safeReturnTo($request->input('returnTo'), '/dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /** Node.js bcrypt ($2b$) と PHP/Laravel ($2y$) の両方に対応 */
    private function verifyPassword(string $plain, string $hash): bool
    {
        if ($hash === '' || $plain === '') {
            return false;
        }

        if (password_verify($plain, $hash)) {
            return true;
        }

        if (str_starts_with($hash, '$2b$') || str_starts_with($hash, '$2a$')) {
            return password_verify($plain, '$2y$'.substr($hash, 4));
        }

        return false;
    }
}
