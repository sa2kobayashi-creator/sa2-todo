<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Photo extends Model
{
    protected $fillable = [
        'user_id',
        'album_id',
        'parent_photo_id',
        'path',
        'thumb_path',
        'original_name',
        'mime',
        'size_bytes',
        'content_hash',
        'width',
        'height',
        'caption',
        'edit_label',
        'taken_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'taken_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(PhotoAlbum::class, 'album_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_photo_id');
    }

    public function edits(): HasMany
    {
        return $this->hasMany(self::class, 'parent_photo_id');
    }
}
