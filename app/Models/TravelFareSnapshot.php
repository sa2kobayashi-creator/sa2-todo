<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelFareSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'travel_trip_id',
        'rt_price_php',
        'ow_out_price_php',
        'ow_back_price_php',
        'rt_price_jpy',
        'ow_out_price_jpy',
        'ow_back_price_jpy',
        'winner_php',
        'winner_jpy',
        'under_budget_jpy',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'rt_price_php' => 'integer',
            'ow_out_price_php' => 'integer',
            'ow_back_price_php' => 'integer',
            'rt_price_jpy' => 'integer',
            'ow_out_price_jpy' => 'integer',
            'ow_back_price_jpy' => 'integer',
            'under_budget_jpy' => 'boolean',
            'captured_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TravelTrip::class, 'travel_trip_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
