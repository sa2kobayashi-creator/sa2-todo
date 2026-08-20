<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TodoShortcutTitle extends Model
{
    protected $fillable = [
        'todo_shortcut_category_id',
        'title',
        'start_time',
        'end_time',
        'reminders',
        'notify_via',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'reminders' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TodoShortcutCategory::class, 'todo_shortcut_category_id');
    }
}
