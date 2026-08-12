<?php

namespace App\Models;

use App\Enums\GroupInvitationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupInvitation extends Model
{
    protected $fillable = [
        'group_id',
        'inviter_user_id',
        'invitee_user_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => GroupInvitationStatus::class,
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === GroupInvitationStatus::Pending;
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        $status = $this->status instanceof GroupInvitationStatus
            ? $this->status
            : GroupInvitationStatus::tryFrom((string) $this->status) ?? GroupInvitationStatus::Pending;

        return [
            'id' => $this->id,
            'groupId' => $this->group_id,
            'groupName' => $this->group?->name,
            'inviterName' => $this->inviter?->display_name,
            'inviteeUserId' => $this->invitee_user_id,
            'inviteeName' => $this->invitee?->display_name,
            'inviteeEmail' => $this->invitee?->email,
            'status' => $status->value,
            'statusLabel' => __($status->label()),
        ];
    }
}
