<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PhotoColdArchiveService
{
    /** 1件も移せなかったときに、その理由を呼び出し側へ伝えるためのキー */
    public const REASON_DISABLED = 'disabled';

    public const REASON_WITHIN_QUOTA = 'within_quota';

    public const REASON_NO_DUE_PHOTOS = 'no_due_photos';

    public const REASON_ALL_BLOCKED = 'all_blocked';

    public const REASON_TIME_BUDGET = 'time_budget';

    public const REASON_LARGE_FILE = 'large_file';

    /** B2 障害時に全件ぶんリトライして待たせないための連続失敗上限 */
    private const MAX_CONSECUTIVE_ERRORS = 3;

    public function __construct(private MediaStorageConfigService $mediaConfig) {}

    /**
     * 古いホット写真を Backblaze へ移す。
     *
     * @return array{
     *   archived: int,
     *   skipped: int,
     *   errors: int,
     *   hasMore: bool,
     *   reason: string,
     *   lastError: string,
     *   lastErrorPhotoId: ?int,
     *   bytesMoved: int
     * }
     */
    public function archiveDuePhotos(int $limit = 40): array
    {
        if (! $this->mediaConfig->pipelineArchivesToBackblaze() || ! $this->mediaConfig->backblazeEnabled()) {
            return $this->stats(reason: self::REASON_DISABLED);
        }

        $this->mediaConfig->applyRuntimeDisks();
        $mode = $this->mediaConfig->capacityMode();

        if ($mode === MediaStorageConfigService::CAPACITY_MODE_R2_CAP) {
            return $this->archiveToEnforceR2Cap($limit);
        }

        if ($mode === MediaStorageConfigService::CAPACITY_MODE_OVERFLOW) {
            $days = $this->mediaConfig->archiveAfterDays();
            if ($days <= 0) {
                return $this->stats(reason: self::REASON_NO_DUE_PHOTOS);
            }

            return $this->archiveByAge($days, $limit);
        }

        // age_archive（現行）
        return $this->archiveByAge($this->mediaConfig->archiveAfterDays(), $limit);
    }

    /**
     * @return array{
     *   archived: int,
     *   skipped: int,
     *   errors: int,
     *   hasMore: bool,
     *   reason: string,
     *   lastError: string,
     *   lastErrorPhotoId: ?int,
     *   bytesMoved: int
     * }
     */
    private function stats(
        int $archived = 0,
        int $skipped = 0,
        int $errors = 0,
        bool $hasMore = false,
        string $reason = '',
        string $lastError = '',
        ?int $lastErrorPhotoId = null,
        int $bytesMoved = 0,
    ): array {
        return [
            'archived' => $archived,
            'skipped' => $skipped,
            'errors' => $errors,
            'hasMore' => $hasMore,
            'reason' => $reason,
            'lastError' => $lastError,
            'lastErrorPhotoId' => $lastErrorPhotoId,
            'bytesMoved' => $bytesMoved,
        ];
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
        $hotUsed = $this->hotUsedBytes($userId);
        $need = max(0, $extraBytes);
        // 移せなかった1件で打ち切らないよう、同じレコードは再試行しない
        $blockedIds = [];
        $consecutiveErrors = 0;
        $deadline = $this->batchDeadline();
        $largeBytes = $this->largeFileBytes();

        while ($archived < $limit && ($hotUsed + $need) > $quota) {
            if ($this->pastDeadline($deadline)) {
                break;
            }

            $photo = Photo::query()
                ->where('user_id', $userId)
                ->where(function ($q) {
                    $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
                })
                ->when($blockedIds !== [], fn ($q) => $q->whereNotIn('id', $blockedIds))
                ->orderByRaw('COALESCE(taken_at, created_at) ASC')
                ->orderBy('id')
                ->first();

            if (! $photo) {
                break;
            }

            $freed = max(0, (int) ($photo->size_bytes ?? 0));
            // 大きな動画は1リクエスト1本。既に進捗があるなら次のリクエストへ回す
            if ($archived > 0 && $freed >= $largeBytes) {
                break;
            }

            try {
                if ($this->archiveOne($photo, $hotDisk, $coldDisk)) {
                    $archived++;
                    $consecutiveErrors = 0;
                    // サムネは R2 に残るため、原本サイズ分だけ減る
                    $hotUsed = max(0, $hotUsed - $freed);
                    if ($freed >= $largeBytes) {
                        break;
                    }
                } else {
                    $blockedIds[] = (int) $photo->id;
                }
            } catch (\Throwable $e) {
                $blockedIds[] = (int) $photo->id;
                $consecutiveErrors++;
                report($e);
                Log::warning('photo cold archive (r2 cap) failed', [
                    'photo_id' => $photo->id,
                    'message' => $e->getMessage(),
                ]);
                // B2 側が落ちている等、全件失敗しそうなときはアップロードを待たせない
                if ($consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                    break;
                }
            }
        }

        return $archived;
    }

    /**
     * @return array{
     *   archived: int,
     *   skipped: int,
     *   errors: int,
     *   hasMore: bool,
     *   reason: string,
     *   lastError: string,
     *   lastErrorPhotoId: ?int,
     *   bytesMoved: int
     * }
     */
    private function archiveToEnforceR2Cap(int $limit): array
    {
        $archived = 0;
        $skipped = 0;
        $errors = 0;
        $bytesMoved = 0;
        $overQuotaSeen = false;
        $lastError = '';
        $lastErrorPhotoId = null;
        $stopReason = '';
        $quota = max(1, (int) config('photos.user_quota_bytes', 10 * 1024 * 1024 * 1024));
        $hotDisk = (string) config('photos.disk', 'public');
        $coldDisk = 'backblaze';
        $deadline = $this->batchDeadline();
        $largeBytes = $this->largeFileBytes();

        $userIds = Photo::query()
            ->where(function ($q) {
                $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
            })
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            // 移せなかった1件で全体が止まらないよう、同じレコードはこのリクエスト内で再試行しない
            $blockedIds = [];
            $consecutiveErrors = 0;

            while (true) {
                if ($this->hotUsedBytes((int) $userId) <= $quota) {
                    break;
                }
                $overQuotaSeen = true;

                if ($archived >= $limit) {
                    // 打ち切り。まだ超過しているので続きがある
                    return $this->stats($archived, $skipped, $errors, true, '', $lastError, $lastErrorPhotoId, $bytesMoved);
                }

                if ($this->pastDeadline($deadline)) {
                    $stopReason = self::REASON_TIME_BUDGET;
                    // 1件も移せていない時間切れは「続きあり」扱いにすると進捗ゼロのまま回り続ける
                    $continue = $archived > 0;

                    return $this->stats($archived, $skipped, $errors, $continue, $stopReason, $lastError, $lastErrorPhotoId, $bytesMoved);
                }

                $photo = Photo::query()
                    ->where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
                    })
                    ->when($blockedIds !== [], fn ($q) => $q->whereNotIn('id', $blockedIds))
                    ->orderByRaw('COALESCE(taken_at, created_at) ASC')
                    ->orderBy('id')
                    ->first();

                if (! $photo) {
                    // 超過しているが、これ以上動かせる原本が無い
                    break;
                }

                $size = max(0, (int) ($photo->size_bytes ?? 0));
                if ($archived > 0 && $size >= $largeBytes) {
                    // 大きな動画は単独リクエストで扱う（プロキシ切断対策）
                    return $this->stats(
                        $archived,
                        $skipped,
                        $errors,
                        true,
                        self::REASON_LARGE_FILE,
                        $lastError,
                        $lastErrorPhotoId,
                        $bytesMoved
                    );
                }

                try {
                    if ($this->archiveOne($photo, $hotDisk, $coldDisk)) {
                        $archived++;
                        $bytesMoved += $size;
                        $consecutiveErrors = 0;
                        // 大きな動画を1本移したら一旦返す（まだ超過なら続きあり）
                        if ($size >= $largeBytes) {
                            $stillOver = $this->hotUsedBytes((int) $userId) > $quota;

                            return $this->stats(
                                $archived,
                                $skipped,
                                $errors,
                                $stillOver,
                                $stillOver ? self::REASON_LARGE_FILE : '',
                                $lastError,
                                $lastErrorPhotoId,
                                $bytesMoved
                            );
                        }
                    } else {
                        $skipped++;
                        $blockedIds[] = (int) $photo->id;
                        Log::warning('photo cold archive skipped (source missing)', [
                            'photo_id' => $photo->id,
                            'path' => $photo->path,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $blockedIds[] = (int) $photo->id;
                    $consecutiveErrors++;
                    $lastError = $this->summarizeException($e);
                    $lastErrorPhotoId = (int) $photo->id;
                    report($e);
                    Log::warning('photo cold archive (r2 cap) failed', [
                        'photo_id' => $photo->id,
                        'size_bytes' => $size,
                        'message' => $e->getMessage(),
                    ]);
                    // B2 側の障害・認証エラーなら全件失敗するので早めに諦める
                    if ($consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                        return $this->stats(
                            $archived,
                            $skipped,
                            $errors,
                            // 進捗ゼロの連続失敗で hasMore を立てるとクライアントが無限リトライする
                            $archived > 0,
                            $archived > 0 ? '' : self::REASON_ALL_BLOCKED,
                            $lastError,
                            $lastErrorPhotoId,
                            $bytesMoved
                        );
                    }
                }
            }
        }

        if ($archived === 0) {
            return $this->stats(
                $archived,
                $skipped,
                $errors,
                false,
                $overQuotaSeen ? self::REASON_ALL_BLOCKED : self::REASON_WITHIN_QUOTA,
                $lastError,
                $lastErrorPhotoId,
                $bytesMoved
            );
        }

        return $this->stats($archived, $skipped, $errors, false, '', $lastError, $lastErrorPhotoId, $bytesMoved);
    }

    /**
     * @return array{
     *   archived: int,
     *   skipped: int,
     *   errors: int,
     *   hasMore: bool,
     *   reason: string,
     *   lastError: string,
     *   lastErrorPhotoId: ?int,
     *   bytesMoved: int
     * }
     */
    private function archiveByAge(int $days, int $limit): array
    {
        $archived = 0;
        $skipped = 0;
        $errors = 0;
        $bytesMoved = 0;
        $lastError = '';
        $lastErrorPhotoId = null;
        if ($days < 0) {
            return $this->stats(reason: self::REASON_NO_DUE_PHOTOS);
        }

        $cutoff = now()->subDays($days);
        $hotDisk = (string) config('photos.disk', 'public');
        $coldDisk = 'backblaze';
        $deadline = $this->batchDeadline();
        $largeBytes = $this->largeFileBytes();
        $blockedIds = [];
        $processed = 0;

        while ($processed < max(1, $limit)) {
            if ($this->pastDeadline($deadline)) {
                return $this->stats(
                    $archived,
                    $skipped,
                    $errors,
                    $archived > 0,
                    $archived > 0 ? self::REASON_TIME_BUDGET : self::REASON_ALL_BLOCKED,
                    $lastError,
                    $lastErrorPhotoId,
                    $bytesMoved
                );
            }

            $photo = Photo::query()
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
                ->when($blockedIds !== [], fn ($q) => $q->whereNotIn('id', $blockedIds))
                ->orderBy('id')
                ->first();

            if (! $photo) {
                if ($processed === 0) {
                    return $this->stats(reason: self::REASON_NO_DUE_PHOTOS);
                }
                break;
            }

            $size = max(0, (int) ($photo->size_bytes ?? 0));
            if ($archived > 0 && $size >= $largeBytes) {
                return $this->stats(
                    $archived,
                    $skipped,
                    $errors,
                    true,
                    self::REASON_LARGE_FILE,
                    $lastError,
                    $lastErrorPhotoId,
                    $bytesMoved
                );
            }

            $processed++;

            try {
                if ($this->archiveOne($photo, $hotDisk, $coldDisk)) {
                    $archived++;
                    $bytesMoved += $size;
                    if ($size >= $largeBytes) {
                        // 大きな動画を1本移したら一旦返す。まだ期限対象が残っていれば続きあり
                        $moreDue = Photo::query()
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
                            ->exists();

                        return $this->stats(
                            $archived,
                            $skipped,
                            $errors,
                            $moreDue,
                            $moreDue ? self::REASON_LARGE_FILE : '',
                            $lastError,
                            $lastErrorPhotoId,
                            $bytesMoved
                        );
                    }
                } else {
                    $skipped++;
                    $blockedIds[] = (int) $photo->id;
                    Log::warning('photo cold archive skipped (source missing)', [
                        'photo_id' => $photo->id,
                        'path' => $photo->path,
                    ]);
                }
            } catch (\Throwable $e) {
                $errors++;
                $blockedIds[] = (int) $photo->id;
                $lastError = $this->summarizeException($e);
                $lastErrorPhotoId = (int) $photo->id;
                report($e);
                Log::warning('photo cold archive failed', [
                    'photo_id' => $photo->id,
                    'size_bytes' => $size,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // 移せなかった写真は次のバッチでも同じ結果になるので、進捗があるときだけ続ける
        $hasMore = $archived > 0 && $processed >= max(1, $limit);

        return $this->stats(
            $archived,
            $skipped,
            $errors,
            $hasMore,
            $archived === 0 ? self::REASON_ALL_BLOCKED : '',
            $lastError,
            $lastErrorPhotoId,
            $bytesMoved
        );
    }

    /**
     * R2（ホット側）使用量。使用状況表示と同じく「ホット原本 + サムネ概算」。
     * サムネはコールド移行後も一覧用に R2 に残るため、全件分を加算する。
     */
    private function hotUsedBytes(int $userId): int
    {
        $hotOriginals = (int) Photo::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
            })
            ->sum('size_bytes');

        // PhotoService::storageStats の thumbExtra と同じ概算（1枚あたり約 80KB）
        $thumbExtra = (int) Photo::query()
            ->where('user_id', $userId)
            ->whereNotNull('thumb_path')
            ->count() * 80_000;

        return $hotOriginals + $thumbExtra;
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
            $ok = $cold->writeStream($path, $stream, [
                'visibility' => 'private',
                'ContentType' => (string) ($photo->mime ?: 'application/octet-stream'),
            ]);
            if ($ok === false) {
                throw new \RuntimeException('Cold writeStream returned false: '.$path);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $cold->exists($path)) {
            throw new \RuntimeException('Cold object missing after write: '.$path);
        }

        if ($coldDisk === 'backblaze') {
            $this->mediaConfig->recordB2Usage(0, 0, 1);
        }

        // サムネは一覧用にホット側へ残す（無ければコピーしない）
        $hot->delete($path);

        $photo->storage_tier = 'cold';
        $photo->cold_disk = $coldDisk;
        $photo->cold_path = $path;
        $photo->save();

        return true;
    }

    /** 共有ホストのプロキシ切断より先にレスポンスを返すためのソフト期限 */
    private function batchDeadline(): float
    {
        $seconds = max(15, (int) config('photos.archive_cold_batch_seconds', 45));

        return microtime(true) + $seconds;
    }

    private function pastDeadline(float $deadline): bool
    {
        return microtime(true) >= $deadline;
    }

    /** このサイズ以上は1リクエスト1本にする（大きな動画向け） */
    private function largeFileBytes(): int
    {
        return max(1, (int) config('photos.archive_cold_large_file_bytes', 80 * 1024 * 1024));
    }

    private function summarizeException(\Throwable $e): string
    {
        $class = class_basename($e);
        $message = trim($e->getMessage());
        if ($message === '') {
            return $class;
        }

        return $class.': '.mb_substr($message, 0, 240);
    }
}
