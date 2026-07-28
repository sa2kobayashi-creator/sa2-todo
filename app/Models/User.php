<?php

namespace App\Models;

use App\Enums\GroupStatus;
use App\Enums\MenuFeature;
use App\Enums\UserRole;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'email',
        'display_name',
        'password',
        'role',
        'menu_features',
        'reset_token',
        'reset_token_expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'reset_token',
    ];

    protected function casts(): array
    {
        return [
            'reset_token_expires_at' => 'datetime',
            'role' => UserRole::class,
            'menu_features' => 'array',
        ];
    }

    public function roleEnum(): UserRole
    {
        return $this->role instanceof UserRole
            ? $this->role
            : UserRole::tryFrom((string) $this->role) ?? UserRole::Standard;
    }

    public function isAdmin(): bool
    {
        return $this->roleEnum() === UserRole::Admin;
    }

    public function canAccess(string $feature): bool
    {
        if ($this->isAdmin()) {
            return UserRole::Admin->canAccess($feature);
        }

        if (MenuFeature::tryFrom($feature) !== null) {
            return in_array($feature, $this->effectiveMenuFeatures(), true);
        }

        return $this->roleEnum()->canAccess($feature);
    }

    /** @return list<string> */
    public function baseMenuFeatures(): array
    {
        if ($this->isAdmin()) {
            return MenuFeature::values();
        }

        if (is_array($this->menu_features)) {
            return array_values(array_intersect($this->menu_features, MenuFeature::values()));
        }

        return MenuFeature::defaultsForRole($this->roleEnum());
    }

    /** @return list<string> */
    public function groupMenuFeatures(): array
    {
        if ($this->isAdmin()) {
            return [];
        }

        return DB::table('group_menu_features')
            ->join('group_members', 'group_members.group_id', '=', 'group_menu_features.group_id')
            ->join('groups', 'groups.id', '=', 'group_menu_features.group_id')
            ->where('group_members.user_id', $this->id)
            ->where('groups.status', GroupStatus::Approved->value)
            ->whereIn('group_menu_features.feature', MenuFeature::values())
            ->distinct()
            ->pluck('group_menu_features.feature')
            ->map(fn ($feature) => (string) $feature)
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function effectiveMenuFeatures(): array
    {
        if ($this->isAdmin()) {
            return MenuFeature::values();
        }

        return array_values(array_unique([
            ...$this->baseMenuFeatures(),
            ...$this->groupMenuFeatures(),
        ]));
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        $role = $this->roleEnum();

        return [
            'id' => $this->id,
            'email' => $this->email,
            'displayName' => $this->display_name,
            'role' => $role->value,
            'roleLabel' => __($role->label()),
            'roleDescription' => __($role->description()),
            'menuFeatures' => $this->baseMenuFeatures(),
            'hasCustomMenuFeatures' => is_array($this->menu_features),
            'createdAt' => optional($this->created_at)?->format('Y-m-d H:i'),
            'updatedAt' => optional($this->updated_at)?->format('Y-m-d H:i'),
        ];
    }
}
