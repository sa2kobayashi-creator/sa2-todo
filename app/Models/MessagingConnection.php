<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessagingConnection extends Model
{
    public const PROVIDER_LINE = 'line';

    public const PROVIDER_MESSENGER = 'messenger';

    protected $fillable = [
        'user_id',
        'provider',
        'external_user_id',
        'display_name',
        'meta',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'linked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
