<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranslationHistory extends Model
{
    protected $fillable = [
        'user_id',
        'mode',
        'source_lang',
        'target_lang',
        'source_text',
        'translated_text',
        'title',
        'source_url',
        'is_saved',
    ];

    protected function casts(): array
    {
        return [
            'is_saved' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
