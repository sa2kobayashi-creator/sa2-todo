<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailLabelRule extends Model
{
    protected $fillable = [
        'mail_label_id',
        'match_field',
        'match_operator',
        'match_value',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(MailLabel::class, 'mail_label_id');
    }

    /** @return array<string, mixed> */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'matchField' => $this->match_field,
            'matchOperator' => $this->match_operator,
            'matchValue' => $this->match_value,
            'priority' => $this->priority,
            'isActive' => $this->is_active,
        ];
    }
}
