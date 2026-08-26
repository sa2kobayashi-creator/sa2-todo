<?php

namespace App\Services;

use App\Enums\GroupInvitationStatus;
use App\Enums\GroupStatus;
use App\Enums\MenuFeature;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\GroupMember;
use App\Models\GroupMenuFeature;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GroupService
{
    /** @return list<int> */
    public function approvedGroupIdsForUser(int $userId): array
    {
        return GroupMember::query()
            ->where('user_id', $userId)
            ->whereHas('group', fn ($q) => $q->where('status', GroupStatus::Approved->value))
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function userBelongsToApprovedGroup(int $userId, int $groupId): bool
    {
        return GroupMember::query()
            ->where('user_id', $userId)
            ->where('group_id', $groupId)
            ->whereHas('group', fn ($q) => $q->where('status', GroupStatus::Approved->value))
            ->exists();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function listForUser(int $userId): Collection
    {
        return Group::query()
            ->with('owner')
            ->withCount('members')
            ->whereHas('members', fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('id')
            ->get()
            ->map(fn (Group $group) => $group->toPublicArray());
    }

    /** @return Collection<int, array<string, mixed>> */
    public function listApprovedForUser(int $userId): Collection
    {
        return Group::query()
            ->with('owner')
            ->withCount('members')
            ->where('status', GroupStatus::Approved->value)
            ->whereHas('members', fn ($q) => $q->where('user_id', $userId))
            ->orderBy('name')
            ->get()
            ->map(fn (Group $group) => $group->toPublicArray());
    }

    /** @return Collection<int, array<string, mixed>> */
    public function listAllForAdmin(?User $actor = null): Collection
    {
        $query = Group::query()
            ->with(['owner', 'menuFeatures'])
            ->withCount('members');

        if ($actor && ! $actor->isSuperAdmin()) {
            if ($actor->tenant_id) {
                $query->whereHas('owner', fn ($q) => $q->where('tenant_id', $actor->tenant_id));
            } else {
                $query->whereHas('owner', fn ($q) => $q->whereNull('tenant_id'));
            }
        }

        return $query
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get()
            ->map(fn (Group $group) => $group->toPublicArray());
    }

    /** @param list<string> $features */
    public function syncMenuFeatures(int $groupId, array $features): array
    {
        $group = Group::query()->findOrFail($groupId);
        $allowed = array_values(array_intersect($features, MenuFeature::assignableValues()));

        DB::transaction(function () use ($group, $allowed) {
            GroupMenuFeature::query()->where('group_id', $group->id)->delete();

            foreach ($allowed as $feature) {
                GroupMenuFeature::create([
                    'group_id' => $group->id,
                    'feature' => $feature,
                ]);
            }
        });

        return $group->load(['owner', 'menuFeatures'])->loadCount('members')->toPublicArray();
    }

    /**
     * グループを作る。$approvedByUserId を渡すと申請を挟まずに承認済みで作成する（管理者用）。
     *
     * @return array<string, mixed>
     */
    public function create(
        int $ownerUserId,
        string $name,
        ?string $description = null,
        ?int $approvedByUserId = null,
    ): array {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('グループ名を入力してください。'));
        }

        if (! User::query()->whereKey($ownerUserId)->exists()) {
            throw new \InvalidArgumentException(__('ユーザーが見つかりません。'));
        }

        return DB::transaction(function () use ($ownerUserId, $name, $description, $approvedByUserId) {
            $approved = $approvedByUserId !== null;

            $group = Group::create([
                'name' => mb_substr($name, 0, 120),
                'description' => $description !== null && trim($description) !== ''
                    ? mb_substr(trim($description), 0, 500)
                    : null,
                'owner_user_id' => $ownerUserId,
                'status' => $approved ? GroupStatus::Approved : GroupStatus::Pending,
                'reviewed_by' => $approvedByUserId,
                'reviewed_at' => $approved ? now() : null,
            ]);

            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $ownerUserId,
                'role' => 'owner',
            ]);

            return $group->load('owner')->loadCount('members')->toPublicArray();
        });
    }

    public function approve(int $groupId, int $adminUserId, ?string $note = null): array
    {
        return $this->review($groupId, $adminUserId, GroupStatus::Approved, $note);
    }

    public function reject(int $groupId, int $adminUserId, ?string $note = null): array
    {
        return $this->review($groupId, $adminUserId, GroupStatus::Rejected, $note);
    }

    private function review(int $groupId, int $adminUserId, GroupStatus $status, ?string $note): array
    {
        $group = Group::query()->findOrFail($groupId);
        $group->status = $status;
        $group->reviewed_by = $adminUserId;
        $group->reviewed_at = now();
        $group->review_note = $note !== null && trim($note) !== ''
            ? mb_substr(trim($note), 0, 500)
            : null;
        $group->save();

        return $group->load('owner')->loadCount('members')->toPublicArray();
    }

    /**
     * 登録済みユーザーをメール完全一致で招待する。承諾するまでメンバーにはならない。
     *
     * @return array<string, mixed>
     */
    public function inviteByEmail(int $actorUserId, int $groupId, string $email): array
    {
        $group = Group::query()->findOrFail($groupId);
        $this->assertOwner($group, $actorUserId);
        if (! $group->isApproved()) {
            throw new \InvalidArgumentException(__('承認済みのグループのみメンバーを招待できます。'));
        }

        $email = strtolower(trim($email));
        if ($email === '') {
            throw new \InvalidArgumentException(__('メールアドレスを入力してください。'));
        }

        $invitee = User::query()->where('email', $email)->first();
        if (! $invitee) {
            throw new \InvalidArgumentException(__('そのメールのユーザーは見つかりません。先にアカウントを作成してもらってください。'));
        }
        $actor = User::query()->find($actorUserId);
        if ($actor && ! $actor->sharesContractWith($invitee)) {
            throw new \InvalidArgumentException(__('別の契約のユーザーは招待できません。'));
        }
        if ((int) $invitee->id === $actorUserId) {
            throw new \InvalidArgumentException(__('自分自身は招待できません。'));
        }
        if ($this->userBelongsToApprovedGroup((int) $invitee->id, $groupId)) {
            throw new \InvalidArgumentException(__('すでにメンバーです。'));
        }

        $invitation = GroupInvitation::query()->firstOrNew([
            'group_id' => $groupId,
            'invitee_user_id' => $invitee->id,
        ]);
        if ($invitation->exists && $invitation->isPending()) {
            throw new \InvalidArgumentException(__('すでに招待中です。'));
        }

        $invitation->inviter_user_id = $actorUserId;
        $invitation->status = GroupInvitationStatus::Pending;
        $invitation->save();

        return $invitation->load(['group', 'inviter', 'invitee'])->toPublicArray();
    }

    public function acceptInvitation(int $userId, int $invitationId): void
    {
        $invitation = $this->pendingInvitationForInvitee($userId, $invitationId);
        $group = $invitation->group;
        if (! $group || ! $group->isApproved()) {
            throw new \InvalidArgumentException(__('このグループはまだ利用できません。'));
        }

        GroupMember::query()->firstOrCreate(
            ['group_id' => $invitation->group_id, 'user_id' => $userId],
            ['role' => 'member']
        );
        $invitation->status = GroupInvitationStatus::Accepted;
        $invitation->save();
    }

    public function declineInvitation(int $userId, int $invitationId): void
    {
        $invitation = $this->pendingInvitationForInvitee($userId, $invitationId);
        $invitation->status = GroupInvitationStatus::Declined;
        $invitation->save();
    }

    /** @return list<array<string, mixed>> */
    public function listPendingInvitationsForUser(int $userId): array
    {
        return GroupInvitation::query()
            ->with(['group', 'inviter', 'invitee'])
            ->where('invitee_user_id', $userId)
            ->where('status', GroupInvitationStatus::Pending->value)
            ->orderByDesc('id')
            ->get()
            ->map(fn (GroupInvitation $invitation) => $invitation->toPublicArray())
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function listPendingInvitationsForGroup(int $groupId): array
    {
        return GroupInvitation::query()
            ->with(['group', 'inviter', 'invitee'])
            ->where('group_id', $groupId)
            ->where('status', GroupInvitationStatus::Pending->value)
            ->orderByDesc('id')
            ->get()
            ->map(fn (GroupInvitation $invitation) => $invitation->toPublicArray())
            ->all();
    }

    private function pendingInvitationForInvitee(int $userId, int $invitationId): GroupInvitation
    {
        $invitation = GroupInvitation::query()->with('group')->find($invitationId);
        if (! $invitation || (int) $invitation->invitee_user_id !== $userId || ! $invitation->isPending()) {
            throw new \InvalidArgumentException(__('招待が見つかりません。'));
        }

        return $invitation;
    }

    public function removeMember(int $actorUserId, int $groupId, int $memberUserId): void
    {
        $group = Group::query()->findOrFail($groupId);
        $this->assertOwner($group, $actorUserId);
        if ((int) $group->owner_user_id === $memberUserId) {
            throw new \InvalidArgumentException(__('オーナーはメンバーから外せません。'));
        }

        GroupMember::query()
            ->where('group_id', $groupId)
            ->where('user_id', $memberUserId)
            ->delete();
    }

    public function deleteByOwner(int $actorUserId, int $groupId): void
    {
        $group = Group::query()->findOrFail($groupId);
        $this->assertOwner($group, $actorUserId);
        $group->delete();
    }

    public function deleteByAdmin(int $groupId): void
    {
        Group::query()->findOrFail($groupId)->delete();
    }

    /** @return list<array<string, mixed>> */
    public function listMembers(int $groupId): array
    {
        return GroupMember::query()
            ->with('user')
            ->where('group_id', $groupId)
            ->orderBy('id')
            ->get()
            ->map(fn (GroupMember $member) => [
                'userId' => $member->user_id,
                'displayName' => $member->user?->display_name,
                'email' => $member->user?->email,
                'role' => $member->role,
            ])
            ->all();
    }

    private function assertOwner(Group $group, int $userId): void
    {
        if ((int) $group->owner_user_id !== $userId) {
            throw new \InvalidArgumentException(__('グループのオーナーのみ操作できます。'));
        }
    }
}
