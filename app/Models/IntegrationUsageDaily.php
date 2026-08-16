<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationUsageDaily extends Model
{
    protected $fillable = ['provider', 'metric', 'usage_date', 'amount'];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'amount' => 'integer',
        ];
    }
}
