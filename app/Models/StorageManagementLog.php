<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageManagementLog extends Model
{
    protected $fillable = [
        'ran_at',
        'r2_bytes',
        'mail_bytes',
        'db_bytes',
        'r2_status',
        'mail_status',
        'db_status',
        'mail_archived',
        'photos_archived',
        'db_archived',
        'backup_key',
        'notes',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'notes' => 'array',
        ];
    }
}
