<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'displayName' => $this->display_name,
            'role' => $this->role,
            'roleLabel' => $this->role === 'admin' ? '管理者' : 'ユーザー',
        ];
    }
}
