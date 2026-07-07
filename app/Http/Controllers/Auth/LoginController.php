<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    use RedirectsWithFlash;

    public function show(Request $request)
    {
        return view('auth.login', [
            'returnTo' => $this->safeReturnTo($request->query('returnTo'), '/dashboard'),
            'email' => $request->old('email'),
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
            return back()->withInput(['email'])->with('error', 'メールアドレスまたはパスワードが正しくありません');
        }

        if (str_starts_with($user->password, '$2b$') || str_starts_with($user->password, '$2a$')) {
            $user->password = Hash::make($credentials['password']);
            $user->save();
        }

        Auth::login($user, true);
        $request->session()->regenerate();

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
