<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeekdayRule extends Model
{
    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'weekdays',
        'exceptions',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'weekdays' => 'array',
            'exceptions' => 'array',
        ];
    }
}
