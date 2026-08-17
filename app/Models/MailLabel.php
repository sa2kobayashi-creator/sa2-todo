<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailLabel extends Model
{
    protected $fillable = [
        'mail_account_id',
        'name',
        'folder_path',
        'color',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(MailLabelRule::class);
    }

    /** @return array<string, mixed> */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'folderPath' => $this->folder_path,
            'color' => $this->color,
            'rules' => $this->relationLoaded('rules')
                ? $this->rules->map(fn (MailLabelRule $r) => $r->toClientArray())->values()->all()
                : [],
        ];
    }
}
