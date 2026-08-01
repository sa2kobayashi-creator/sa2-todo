<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MenuFeature;
use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GroupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(private GroupService $groups) {}

    public function index(Request $request)
    {
        return view('admin.groups.index', array_merge($this->flashFromQuery($request), [
            'groups' => $this->groups->listAllForAdmin(),
            'menuFeatures' => MenuFeature::assignable(),
            'users' => User::query()->orderBy('display_name')->get()->map->toPublicArray(),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $ownerUserId = (int) ($data['owner_user_id'] ?? $request->user()->id);

        try {
            // 管理者が作るグループは承認済みで登録する
            $this->groups->create(
                $ownerUserId,
                $data['name'],
                $data['description'] ?? null,
                (int) $request->user()->id
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage('/admin/groups', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/admin/groups', __('グループを作成しました。'));
    }

    public function approve(Request $request, int $id)
    {
        $this->groups->approve($id, (int) $request->user()->id, $request->input('review_note'));

        return $this->redirectWithMessage('/admin/groups', __('グループを承認しました。'));
    }

    public function reject(Request $request, int $id)
    {
        $this->groups->reject($id, (int) $request->user()->id, $request->input('review_note'));

        return $this->redirectWithMessage('/admin/groups', __('グループを却下しました。'));
    }

    public function updateMenus(Request $request, int $id)
    {
        $data = $request->validate([
            'menuFeatures' => ['nullable', 'array'],
            'menuFeatures.*' => ['string', Rule::in(MenuFeature::values())],
        ]);

        $this->groups->syncMenuFeatures($id, $data['menuFeatures'] ?? []);

        return $this->redirectWithMessage('/admin/groups', __('グループのメニュー権限を更新しました。'));
    }

    public function destroy(int $id)
    {
        $this->groups->deleteByAdmin($id);

        return $this->redirectWithMessage('/admin/groups', __('グループを削除しました。'));
    }
}
