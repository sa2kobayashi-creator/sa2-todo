<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MenuFeature;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteUserAccountJob;
use App\Models\User;
use App\Services\BillingEntitlementService;
use App\Support\FooterNav;
use App\Support\Registration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(
        private BillingEntitlementService $billing,
    ) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        $users = User::query()->orderBy('id')->get()->map(fn (User $user) => $this->presentUser($user, $request));

        return view('admin.users.index', array_merge($this->flashFromQuery($request), $this->formMeta($actor), [
            'users' => $users,
            'canManageRegistration' => $actor->isAdmin(),
            'registrationInviteCode' => Registration::inviteCode(),
            'registrationConfiguredInDatabase' => Registration::isConfiguredInDatabase(),
            'registrationOpen' => Registration::isOpen(),
        ]));
    }

    public function updateRegistration(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            abort(403, __('招待コードを変更できるのは管理者だけです。'));
        }

        $data = $request->validate([
            'inviteCode' => ['nullable', 'string', 'max:120'],
            'clearInviteCode' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('clearInviteCode')) {
            Registration::setInviteCode('');
        } else {
            Registration::setInviteCode($data['inviteCode'] ?? '');
        }

        $message = Registration::isOpen()
            ? __('招待コードを保存しました。このコードを知っている人だけ自己登録できます。')
            : __('招待コードを空にしたので、新規の自己登録は閉じました。');

        return $this->redirectWithMessage('/admin/users', $message);
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
        $actor = $request->user();
        $user = User::query()->findOrFail($id);

        if ($error = $this->guardTargetEditable($actor, $user)) {
            return $this->redirectWithMessage('/admin/users', $error, 'error');
        }

        return view('admin.users.edit', array_merge($this->flashFromQuery($request), $this->formMeta($actor), [
            'user' => $this->presentUser($user, $request),
        ]));
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        $allowedRoles = array_map(fn (UserRole $role) => $role->value, UserRole::assignableBy($actor->roleEnum()));

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'displayName' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in($allowedRoles)],
            'menuFeatures' => ['nullable', 'array'],
            'menuFeatures.*' => ['string', Rule::in(MenuFeature::values())],
        ]);

        $role = UserRole::from($data['role']);
        $menuFeatures = null;
        if (! $role->isStaff() && $request->boolean('menuFeaturesConfigured')) {
            $menuFeatures = $this->normalizeMenuFeaturesForStorage($role, $data['menuFeatures'] ?? []);
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
        $actor = $request->user();
        $user = User::query()->findOrFail($id);

        if ($error = $this->guardTargetEditable($actor, $user)) {
            return $this->redirectWithMessage("/admin/users/{$id}/edit", $error, 'error');
        }

        $allowedRoles = array_map(fn (UserRole $role) => $role->value, UserRole::assignableBy($actor->roleEnum()));
        // 編集対象の現在ロールが選択肢外でも、変更しない限り維持できるよう含める
        $allowedRoles[] = $user->roleEnum()->value;
        $allowedRoles = array_values(array_unique($allowedRoles));

        $data = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'displayName' => ['required', 'string', 'max:100'],
            'role' => ['required', 'string', Rule::in($allowedRoles)],
            'menuFeatures' => ['nullable', 'array'],
            'menuFeatures.*' => ['string', Rule::in(MenuFeature::values())],
            'subscriptionStatus' => ['nullable', 'string', Rule::in(array_map(
                fn (SubscriptionStatus $s) => $s->value,
                SubscriptionStatus::assignable()
            ))],
            'trialEndsAt' => ['nullable', 'date'],
            'storageOverageActive' => ['nullable', 'boolean'],
            'mailboxAddonActive' => ['nullable', 'boolean'],
        ]);

        $newRole = UserRole::from($data['role']);

        if ($user->id === $actor->id && ! $newRole->isStaff()) {
            return $this->redirectWithMessage("/admin/users/{$id}/edit", __('自分自身の管理者権限は外せません。'), 'error');
        }

        if ($user->isSuperAdmin() && $newRole !== UserRole::SuperAdmin && $this->superAdminCount() <= 1) {
            return $this->redirectWithMessage("/admin/users/{$id}/edit", __('最後のスーパー管理者は降格できません。'), 'error');
        }

        if (! $actor->isSuperAdmin() && $newRole === UserRole::SuperAdmin) {
            return $this->redirectWithMessage("/admin/users/{$id}/edit", __('スーパー管理者を付与できるのはスーパー管理者だけです。'), 'error');
        }

        $user->email = strtolower(trim($data['email']));
        $user->display_name = trim($data['displayName']);
        $user->role = $newRole;
        if ($newRole->isStaff()) {
            $user->menu_features = null;
        } else {
            // 編集フォームは常に利用メニューを送る（未チェック＝追加メニューなし）。
            $user->menu_features = $this->normalizeMenuFeaturesForStorage($newRole, $data['menuFeatures'] ?? []);
        }

        // 利用メニュー変更後、表示メニュー設定から権限外の項目を落とす
        if (! $newRole->isStaff()) {
            if (is_array($user->footer_nav)) {
                $user->footer_nav = FooterNav::normalizeFooterKeys($user->footer_nav, $user);
            }
            if (is_array($user->header_nav)) {
                $user->header_nav = FooterNav::normalizeHeaderKeys($user->header_nav, $user);
            }
        }

        $user->save();

        // 契約状態は Stripe 前の手動運用向け。権限ロールとは別に保存する。
        $this->billing->apply($user, [
            'subscription_status' => $data['subscriptionStatus'] ?? SubscriptionStatus::None->value,
            'trial_ends_at' => $data['trialEndsAt'] ?? null,
            'storage_overage_active' => $request->boolean('storageOverageActive'),
            'mailbox_addon_active' => $request->boolean('mailboxAddonActive'),
        ]);

        return $this->redirectWithMessage("/admin/users/{$id}", __('ユーザー情報を更新しました。'));
    }

    public function destroy(Request $request, int $id)
    {
        $actor = $request->user();
        $user = User::query()->findOrFail($id);

        if ($user->id === $actor->id) {
            return $this->redirectWithMessage('/admin/users', __('自分自身は削除できません。'), 'error');
        }

        if ($error = $this->guardTargetEditable($actor, $user)) {
            return $this->redirectWithMessage('/admin/users', $error, 'error');
        }

        if ($user->isSuperAdmin() && $this->superAdminCount() <= 1) {
            return $this->redirectWithMessage('/admin/users', __('最後のスーパー管理者は削除できません。'), 'error');
        }

        $userId = (int) $user->id;
        $user->forceFill([
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
        ])->save();

        DeleteUserAccountJob::dispatchAfterHttp($userId);

        return $this->redirectWithMessage('/admin/users', __('ユーザー削除を開始しました。'));
    }

    /** @return array<string, mixed> */
    private function formMeta(User $actor): array
    {
        $assignable = UserRole::assignableBy($actor->roleEnum());

        return [
            'roles' => UserRole::assignable(),
            'assignableRoles' => $assignable,
            'subscriptionStatuses' => SubscriptionStatus::assignable(),
            'menuFeatures' => MenuFeature::assignable(),
            'menuFeaturesByRole' => collect(UserRole::assignable())
                ->mapWithKeys(fn (UserRole $role) => [$role->value => MenuFeature::defaultsForRole($role)])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentUser(User $user, Request $request): array
    {
        $actor = $request->user();
        $menuLabels = collect(MenuFeature::assignable())
            ->filter(fn (MenuFeature $feature) => in_array($feature->value, $user->baseMenuFeatures(), true))
            ->map(fn (MenuFeature $feature) => __($feature->label()))
            ->values()
            ->all();

        $staffLabel = $user->isSuperAdmin()
            ? __('すべて（スーパー管理者）')
            : __('すべて（管理者）');

        return [
            ...$user->toPublicArray(),
            'isSelf' => $user->id === $actor->id,
            'canManageTarget' => $this->guardTargetEditable($actor, $user) === null,
            'menuFeatureLabels' => $user->isAdmin()
                ? [$staffLabel]
                : $menuLabels,
        ];
    }

    /**
     * ロール既定と同じ選択なら null（グループ付与を有効のまま）。
     * それ以外（空配列含む）は明示の許可リストとして保存する。
     *
     * @param  list<string>|array<int, string>  $selected
     * @return list<string>|null
     */
    private function normalizeMenuFeaturesForStorage(UserRole $role, array $selected): ?array
    {
        if ($role->isStaff()) {
            return null;
        }

        $selected = array_values(array_intersect($selected, MenuFeature::values()));
        $defaults = MenuFeature::defaultsForRole($role);
        $sortedSelected = $selected;
        $sortedDefaults = $defaults;
        sort($sortedSelected);
        sort($sortedDefaults);

        if ($sortedSelected === $sortedDefaults) {
            return null;
        }

        return $selected;
    }

    private function guardTargetEditable(User $actor, User $target): ?string
    {
        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            return __('スーパー管理者はスーパー管理者のみ編集できます。');
        }

        return null;
    }

    private function superAdminCount(): int
    {
        return User::query()->where('role', UserRole::SuperAdmin->value)->count();
    }
}
