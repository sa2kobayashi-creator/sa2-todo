<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    protected $fillable = [
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
}
