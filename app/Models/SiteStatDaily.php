<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteStatDaily extends Model
{
    protected $table = 'site_stats_daily';

    protected $fillable = [
        'stat_date',
        'event_key',
        'count',
    ];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'count' => 'integer',
        ];
    }
}
