<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\CloudinaryMediaService;
use App\Services\MediaStorageConfigService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncPhotoToCloudinary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 660;

    public function __construct(public int $photoId) {}

    public function handle(CloudinaryMediaService $cloudinary, MediaStorageConfigService $mediaConfig): void
    {
        if (! $mediaConfig->pipelineUsesCloudinaryDisplay() || ! $cloudinary->isReady()) {
            return;
        }

        $photo = Photo::query()->find($this->photoId);
        if (! $photo) {
            return;
        }

        $cloudinary->syncPhoto($photo);
    }
}
