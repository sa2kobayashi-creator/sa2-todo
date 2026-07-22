<?php

namespace App\Console\Commands;

use App\Jobs\SyncPhotoToCloudinary;
use App\Models\Photo;
use App\Services\CloudinaryMediaService;
use App\Services\MediaStorageConfigService;
use Illuminate\Console\Command;

class SyncPhotosToCloudinary extends Command
{
    protected $signature = 'photos:sync-cloudinary
        {--limit=50 : Max photos to sync}
        {--sync : Run inline instead of queue}
        {--id= : Sync a single photo ID}';

    protected $description = 'Backfill existing photos/videos to Cloudinary for CDN display';

    public function handle(CloudinaryMediaService $cloudinary, MediaStorageConfigService $mediaConfig): int
    {
        if (! $mediaConfig->pipelineUsesCloudinaryDisplay() || ! $cloudinary->isReady()) {
            $this->error('Cloudinary display pipeline is not enabled/ready.');

            return self::FAILURE;
        }

        $query = Photo::query()
            ->where(function ($q) {
                $q->whereNull('cloudinary_public_id')->orWhere('cloudinary_public_id', '');
            })
            ->orderBy('id');

        if ($this->option('id')) {
            $query->where('id', (int) $this->option('id'));
        }

        $limit = max(1, (int) $this->option('limit'));
        $photos = $query->limit($limit)->get();

        if ($photos->isEmpty()) {
            $this->info('Nothing to sync.');

            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;
        $inline = (bool) $this->option('sync');

        foreach ($photos as $photo) {
            try {
                if ($inline) {
                    if ($cloudinary->syncPhoto($photo)) {
                        $ok++;
                        $this->line("synced #{$photo->id}");
                    } else {
                        $fail++;
                        $this->warn("failed #{$photo->id}");
                    }
                } else {
                    SyncPhotoToCloudinary::dispatch($photo->id);
                    $ok++;
                    $this->line("queued #{$photo->id}");
                }
            } catch (\Throwable $e) {
                $fail++;
                $this->warn("error #{$photo->id}: ".$e->getMessage());
                report($e);
            }
        }

        $this->info("done ok={$ok} fail={$fail} mode=".($inline ? 'sync' : 'queue'));

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
