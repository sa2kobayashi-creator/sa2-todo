<?php

namespace App\Models;

use App\Enums\GroupStatus;
use App\Enums\MenuFeature;
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
        'chat_bg_type',
        'chat_bg_theme',
        'chat_bg_disk',
        'chat_bg_path',
        'chat_bg_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GroupStatus::class,
            'reviewed_at' => 'datetime',
            'chat_bg_updated_at' => 'datetime',
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

    public function menuFeatures(): HasMany
    {
        return $this->hasMany(GroupMenuFeature::class);
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

        $menuFeatures = $this->relationLoaded('menuFeatures')
            ? $this->menuFeatures->pluck('feature')->map(fn ($f) => (string) $f)->values()->all()
            : $this->menuFeatures()->pluck('feature')->map(fn ($f) => (string) $f)->values()->all();

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
            'menuFeatures' => array_values(array_intersect($menuFeatures, MenuFeature::values())),
        ];
    }
}
