<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupMessage extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'recipient_user_id',
        'body',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(GroupMessageAttachment::class);
    }

    public function isDirect(): bool
    {
        return $this->recipient_user_id !== null;
    }
}
