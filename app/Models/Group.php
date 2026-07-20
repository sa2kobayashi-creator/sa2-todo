<?php

namespace App\Models;

use App\Enums\GroupStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $fillable = [
        'name',
        'description',
        'owner_user_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => GroupStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function isApproved(): bool
    {
        return $this->status === GroupStatus::Approved;
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        $status = $this->status instanceof GroupStatus
            ? $this->status
            : GroupStatus::tryFrom((string) $this->status) ?? GroupStatus::Pending;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'ownerUserId' => $this->owner_user_id,
            'ownerName' => $this->owner?->display_name,
            'status' => $status->value,
            'statusLabel' => __($status->label()),
            'reviewedAt' => optional($this->reviewed_at)?->format('Y-m-d H:i'),
            'reviewNote' => $this->review_note,
            'memberCount' => (int) ($this->members_count ?? $this->members()->count()),
        ];
    }
}
