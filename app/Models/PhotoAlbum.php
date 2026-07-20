<?php

namespace App\Models;

use App\Enums\AlbumVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhotoAlbum extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'visibility',
        'group_id',
        'cover_photo_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'cover_photo_id' => 'integer',
            'visibility' => AlbumVisibility::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'album_id');
    }

    public function coverPhoto(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'cover_photo_id');
    }

    public function visibilityEnum(): AlbumVisibility
    {
        return $this->visibility instanceof AlbumVisibility
            ? $this->visibility
            : AlbumVisibility::tryFrom((string) $this->visibility) ?? AlbumVisibility::Private;
    }
}
