<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GroupMessage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'group_id',
        'user_id',
        'recipient_user_id',
        'reply_to_id',
        'body',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

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

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(GroupMessageAttachment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(GroupMessageReaction::class);
    }

    public function hides(): HasMany
    {
        return $this->hasMany(GroupMessageHide::class);
    }

    public function isDirect(): bool
    {
        return $this->recipient_user_id !== null;
    }
}
