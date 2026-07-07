<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    use RedirectsWithFlash;

    public function show(Request $request)
    {
        return view('auth.register', $this->flashFromQuery($request));
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'displayName' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = strtolower(trim($data['email']));
        if (User::query()->where('email', $email)->exists()) {
            return back()->withInput()->with('error', 'このメールアドレスは既に登録されています');
        }

        $user = User::create([
            'email' => $email,
            'display_name' => trim($data['displayName'] ?? '') ?: explode('@', $email)[0],
            'password' => Hash::make($data['password']),
            'role' => 'user',
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/dashboard')->with('notice', '会員登録が完了しました');
    }
}
