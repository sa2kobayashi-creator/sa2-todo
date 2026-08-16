<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailDomainRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PROVISIONED = 'provisioned';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'user_id',
        'local_part',
        'domain',
        'status',
        'user_note',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
        'provisioned_by',
        'provisioned_at',
        'provisioning_mode',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'provisioned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function provisioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provisioned_by');
    }

    public function emailAddress(): string
    {
        return strtolower($this->local_part).'@'.$this->domain;
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'localPart' => $this->local_part,
            'domain' => $this->domain,
            'email' => $this->emailAddress(),
            'status' => $this->status,
            'userNote' => $this->user_note,
            'adminNote' => $this->admin_note,
            'provisioningMode' => $this->provisioning_mode,
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'provisionedAt' => $this->provisioned_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'user' => $this->user ? [
                'id' => $this->user->id,
                'email' => $this->user->email,
                'displayName' => $this->user->display_name,
            ] : null,
        ];
    }
}
