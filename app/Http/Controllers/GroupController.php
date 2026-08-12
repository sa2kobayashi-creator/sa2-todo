<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
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
        $pendingByGroup = [];
        foreach ($ownedGroupIds as $groupId) {
            $details[$groupId] = $this->groups->listMembers((int) $groupId);
            $pendingByGroup[$groupId] = $this->groups->listPendingInvitationsForGroup((int) $groupId);
        }

        return view('groups.index', array_merge($this->flashFromQuery($request), [
            'groups' => $this->groups->listForUser($userId),
            'memberDetails' => $details,
            'pendingByGroup' => $pendingByGroup,
            'pendingInvitations' => $this->groups->listPendingInvitationsForUser($userId),
        ]));
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->groups->create(
                (int) $user->id,
                $data['name'],
                $data['description'] ?? null,
                // 管理者は自分で承認する立場なので、申請を挟まない
                $user->isAdmin() ? (int) $user->id : null
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage('/groups', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/groups', $user->isAdmin()
            ? __('グループを作成しました。')
            : __('グループを申請しました。管理者の承認後に利用できます。'));
    }

    public function inviteMember(Request $request, int $id)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $this->groups->inviteByEmail((int) $request->user()->id, $id, $data['email']);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage('/groups', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/groups', __('招待を送りました。相手が承諾するとメンバーになります。'));
    }

    public function acceptInvitation(Request $request, int $id)
    {
        $user = $request->user();
        $returnTo = $user->canAccess('groups') ? '/groups' : '/dashboard';

        try {
            $this->groups->acceptInvitation((int) $user->id, $id);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('招待を承諾しました。'));
    }

    public function declineInvitation(Request $request, int $id)
    {
        $user = $request->user();
        $returnTo = $user->canAccess('groups') ? '/groups' : '/dashboard';

        try {
            $this->groups->declineInvitation((int) $user->id, $id);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('招待を辞退しました。'));
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

    public function destroy(Request $request, int $id)
    {
        try {
            $this->groups->deleteByOwner((int) $request->user()->id, $id);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage('/groups', $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/groups', __('グループを削除しました。'));
    }
}
