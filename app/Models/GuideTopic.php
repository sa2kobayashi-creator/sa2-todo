<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuideTopic extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'icon',
        'instruction',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 組み込み話題の id と衝突しない形にする */
    public function topicId(): string
    {
        return 'u'.$this->getKey();
    }
}
