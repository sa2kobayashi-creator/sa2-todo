<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Models\User;
use App\Services\GroupService;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(private GroupService $groups) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $ownedGroupIds = collect($this->groups->listForUser($userId))
            ->where('ownerUserId', $userId)
            ->pluck('id')
            ->all();

        $details = [];
        foreach ($ownedGroupIds as $groupId) {
            $details[$groupId] = $this->groups->listMembers((int) $groupId);
        }

        return view('groups.index', array_merge($this->flashFromQuery($request), [
            'groups' => $this->groups->listForUser($userId),
            'users' => User::query()->orderBy('display_name')->get()->map->toPublicArray(),
            'memberDetails' => $details,
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->groups->create(
                (int) $request->user()->id,
                $data['name'],
                $data['description'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage('/groups', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/groups', __('グループを申請しました。管理者の承認後に利用できます。'));
    }

    public function addMember(Request $request, int $id)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->groups->addMember((int) $request->user()->id, $id, (int) $data['user_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage('/groups', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/groups', __('メンバーを追加しました。'));
    }

    public function removeMember(Request $request, int $id)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        try {
            $this->groups->removeMember((int) $request->user()->id, $id, (int) $data['user_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage('/groups', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/groups', __('メンバーを削除しました。'));
    }
}
