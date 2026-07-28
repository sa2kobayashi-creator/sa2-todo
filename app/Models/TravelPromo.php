<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelPromo extends Model
{
    protected $fillable = [
        'user_id',
        'external_key',
        'code',
        'title',
        'source_url',
        'applies_to',
        'status',
        'valid_from',
        'valid_until',
        'travel_from',
        'travel_until',
        'notes',
        'auto_fetched',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'travel_from' => 'date',
            'travel_until' => 'date',
            'auto_fetched' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
