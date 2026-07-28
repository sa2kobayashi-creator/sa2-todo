<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMenuFeature extends Model
{
    protected $fillable = [
        'group_id',
        'feature',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
