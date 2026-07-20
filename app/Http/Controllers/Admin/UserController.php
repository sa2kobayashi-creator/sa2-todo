<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UserController extends Controller
{
    use RedirectsWithFlash;

    public function index(Request $request)
    {
        $users = User::query()->orderBy('id')->get()->map(fn (User $user) => [
            ...$user->toPublicArray(),
            'isSelf' => $user->id === $request->user()->id,
        ]);

        return view('admin.users.index', array_merge($this->flashFromQuery($request), [
            'users' => $users,
            'roles' => UserRole::assignable(),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'displayName' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', new Enum(UserRole::class)],
        ]);

        User::create([
            'email' => strtolower(trim($data['email'])),
            'display_name' => trim($data['displayName']),
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);

        return $this->redirectWithMessage('/admin/users', __('ユーザーを追加しました。'));
    }

    public function update(Request $request, int $id)
    {
        $user = User::query()->findOrFail($id);

        $data = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'displayName' => ['required', 'string', 'max:100'],
            'role' => ['required', new Enum(UserRole::class)],
        ]);

        $newRole = UserRole::from($data['role']);
        if ($user->id === $request->user()->id && $newRole !== UserRole::Admin) {
            return $this->redirectWithMessage('/admin/users', __('自分自身の管理者権限は外せません。'), 'error');
        }

        if ($user->isAdmin() && $newRole !== UserRole::Admin && $this->adminCount() <= 1) {
            return $this->redirectWithMessage('/admin/users', __('最後の管理者は降格できません。'), 'error');
        }

        $user->email = strtolower(trim($data['email']));
        $user->display_name = trim($data['displayName']);
        $user->role = $newRole;
        $user->save();

        return $this->redirectWithMessage('/admin/users', __('ユーザー情報を更新しました。'));
    }

    public function updatePassword(Request $request, int $id)
    {
        $user = User::query()->findOrFail($id);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->password = Hash::make($data['password']);
        $user->save();

        return $this->redirectWithMessage('/admin/users', __('パスワードを更新しました。'));
    }

    public function destroy(Request $request, int $id)
    {
        $user = User::query()->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return $this->redirectWithMessage('/admin/users', __('自分自身は削除できません。'), 'error');
        }

        if ($user->isAdmin() && $this->adminCount() <= 1) {
            return $this->redirectWithMessage('/admin/users', __('最後の管理者は削除できません。'), 'error');
        }

        $user->delete();

        return $this->redirectWithMessage('/admin/users', __('ユーザーを削除しました。'));
    }

    private function adminCount(): int
    {
        return User::query()->where('role', UserRole::Admin->value)->count();
    }
}
