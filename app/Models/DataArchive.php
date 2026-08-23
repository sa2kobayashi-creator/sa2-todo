<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataArchive extends Model
{
    protected $fillable = [
        'user_id',
        'source_table',
        'source_id',
        'title',
        'summary',
        'source_created_at',
        'source_updated_at',
        'storage_provider',
        'storage_key',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
