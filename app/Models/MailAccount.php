<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailAccount extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'email',
        'provider',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'username',
        'password',
        'is_sa2_plus_mailbox',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
            'last_synced_at',
            'last_sync_status',
            'last_sync_message',
    ];

    protected function casts(): array
    {
        return [
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'password' => 'encrypted',
            'is_sa2_plus_mailbox' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'email' => $this->email,
            'provider' => $this->provider,
            'imapHost' => $this->imap_host,
            'imapPort' => $this->imap_port,
            'imapEncryption' => $this->imap_encryption,
            'smtpHost' => $this->smtp_host,
            'smtpPort' => $this->smtp_port,
            'smtpEncryption' => $this->smtp_encryption,
            'username' => $this->username,
            'isSa2PlusMailbox' => $this->is_sa2_plus_mailbox,
            'lastTestedAt' => $this->last_tested_at?->toIso8601String(),
            'lastTestStatus' => $this->last_test_status,
            'lastTestMessage' => $this->last_test_message,
            'lastSyncedAt' => $this->last_synced_at?->toIso8601String(),
            'lastSyncStatus' => $this->last_sync_status,
            'lastSyncMessage' => $this->last_sync_message,
        ];
    }
}
