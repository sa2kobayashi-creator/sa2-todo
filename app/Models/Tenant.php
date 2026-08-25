<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'owner_user_id',
        'notes',
        'max_users',
        'allow_own_keys',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'allow_own_keys' => 'boolean',
            'max_users' => 'integer',
            'trial_ends_at' => 'date',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function userCount(): int
    {
        return $this->users()->count();
    }

    public function isOwner(?User $user): bool
    {
        return $user !== null && (int) $this->owner_user_id === (int) $user->id;
    }

    public static function defaultMaxUsers(): int
    {
        return max(1, (int) config('commercial.included_users', 5));
    }

    public function isOnTrial(): bool
    {
        if ($this->trial_ends_at === null) {
            return false;
        }

        return $this->trial_ends_at->copy()->endOfDay()->gte(now());
    }

    public function trialDaysLeft(): ?int
    {
        if ($this->trial_ends_at === null) {
            return null;
        }
        if ($this->trial_ends_at->copy()->endOfDay()->lt(now())) {
            return 0;
        }

        return (int) now()->startOfDay()->diffInDays($this->trial_ends_at->copy()->startOfDay());
    }

    public function trialStatusLabel(): string
    {
        if ($this->trial_ends_at === null) {
            return __('本契約');
        }
        if ($this->isOnTrial()) {
            return __('試用中（あと:days日）', ['days' => (int) $this->trialDaysLeft()]);
        }

        return __('試用終了（請求待ち）');
    }

    public function hasUserCapacity(int $additional = 1): bool
    {
        $max = (int) $this->max_users;
        if ($max <= 0) {
            return true;
        }

        return ($this->userCount() + $additional) <= $max;
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'statusLabel' => $this->isSuspended() ? __('停止中') : __('利用中'),
            'ownerUserId' => $this->owner_user_id,
            'ownerName' => $this->owner?->display_name,
            'ownerEmail' => $this->owner?->email,
            'notes' => $this->notes,
            'maxUsers' => (int) $this->max_users,
            'allowOwnKeys' => (bool) $this->allow_own_keys,
            'trialEndsAt' => optional($this->trial_ends_at)?->format('Y-m-d'),
            'trialDaysLeft' => $this->trialDaysLeft(),
            'onTrial' => $this->isOnTrial(),
            'trialStatusLabel' => $this->trialStatusLabel(),
            'userCount' => $this->relationLoaded('users')
                ? $this->users->count()
                : $this->userCount(),
            'createdAt' => optional($this->created_at)?->format('Y-m-d H:i'),
        ];
    }
}
