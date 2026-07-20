<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'email',
        'display_name',
        'password',
        'role',
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
        return $this->roleEnum()->canAccess($feature);
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
            'createdAt' => optional($this->created_at)?->format('Y-m-d H:i'),
            'updatedAt' => optional($this->updated_at)?->format('Y-m-d H:i'),
        ];
    }
}
