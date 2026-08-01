<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PasswordSetupController extends Controller
{
    use RedirectsWithFlash;

    public function show(Request $request)
    {
        if (! $request->user()->must_change_password) {
            return redirect('/dashboard');
        }

        return view('auth.setup-password', [
            'user' => $request->user(),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return redirect('/password/setup')->withErrors($validator);
        }

        $user->password = Hash::make((string) $request->input('password'));
        $user->must_change_password = false;
        $user->reset_token = null;
        $user->reset_token_expires_at = null;
        $user->save();

        $request->session()->regenerate();

        return $this->redirectWithMessage('/dashboard', __('パスワードを設定しました。'));
    }
}
