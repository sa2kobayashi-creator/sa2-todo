<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MapRoute extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'origin_label',
        'origin_lat',
        'origin_lng',
        'destination_label',
        'destination_lat',
        'destination_lng',
        'travel_mode',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'origin_lat' => 'decimal:7',
            'origin_lng' => 'decimal:7',
            'destination_lat' => 'decimal:7',
            'destination_lng' => 'decimal:7',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
