<?php

namespace App\Models;

use App\Enums\RegistrationApplicationPlan;
use App\Enums\RegistrationApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationApplication extends Model
{
    protected $fillable = [
        'plan',
        'email',
        'display_name',
        'organization_name',
        'phone',
        'message',
        'status',
        'approval_token_hash',
        'approval_token_expires_at',
        'user_id',
        'reviewed_by',
        'reviewed_at',
        'admin_note',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'plan' => RegistrationApplicationPlan::class,
            'status' => RegistrationApplicationStatus::class,
            'approval_token_expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    public function isPending(): bool
    {
        return $this->status === RegistrationApplicationStatus::Pending;
    }

    public function isAwaitingActivation(): bool
    {
        return $this->status === RegistrationApplicationStatus::Approved
            && $this->approval_token_hash
            && $this->approval_token_expires_at
            && $this->approval_token_expires_at->isFuture();
    }
}
