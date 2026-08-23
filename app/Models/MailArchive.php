<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailArchive extends Model
{
    protected $fillable = [
        'user_id',
        'mail_account_id',
        'folder_path',
        'imap_uid',
        'message_id',
        'subject',
        'from_address',
        'to_address',
        'sent_at',
        'size_bytes',
        'has_attachments',
        'storage_provider',
        'storage_key',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'archived_at' => 'datetime',
            'has_attachments' => 'boolean',
            'size_bytes' => 'integer',
            'imap_uid' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }
}
