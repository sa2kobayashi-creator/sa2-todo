<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceAccount extends Model
{
    protected $fillable = [
        'slug',
        'region',
        'kind',
        'name',
        'currency',
        'sort_order',
        'linked_bank_id',
        'initial_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function linkedBank(): BelongsTo
    {
        return $this->belongsTo(self::class, 'linked_bank_id');
    }

    public function outgoingTransactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'account_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'to_account_id');
    }
}
