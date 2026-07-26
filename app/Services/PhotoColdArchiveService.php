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
        $mode = $this->mediaConfig->capacityMode();

        if ($mode === MediaStorageConfigService::CAPACITY_MODE_R2_CAP) {
            return $this->archiveToEnforceR2Cap($limit);
        }

        if ($mode === MediaStorageConfigService::CAPACITY_MODE_OVERFLOW) {
            $days = $this->mediaConfig->archiveAfterDays();
            if ($days <= 0) {
                return $stats;
            }

            return $this->archiveByAge($days, $limit);
        }

        // age_archive（現行）
        return $this->archiveByAge($this->mediaConfig->archiveAfterDays(), $limit);
    }

    /**
     * アップロード前に R2（ホット）を無料枠内へ収める。
     *
     * @return int アーカイブした件数
     */
    public function ensureHotWithinQuota(int $userId, int $extraBytes = 0, int $limit = 80): int
    {
        if (! $this->mediaConfig->pipelineArchivesToBackblaze() || ! $this->mediaConfig->backblazeEnabled()) {
            return 0;
        }
        if ($this->mediaConfig->capacityMode() !== MediaStorageConfigService::CAPACITY_MODE_R2_CAP) {
            return 0;
        }

        $this->mediaConfig->applyRuntimeDisks();
        $quota = max(1, (int) config('photos.user_quota_bytes', 10 * 1024 * 1024 * 1024));
        $hotDisk = (string) config('photos.disk', 'public');
        $coldDisk = 'backblaze';
        $archived = 0;

        while ($archived < $limit) {
            $hotUsed = $this->hotUsedBytes($userId);
            if ($hotUsed + max(0, $extraBytes) <= $quota) {
                break;
            }

            $photo = Photo::query()
                ->where('user_id', $userId)
                ->where(function ($q) {
                    $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
                })
                ->orderByRaw('COALESCE(taken_at, created_at) ASC')
                ->orderBy('id')
                ->first();

            if (! $photo) {
                break;
            }

            try {
                if ($this->archiveOne($photo, $hotDisk, $coldDisk)) {
                    $archived++;
                } else {
                    break;
                }
            } catch (\Throwable $e) {
                report($e);
                Log::warning('photo cold archive (r2 cap) failed', [
                    'photo_id' => $photo->id,
                    'message' => $e->getMessage(),
                ]);
                break;
            }
        }

        return $archived;
    }

    /**
     * @return array{archived: int, skipped: int, errors: int}
     */
    private function archiveToEnforceR2Cap(int $limit): array
    {
        $stats = ['archived' => 0, 'skipped' => 0, 'errors' => 0];
        $quota = max(1, (int) config('photos.user_quota_bytes', 10 * 1024 * 1024 * 1024));
        $hotDisk = (string) config('photos.disk', 'public');
        $coldDisk = 'backblaze';

        $userIds = Photo::query()
            ->where(function ($q) {
                $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
            })
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            if ($stats['archived'] >= $limit) {
                break;
            }
            $remaining = $limit - $stats['archived'];
            while ($remaining > 0) {
                $hotUsed = $this->hotUsedBytes((int) $userId);
                if ($hotUsed <= $quota) {
                    break;
                }
                $photo = Photo::query()
                    ->where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
                    })
                    ->orderByRaw('COALESCE(taken_at, created_at) ASC')
                    ->orderBy('id')
                    ->first();
                if (! $photo) {
                    $stats['skipped']++;
                    break;
                }
                try {
                    if ($this->archiveOne($photo, $hotDisk, $coldDisk)) {
                        $stats['archived']++;
                        $remaining--;
                    } else {
                        $stats['skipped']++;
                        break;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    report($e);
                    break;
                }
            }
        }

        return $stats;
    }

    /**
     * @return array{archived: int, skipped: int, errors: int}
     */
    private function archiveByAge(int $days, int $limit): array
    {
        $stats = ['archived' => 0, 'skipped' => 0, 'errors' => 0];
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

    private function hotUsedBytes(int $userId): int
    {
        return (int) Photo::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
            })
            ->sum('size_bytes');
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
