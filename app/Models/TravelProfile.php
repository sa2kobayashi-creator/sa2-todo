<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelProfile extends Model
{
    protected $fillable = [
        'user_id',
        'visa_type',
        'rp_expires_on',
        'rp_duration_months',
        'annual_report_done_year',
        'budget_max_jpy',
        'preferred_currency',
        'home_airport',
        'ph_airport',
        'airline_code',
        'notes',
        'alerts_enabled',
        'alert_days_rp',
        'alert_days_ar',
        'procedures_enabled',
        'promo_watch_enabled',
    ];

    protected function casts(): array
    {
        return [
            'rp_expires_on' => 'date',
            'rp_duration_months' => 'integer',
            'annual_report_done_year' => 'integer',
            'budget_max_jpy' => 'integer',
            'alerts_enabled' => 'boolean',
            'alert_days_rp' => 'integer',
            'alert_days_ar' => 'integer',
            'procedures_enabled' => 'boolean',
            'promo_watch_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
