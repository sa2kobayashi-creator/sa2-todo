<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MenuFeature;
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
        $users = User::query()->orderBy('id')->get()->map(fn (User $user) => $this->presentUser($user, $request));

        return view('admin.users.index', array_merge($this->flashFromQuery($request), $this->formMeta(), [
            'users' => $users,
        ]));
    }

    public function show(Request $request, int $id)
    {
        $user = User::query()->findOrFail($id);

        return view('admin.users.show', array_merge($this->flashFromQuery($request), [
            'user' => $this->presentUser($user, $request),
            'menuFeatures' => MenuFeature::assignable(),
        ]));
    }

    public function edit(Request $request, int $id)
    {
        $user = User::query()->findOrFail($id);

        return view('admin.users.edit', array_merge($this->flashFromQuery($request), $this->formMeta(), [
            'user' => $this->presentUser($user, $request),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'displayName' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', new Enum(UserRole::class)],
            'menuFeatures' => ['nullable', 'array'],
            'menuFeatures.*' => ['string', Rule::in(MenuFeature::values())],
        ]);

        $role = UserRole::from($data['role']);
        $menuFeatures = null;
        if ($role !== UserRole::Admin && $request->boolean('menuFeaturesConfigured')) {
            $menuFeatures = array_values(array_intersect($data['menuFeatures'] ?? [], MenuFeature::values()));
        }

        User::create([
            'email' => strtolower(trim($data['email'])),
            'display_name' => trim($data['displayName']),
            'password' => Hash::make($data['password']),
            'role' => $role,
            'menu_features' => $menuFeatures,
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
            'menuFeatures' => ['nullable', 'array'],
            'menuFeatures.*' => ['string', Rule::in(MenuFeature::values())],
        ]);

        $newRole = UserRole::from($data['role']);
        if ($user->id === $request->user()->id && $newRole !== UserRole::Admin) {
            return $this->redirectWithMessage("/admin/users/{$id}/edit", __('自分自身の管理者権限は外せません。'), 'error');
        }

        if ($user->isAdmin() && $newRole !== UserRole::Admin && $this->adminCount() <= 1) {
            return $this->redirectWithMessage("/admin/users/{$id}/edit", __('最後の管理者は降格できません。'), 'error');
        }

        $user->email = strtolower(trim($data['email']));
        $user->display_name = trim($data['displayName']);
        $user->role = $newRole;
        if ($newRole === UserRole::Admin) {
            $user->menu_features = null;
        } elseif ($request->boolean('menuFeaturesConfigured')) {
            $user->menu_features = array_values(array_intersect($data['menuFeatures'] ?? [], MenuFeature::values()));
        }
        $user->save();

        return $this->redirectWithMessage("/admin/users/{$id}", __('ユーザー情報を更新しました。'));
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

    /** @return array<string, mixed> */
    private function formMeta(): array
    {
        return [
            'roles' => UserRole::assignable(),
            'menuFeatures' => MenuFeature::assignable(),
            'menuFeaturesByRole' => collect(UserRole::assignable())
                ->mapWithKeys(fn (UserRole $role) => [$role->value => MenuFeature::defaultsForRole($role)])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentUser(User $user, Request $request): array
    {
        $menuLabels = collect(MenuFeature::assignable())
            ->filter(fn (MenuFeature $feature) => in_array($feature->value, $user->baseMenuFeatures(), true))
            ->map(fn (MenuFeature $feature) => __($feature->label()))
            ->values()
            ->all();

        return [
            ...$user->toPublicArray(),
            'isSelf' => $user->id === $request->user()->id,
            'menuFeatureLabels' => $user->roleEnum() === UserRole::Admin
                ? [__('すべて（管理者）')]
                : $menuLabels,
        ];
    }

    private function adminCount(): int
    {
        return User::query()->where('role', UserRole::Admin->value)->count();
    }
}
