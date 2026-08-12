<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDailyUsage extends Model
{
    protected $fillable = [
        'user_id',
        'usage_date',
        'feature',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
