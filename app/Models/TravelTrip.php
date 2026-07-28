<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelTrip extends Model
{
    protected $fillable = [
        'user_id',
        'purpose',
        'label',
        'depart_on',
        'return_on',
        'origin',
        'destination',
        'airline_code',
        'status',
        'prefer_currency',
        'booked_as',
        'rt_price_php',
        'ow_out_price_php',
        'ow_back_price_php',
        'rt_price_jpy',
        'ow_out_price_jpy',
        'ow_back_price_jpy',
        'out_booked_in_php',
        'back_booked_in_php',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'depart_on' => 'date',
            'return_on' => 'date',
            'rt_price_php' => 'integer',
            'ow_out_price_php' => 'integer',
            'ow_back_price_php' => 'integer',
            'rt_price_jpy' => 'integer',
            'ow_out_price_jpy' => 'integer',
            'ow_back_price_jpy' => 'integer',
            'out_booked_in_php' => 'boolean',
            'back_booked_in_php' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
