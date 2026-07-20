<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MyPageController extends Controller
{
    use RedirectsWithFlash;

    public function show(Request $request)
    {
        $user = $request->user();

        return view('mypage.index', array_merge($this->flashFromQuery($request), [
            'user' => $user->toPublicArray(),
            'role' => $user->roleEnum(),
            'features' => $user->roleEnum()->features(),
        ]));
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'displayName' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $user->display_name = trim($data['displayName']);
        $user->email = strtolower(trim($data['email']));

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return $this->redirectWithMessage('/mypage', __('プロフィールを更新しました。'));
    }
}
