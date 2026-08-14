<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMessageThreadRead extends Model
{
    protected $fillable = [
        'user_id',
        'group_id',
        'peer_user_id',
        'last_read_message_id',
    ];

    protected function casts(): array
    {
        return [
            'peer_user_id' => 'integer',
            'last_read_message_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
