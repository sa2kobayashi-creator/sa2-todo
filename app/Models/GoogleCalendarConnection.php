<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCalendarConnection extends Model
{
    protected $fillable = [
        'user_id',
        'google_user_id',
        'google_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'selected_calendar_ids',
        'sync_calendar_id',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'selected_calendar_ids' => 'array',
        ];
    }

    /** @return list<string> */
    public function selectedCalendarIds(): array
    {
        $ids = $this->selected_calendar_ids;
        if (! is_array($ids) || $ids === []) {
            return ['primary'];
        }

        return array_values(array_filter(array_map('strval', $ids)));
    }

    public function syncCalendarId(): string
    {
        $id = trim((string) ($this->sync_calendar_id ?? ''));

        return $id !== '' ? $id : 'primary';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessTokenExpired(): bool
    {
        if ($this->token_expires_at === null) {
            return true;
        }

        // 60秒の余裕を持って期限切れ扱いにする
        return $this->token_expires_at->lte(now()->addSeconds(60));
    }
}
