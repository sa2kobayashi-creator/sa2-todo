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
            'items' => 'array',
            'registered_date' => 'date:Y-m-d',
        ];
    }
}
