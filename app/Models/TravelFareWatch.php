<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelFareWatch extends Model
{
    protected $fillable = [
        'user_id',
        'origin',
        'destination',
        'airline_code',
        'mode',
        'currency',
        'depart_from',
        'depart_to',
        'return_from',
        'return_to',
        'max_price',
        'last_best_price',
        'last_best_on',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'depart_from' => 'date',
            'depart_to' => 'date',
            'return_from' => 'date',
            'return_to' => 'date',
            'max_price' => 'integer',
            'last_best_price' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
