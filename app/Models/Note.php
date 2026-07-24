<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Note extends Model
{
    protected $fillable = [
        'user_id',
        'group_id',
        'title',
        'body',
        'color',
        'pinned',
        'sort_order',
        'archived',
        'type',
        'category',
        'items',
        'registered_date',
    ];

    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'archived' => 'boolean',
            'sort_order' => 'integer',
            'items' => 'array',
            'registered_date' => 'date:Y-m-d',
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

    public function attachments(): HasMany
    {
        return $this->hasMany(NoteAttachment::class)->orderBy('id');
    }
}
