<?php

namespace App\Services;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\GroupMember;
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
    public function listAllForAdmin(): Collection
    {
        return Group::query()
            ->with('owner')
            ->withCount('members')
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get()
            ->map(fn (Group $group) => $group->toPublicArray());
    }

    /** @return array<string, mixed> */
    public function create(int $ownerUserId, string $name, ?string $description = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('グループ名を入力してください。'));
        }

        return DB::transaction(function () use ($ownerUserId, $name, $description) {
            $group = Group::create([
                'name' => mb_substr($name, 0, 120),
                'description' => $description !== null && trim($description) !== ''
                    ? mb_substr(trim($description), 0, 500)
                    : null,
                'owner_user_id' => $ownerUserId,
                'status' => GroupStatus::Pending,
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

    public function addMember(int $actorUserId, int $groupId, int $memberUserId): void
    {
        $group = Group::query()->findOrFail($groupId);
        $this->assertOwner($group, $actorUserId);
        if (! $group->isApproved()) {
            throw new \InvalidArgumentException(__('承認済みのグループのみメンバーを追加できます。'));
        }
        if (! User::query()->whereKey($memberUserId)->exists()) {
            throw new \InvalidArgumentException(__('ユーザーが見つかりません。'));
        }

        GroupMember::query()->firstOrCreate(
            ['group_id' => $groupId, 'user_id' => $memberUserId],
            ['role' => 'member']
        );
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
