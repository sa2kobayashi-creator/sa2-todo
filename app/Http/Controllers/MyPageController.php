<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Services\EmailChangeService;
use App\Services\GroupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MyPageController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(
        private GroupService $groups,
        private EmailChangeService $emailChange,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        return view('mypage.index', array_merge($this->flashFromQuery($request), [
            'user' => $user->toPublicArray(),
            'role' => $user->roleEnum(),
            'features' => $user->roleEnum()->features(),
            'groups' => $this->groups->listForUser($user->id)->all(),
            'hasPendingEmail' => $this->emailChange->hasPendingChange($user),
        ]));
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $validator = Validator::make($request->all(), [
            'displayName' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        if ($validator->fails()) {
            return redirect('/mypage')->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        $user->display_name = trim($data['displayName']);
        $user->save();

        // メールアドレスはログインIDなので、新しい宛先で受信できることを確認してから反映する
        if ($data['email'] !== $user->email) {
            $this->emailChange->startChange($user, $data['email']);

            return $this->redirectWithMessage(
                '/mypage/email/verify',
                __('確認コードを:emailに送信しました。コードを入力すると変更が完了します。', ['email' => $data['email']])
            );
        }

        return $this->redirectWithMessage('/mypage', __('プロフィールを更新しました。'));
    }
}
