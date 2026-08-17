<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailMessageHeader extends Model
{
    protected $fillable = [
        'mail_account_id',
        'folder_path',
        'imap_uid',
        'subject',
        'from_address',
        'received_at',
        'is_seen',
        'is_flagged',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'imap_uid' => 'integer',
            'received_at' => 'datetime',
            'is_seen' => 'boolean',
            'is_flagged' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }
}
