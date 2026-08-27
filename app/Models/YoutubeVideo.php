<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YoutubeVideo extends Model
{
    public const PROVIDER_YOUTUBE = 'youtube';

    public const PROVIDER_TIKTOK = 'tiktok';

    protected $fillable = [
        'user_id',
        'video_library_id',
        'provider',
        'youtube_id',
        'title',
        'url',
        'thumbnail_url',
        'sort_order',
    ];

    protected $attributes = [
        'provider' => self::PROVIDER_YOUTUBE,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'video_library_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(VideoLibrary::class, 'video_library_id');
    }
}
