<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PhotoColdArchiveService
{
    public function __construct(private MediaStorageConfigService $mediaConfig) {}

    /**
     * 古いホット写真を Backblaze へ移す。
     *
     * @return array{archived: int, skipped: int, errors: int}
     */
    public function archiveDuePhotos(int $limit = 40): array
    {
        $stats = ['archived' => 0, 'skipped' => 0, 'errors' => 0];

        if (! $this->mediaConfig->pipelineArchivesToBackblaze() || ! $this->mediaConfig->backblazeEnabled()) {
            return $stats;
        }

        $this->mediaConfig->applyRuntimeDisks();

        $days = $this->mediaConfig->archiveAfterDays();
        if ($days < 0) {
            return $stats;
        }

        $cutoff = now()->subDays($days);
        $hotDisk = (string) config('photos.disk', 'public');
        $coldDisk = 'backblaze';

        $photos = Photo::query()
            ->where(function ($q) {
                $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
            })
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNotNull('taken_at')->where('taken_at', '<=', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('taken_at')->where('created_at', '<=', $cutoff);
                });
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($photos as $photo) {
            try {
                if ($this->archiveOne($photo, $hotDisk, $coldDisk)) {
                    $stats['archived']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                report($e);
                Log::warning('photo cold archive failed', [
                    'photo_id' => $photo->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    private function archiveOne(Photo $photo, string $hotDisk, string $coldDisk): bool
    {
        $path = (string) $photo->path;
        if ($path === '') {
            return false;
        }

        $hot = Storage::disk($hotDisk);
        $cold = Storage::disk($coldDisk);

        if (! $hot->exists($path)) {
            // 既にホットに無い場合はコールド扱いへ寄せる
            if ($cold->exists($path)) {
                $photo->storage_tier = 'cold';
                $photo->cold_disk = $coldDisk;
                $photo->cold_path = $path;
                $photo->save();

                return true;
            }

            return false;
        }

        $stream = $hot->readStream($path);
        if ($stream === false || $stream === null) {
            throw new \RuntimeException('Failed to read hot object: '.$path);
        }

        try {
            $cold->writeStream($path, $stream, [
                'visibility' => 'private',
                'ContentType' => (string) ($photo->mime ?: 'application/octet-stream'),
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $cold->exists($path)) {
            throw new \RuntimeException('Cold object missing after write: '.$path);
        }

        // サムネは一覧用にホット側へ残す（無ければコピーしない）
        $hot->delete($path);

        $photo->storage_tier = 'cold';
        $photo->cold_disk = $coldDisk;
        $photo->cold_path = $path;
        $photo->save();

        return true;
    }
}
