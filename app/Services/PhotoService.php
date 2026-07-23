<?php

namespace App\Services;

use App\Enums\AlbumVisibility;
use App\Jobs\SyncPhotoToCloudinary;
use App\Models\Photo;
use App\Models\PhotoAlbum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PhotoService
{
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/heif',
    ];

    public const ALLOWED_VIDEO_MIMES = [
        'video/mp4',
    ];

    public function __construct(
        private GroupService $groups,
        private FfmpegService $ffmpeg,
        private CloudinaryMediaService $cloudinary,
        private MediaStorageConfigService $mediaConfig,
        private StabilityAiService $stability,
    ) {}

    public function maxUploadBytes(): int
    {
        // 0 = アプリ側の画像サイズ上限なし（PHP の upload_max_filesize は別途あり）
        return max(0, (int) config('photos.max_upload_bytes', 0));
    }

    public function maxVideoUploadBytes(): int
    {
        return max(1, (int) config('photos.max_video_upload_bytes', 800 * 1024 * 1024));
    }

    public function isVideoMime(?string $mime, ?string $extension = null): bool
    {
        $mime = strtolower((string) $mime);
        $extension = strtolower((string) $extension);
        if ($extension === 'mp4') {
            return true;
        }

        return in_array($mime, self::ALLOWED_VIDEO_MIMES, true)
            || str_starts_with($mime, 'video/mp4')
            || $mime === 'application/mp4';
    }

    public function isVideoUpload(UploadedFile $file): bool
    {
        return $this->isVideoMime($file->getMimeType(), $file->getClientOriginalExtension());
    }

    public function userQuotaBytes(): int
    {
        return max(1, (int) config('photos.user_quota_bytes', 10 * 1024 * 1024 * 1024));
    }

    public function overagePricePerGbMonthUsd(): float
    {
        return max(0, (float) config('photos.overage_price_per_gb_month_usd', 0.015));
    }

    public function b2QuotaBytes(): int
    {
        return max(1, (int) config('photos.b2_quota_bytes', 10 * 1024 * 1024 * 1024));
    }

    public function b2OveragePricePerGbMonthUsd(): float
    {
        return max(0, (float) config('photos.b2_overage_price_per_gb_month_usd', 0.006));
    }

    private function formatUsdPerGbMonth(float $price): string
    {
        $trimmed = rtrim(rtrim(number_format($price, 3, '.', ''), '0'), '.');

        return '$'.$trimmed.__('/GB/月');
    }

    private function formatUsdMonth(float $amount): string
    {
        if ($amount <= 0) {
            return '$0';
        }

        return '$'.rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.');
    }

    private function estimateOverageUsd(int $usedBytes, int $quotaBytes, float $pricePerGbMonth): float
    {
        $overBytes = max(0, $usedBytes - $quotaBytes);
        if ($overBytes <= 0) {
            return 0.0;
        }

        return round(($overBytes / (1024 * 1024 * 1024)) * $pricePerGbMonth, 4);
    }

    public function storageStats(int $userId): array
    {
        $thumbExtra = (int) Photo::query()
            ->where('user_id', $userId)
            ->whereNotNull('thumb_path')
            ->count() * 80_000;

        $hotQuery = Photo::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('storage_tier')->orWhere('storage_tier', 'hot');
            });
        $coldQuery = Photo::query()
            ->where('user_id', $userId)
            ->where('storage_tier', 'cold');

        $hotUsed = (int) (clone $hotQuery)->sum('size_bytes');
        $coldUsed = (int) (clone $coldQuery)->sum('size_bytes');
        $hotCount = (int) (clone $hotQuery)->count();
        $coldCount = (int) (clone $coldQuery)->count();
        $hotUsedApprox = $hotUsed + $thumbExtra;
        $usedApprox = $hotUsedApprox + $coldUsed;
        $photoCount = $hotCount + $coldCount;

        $quota = $this->userQuotaBytes();
        $b2Quota = $this->b2QuotaBytes();
        $disk = $this->diskName();
        $r2Price = $this->overagePricePerGbMonthUsd();
        $b2Price = $this->b2OveragePricePerGbMonthUsd();

        $pipeline = $this->mediaConfig->get(\App\Models\MediaStorageSetting::PROVIDER_PIPELINE);
        $pipelineEnabled = (bool) $pipeline->enabled;
        $archiveEnabled = $this->mediaConfig->pipelineArchivesToBackblaze();
        $cloudinaryEditor = $this->mediaConfig->cloudinaryEditorEnabled();
        $cloudinaryEnabled = $this->mediaConfig->cloudinaryEnabled();

        // パイプライン＋長期保存時は R2 10GB + B2 10GB = 20GB を合計無料枠とする
        $combinedQuota = $archiveEnabled ? ($quota + $b2Quota) : $quota;
        $barUsed = $archiveEnabled ? $usedApprox : $hotUsedApprox;
        $percent = round(($barUsed / max(1, $combinedQuota)) * 100, 1);

        $r2OverageUsd = $this->estimateOverageUsd($hotUsedApprox, $quota, $r2Price);
        $b2OverageUsd = $this->estimateOverageUsd($coldUsed, $b2Quota, $b2Price);

        $cloudinaryResidual = Photo::query()
            ->where('user_id', $userId)
            ->whereNotNull('cloudinary_public_id')
            ->where('cloudinary_public_id', '!=', '')
            ->count();
        $cloudinaryFreeCredits = max(1, (int) config('photos.cloudinary_free_credits', 25));

        $primaryLabel = match ($disk) {
            'r2' => 'Cloudflare R2',
            'public' => __('サーバーローカル'),
            default => $disk,
        };
        $diskLabel = $pipelineEnabled
            ? __('パイプライン').'（'.$primaryLabel.($archiveEnabled ? ' + Backblaze B2' : '').($cloudinaryEditor ? ' + Cloudinary'.__('編集') : '').'）'
            : $primaryLabel;

        $providers = [
            [
                'id' => 'r2',
                'name' => 'Cloudflare R2',
                'role' => __('常用原本'),
                'enabled' => $disk === 'r2' || $pipelineEnabled,
                'usedLabel' => $this->formatBytes($hotUsedApprox),
                'quotaLabel' => $this->formatBytes($quota),
                'count' => $hotCount,
                'percent' => round(($hotUsedApprox / max(1, $quota)) * 100, 1),
                'overFreeTier' => $hotUsedApprox > $quota,
                'overagePriceLabel' => $this->formatUsdPerGbMonth($r2Price),
                'estimatedBillLabel' => $this->formatUsdMonth($r2OverageUsd).__('/月'),
                'billingNote' => __('無料枠超過分のみ従量課金。転送料は無料。'),
            ],
            [
                'id' => 'backblaze',
                'name' => 'Backblaze B2',
                'role' => __('長期保存'),
                'enabled' => $archiveEnabled || $coldCount > 0,
                'usedLabel' => $this->formatBytes($coldUsed),
                'quotaLabel' => $this->formatBytes($b2Quota),
                'count' => $coldCount,
                'percent' => round(($coldUsed / max(1, $b2Quota)) * 100, 1),
                'overFreeTier' => $coldUsed > $b2Quota,
                'overagePriceLabel' => $this->formatUsdPerGbMonth($b2Price),
                'estimatedBillLabel' => $this->formatUsdMonth($b2OverageUsd).__('/月'),
                'billingNote' => __('無料枠超過分のみ従量課金（目安）。'),
            ],
            [
                'id' => 'cloudinary',
                'name' => 'Cloudinary',
                'role' => __('編集（一時のみ）'),
                'enabled' => $cloudinaryEnabled || $cloudinaryEditor,
                'usedLabel' => $cloudinaryResidual > 0
                    ? __('残存アセット :count 件', ['count' => $cloudinaryResidual])
                    : __('常設保管なし'),
                'quotaLabel' => $cloudinaryFreeCredits.__('クレジット/月'),
                'count' => $cloudinaryResidual,
                'percent' => 0,
                'overFreeTier' => false,
                'overagePriceLabel' => __('Free は超過課金なし（制限・アップグレード案内）'),
                'estimatedBillLabel' => '$0'.__('/月'),
                'billingNote' => __('1クレジット ≒ 1GB保管 または 1GB帯域 または 1,000変換。編集後は一時アセットを削除。'),
            ],
        ];

        return [
            'usedBytes' => $usedApprox,
            'quotaBytes' => $quota,
            'combinedQuotaBytes' => $combinedQuota,
            'percent' => $percent,
            'photoCount' => $photoCount,
            'remainingBytes' => max(0, $combinedQuota - $barUsed),
            'formattedUsed' => $this->formatBytes($barUsed),
            'formattedQuota' => $this->formatBytes($quota),
            'formattedCombinedQuota' => $this->formatBytes($combinedQuota),
            'formattedTotalUsed' => $this->formatBytes($usedApprox),
            'disk' => $disk,
            'diskLabel' => $diskLabel,
            'overFreeTier' => $barUsed > $combinedQuota,
            'overagePriceLabel' => $this->formatUsdPerGbMonth($r2Price),
            'hotUsedBytes' => $hotUsedApprox,
            'coldUsedBytes' => $coldUsed,
            'hotCount' => $hotCount,
            'coldCount' => $coldCount,
            'formattedHotUsed' => $this->formatBytes($hotUsedApprox),
            'formattedColdUsed' => $this->formatBytes($coldUsed),
            'formattedB2Quota' => $this->formatBytes($b2Quota),
            'b2OveragePriceLabel' => $this->formatUsdPerGbMonth($b2Price),
            'hotOverFreeTier' => $hotUsedApprox > $quota,
            'coldOverFreeTier' => $coldUsed > $b2Quota,
            'r2EstimatedBillLabel' => $this->formatUsdMonth($r2OverageUsd).__('/月'),
            'b2EstimatedBillLabel' => $this->formatUsdMonth($b2OverageUsd).__('/月'),
            'estimatedTotalBillLabel' => $this->formatUsdMonth($r2OverageUsd + $b2OverageUsd).__('/月'),
            'pipelineEnabled' => $pipelineEnabled,
            'archiveEnabled' => $archiveEnabled,
            'cloudinaryEditor' => $cloudinaryEditor,
            'primaryLabel' => $primaryLabel,
            'providers' => $providers,
        ];
    }

    public function diskName(): string
    {
        $disk = (string) config('photos.disk', 'public');

        return $disk !== '' ? $disk : 'public';
    }

    public function diskDriver(): string
    {
        return (string) config('filesystems.disks.'.$this->diskName().'.driver', 'local');
    }

    public function usesObjectStorage(): bool
    {
        return $this->diskDriver() === 's3';
    }

    /** @return list<array<string, mixed>> */
    public function listAlbums(int $userId): array
    {
        $groupIds = $this->groups->approvedGroupIdsForUser($userId);

        return PhotoAlbum::query()
            ->with(['group', 'user'])
            ->withCount('photos')
            ->where(function ($q) use ($userId, $groupIds) {
                $q->where('user_id', $userId)
                    ->orWhere('visibility', AlbumVisibility::Public->value);
                if ($groupIds !== []) {
                    $q->orWhere(function ($groupQ) use ($groupIds) {
                        $groupQ->where('visibility', AlbumVisibility::Group->value)
                            ->whereIn('group_id', $groupIds);
                    });
                }
            })
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$userId])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (PhotoAlbum $album) => $this->albumToArray($album, $userId))
            ->all();
    }

    public function canViewAlbum(int $userId, PhotoAlbum $album): bool
    {
        if ((int) $album->user_id === $userId) {
            return true;
        }
        $visibility = $album->visibilityEnum();
        if ($visibility === AlbumVisibility::Public) {
            return true;
        }
        if ($visibility === AlbumVisibility::Group && $album->group_id) {
            return $this->groups->userBelongsToApprovedGroup($userId, (int) $album->group_id);
        }

        return false;
    }

    public function canManageAlbum(int $userId, PhotoAlbum $album): bool
    {
        return (int) $album->user_id === $userId;
    }

    public function findViewableAlbum(int $userId, int $albumId): ?PhotoAlbum
    {
        $album = PhotoAlbum::query()->with(['group', 'user'])->find($albumId);
        if (! $album || ! $this->canViewAlbum($userId, $album)) {
            return null;
        }

        return $album;
    }

    /** @return list<array<string, mixed>> */
    public function listPhotos(int $userId, ?int $albumId = null): array
    {
        if ($albumId !== null) {
            $album = $this->findViewableAlbum($userId, $albumId);
            if (! $album) {
                return [];
            }

            return Photo::query()
                ->where('album_id', $albumId)
                ->orderByDesc('taken_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Photo $photo) => $this->photoToArray($photo, $userId))
                ->all();
        }

        return Photo::query()
            ->where('user_id', $userId)
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Photo $photo) => $this->photoToArray($photo, $userId))
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $photos
     * @return list<array{date: string, label: string, photos: list<array<string, mixed>>}>
     */
    public function groupPhotosByDate(array $photos): array
    {
        $tz = config('app.timezone', 'Asia/Tokyo');
        $today = now($tz)->format('Y-m-d');
        $yesterday = now($tz)->subDay()->format('Y-m-d');
        $groups = [];

        foreach ($photos as $photo) {
            $date = substr((string) ($photo['takenAt'] ?? ''), 0, 10);
            if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = substr((string) ($photo['createdAt'] ?? ''), 0, 10) ?: 'unknown';
            }
            if (! isset($groups[$date])) {
                $groups[$date] = [
                    'date' => $date,
                    'label' => $this->formatDateGroupLabel($date, $today, $yesterday),
                    'photos' => [],
                ];
            }
            $groups[$date]['photos'][] = $photo;
        }

        return array_values($groups);
    }

    public function createAlbum(
        int $userId,
        string $name,
        ?string $description = null,
        string $visibility = 'private',
        mixed $groupId = null,
    ): array {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('アルバム名を入力してください');
        }

        [$visibilityEnum, $resolvedGroupId] = $this->normalizeAlbumVisibility($userId, $visibility, $groupId);

        $max = (int) PhotoAlbum::query()->where('user_id', $userId)->max('sort_order');
        $album = PhotoAlbum::create([
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 120),
            'description' => $description !== null ? mb_substr(trim($description), 0, 500) : null,
            'visibility' => $visibilityEnum,
            'group_id' => $resolvedGroupId,
            'sort_order' => $max + 10,
        ]);

        return $this->albumToArray($album->load(['group', 'user'])->loadCount('photos'), $userId);
    }

    public function updateAlbum(
        int $userId,
        int $albumId,
        string $name,
        ?string $description = null,
        ?string $visibility = null,
        mixed $groupId = null,
    ): array {
        $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
        if (! $album) {
            throw new \InvalidArgumentException('アルバムが見つかりません');
        }

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('アルバム名を入力してください');
        }

        $album->name = mb_substr($name, 0, 120);
        $album->description = $description !== null ? mb_substr(trim($description), 0, 500) : null;
        if ($album->description === '') {
            $album->description = null;
        }
        if ($visibility !== null) {
            [$visibilityEnum, $resolvedGroupId] = $this->normalizeAlbumVisibility($userId, $visibility, $groupId);
            $album->visibility = $visibilityEnum;
            $album->group_id = $resolvedGroupId;
        }
        $album->save();

        return $this->albumToArray($album->load(['group', 'user'])->loadCount('photos'), $userId);
    }

    /** @return array{0: \App\Enums\AlbumVisibility, 1: ?int} */
    private function normalizeAlbumVisibility(int $userId, string $visibility, mixed $groupId): array
    {
        $visibilityEnum = AlbumVisibility::tryFrom($visibility) ?? AlbumVisibility::Private;
        $resolvedGroupId = null;
        if ($visibilityEnum === AlbumVisibility::Group) {
            $resolvedGroupId = (int) $groupId;
            if ($resolvedGroupId <= 0 || ! $this->groups->userBelongsToApprovedGroup($userId, $resolvedGroupId)) {
                throw new \InvalidArgumentException(__('グループのみ公開には有効なグループが必要です。'));
            }
        }

        return [$visibilityEnum, $resolvedGroupId];
    }

    public function setAlbumCover(int $userId, int $albumId, int $photoId): array
    {
        $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
        if (! $album) {
            throw new \InvalidArgumentException('アルバムが見つかりません');
        }

        $photo = Photo::query()
            ->where('user_id', $userId)
            ->where('id', $photoId)
            ->where('album_id', $albumId)
            ->first();
        if (! $photo) {
            throw new \InvalidArgumentException('このアルバム内の写真のみ表紙に設定できます');
        }

        $album->cover_photo_id = $photo->id;
        $album->save();

        return $this->albumToArray($album->loadCount('photos'), $userId);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  array<int, UploadedFile>  $videoThumbsByIndex  photos[] のインデックス => サムネ JPEG
     * @return array{created: list<array<string, mixed>>, skipped: list<array{name: string, hash: string}>}
     */
    public function uploadPhotos(
        int $userId,
        array $files,
        ?int $albumId = null,
        array $videoThumbsByIndex = [],
        bool $allowDuplicates = false
    ): array {
        if ($albumId !== null) {
            $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
            if (! $album) {
                throw new \InvalidArgumentException('アルバムが見つかりません');
            }
        }

        $created = [];
        $skipped = [];
        $existingMin = Photo::query()->where('user_id', $userId)->min('sort_order');
        $nextOrder = $existingMin === null ? 0 : ((int) $existingMin - 10);

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            if (! $file->isValid()) {
                $this->throwUploadError($file);
                continue;
            }
            $this->assertValidUpload($file);

            $path = $file->getRealPath();
            $hash = is_string($path) && $path !== '' ? $this->computeContentHashFromPath($path) : null;
            $originalName = mb_substr((string) $file->getClientOriginalName(), 0, 255);

            if ($hash && ! $allowDuplicates && $this->findOwnedByContentHash($userId, $hash)) {
                $skipped[] = ['name' => $originalName !== '' ? $originalName : 'file', 'hash' => $hash];
                continue;
            }

            $dir = 'photos/'.$userId.'/'.now()->format('Y/m');
            $videoThumb = $videoThumbsByIndex[$index] ?? null;
            $stored = $this->isVideoMime($file->getMimeType(), $file->getClientOriginalExtension())
                ? $this->storeVideo($file, $dir, $videoThumb instanceof UploadedFile ? $videoThumb : null)
                : $this->storeOptimizedImage($file, $dir);
            if ($stored === null) {
                continue;
            }

            try {
                $photo = Photo::create([
                    'user_id' => $userId,
                    'album_id' => $albumId,
                    'path' => $stored['path'],
                    'thumb_path' => $stored['thumbPath'],
                    'original_name' => $originalName,
                    'mime' => $stored['mime'],
                    'size_bytes' => $stored['sizeBytes'],
                    'content_hash' => $allowDuplicates ? null : $hash,
                    'width' => $stored['width'],
                    'height' => $stored['height'],
                    'taken_at' => now(),
                    'sort_order' => $nextOrder,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // 並行アップロード時の競合
                $this->deleteStoragePaths(array_values(array_filter([
                    $stored['path'] ?? null,
                    $stored['thumbPath'] ?? null,
                ])));
                if ($hash) {
                    $skipped[] = ['name' => $originalName !== '' ? $originalName : 'file', 'hash' => $hash];
                }
                continue;
            }

            $nextOrder -= 10;
            $this->maybeSyncCloudinary($photo);
            $created[] = $this->photoToArray($photo->fresh() ?? $photo, $userId);

            if ($albumId !== null) {
                $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
                if ($album && ! $album->cover_photo_id) {
                    $album->cover_photo_id = $photo->id;
                    $album->save();
                }
            }
        }

        if ($created === [] && $skipped === []) {
            throw new \InvalidArgumentException('アップロードできるファイルがありません');
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * クライアントと同じアルゴリズム（sa2-photo-v1）で内容フィンガープリントを計算する。
     * 4MB 以下は全文 SHA-256、それ以上は size + 先頭2MB + 末尾2MB。
     */
    public function computeContentHashFromPath(string $path): string
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException('ファイルが見つかりません');
        }

        $size = (int) filesize($path);
        $sample = 2 * 1024 * 1024;

        if ($size <= $sample * 2) {
            $hash = hash_file('sha256', $path);
            if (! is_string($hash) || $hash === '') {
                throw new \InvalidArgumentException('ハッシュの計算に失敗しました');
            }

            return $hash;
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new \InvalidArgumentException('ファイルを開けません');
        }

        try {
            $head = fread($fp, $sample);
            if ($head === false || strlen($head) === 0) {
                throw new \InvalidArgumentException('ファイルの読み込みに失敗しました');
            }
            $headHash = hash('sha256', $head);

            $tailSize = min($sample, $size);
            if (fseek($fp, -$tailSize, SEEK_END) !== 0) {
                throw new \InvalidArgumentException('ファイル末尾の読み込みに失敗しました');
            }
            $tail = fread($fp, $tailSize);
            if ($tail === false || strlen($tail) === 0) {
                throw new \InvalidArgumentException('ファイル末尾の読み込みに失敗しました');
            }
            $tailHash = hash('sha256', $tail);
        } finally {
            fclose($fp);
        }

        return hash('sha256', 'sa2-photo-v1|'.$size.'|'.$headHash.'|'.$tailHash);
    }

    /**
     * 保存済みメディアの重複グループを返す（content_hash 未設定はストレージから計算）。
     *
     * @return list<array{hash: string, count: int, photos: list<array<string, mixed>>}>
     */
    public function findDuplicateGroups(int $userId, ?int $albumId = null): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $query = Photo::query()->where('user_id', $userId);
        if ($albumId !== null) {
            $query->where('album_id', $albumId);
        }

        $photos = $query->orderByDesc('id')->get();
        /** @var array<string, list<Photo>> $byHash */
        $byHash = [];

        foreach ($photos as $photo) {
            $hash = is_string($photo->content_hash) ? strtolower($photo->content_hash) : '';
            if ($hash === '' || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                try {
                    $hash = $this->computeContentHashForStoredPath((string) $photo->path);
                    // 他に同じハッシュが無ければバックフィル（ユニーク制約を守る）
                    if (! $this->findOwnedByContentHash($userId, $hash)) {
                        $photo->content_hash = $hash;
                        $photo->save();
                    }
                } catch (\Throwable $e) {
                    report($e);
                    continue;
                }
            }
            $byHash[$hash][] = $photo;
        }

        $groups = [];
        foreach ($byHash as $hash => $items) {
            if (count($items) < 2) {
                continue;
            }
            $groups[] = [
                'hash' => $hash,
                'count' => count($items),
                'photos' => array_map(
                    fn (Photo $photo) => $this->photoToArray($photo, $userId),
                    $items
                ),
            ];
        }

        usort($groups, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return $groups;
    }

    public function computeContentHashForStoredPath(string $storagePath): string
    {
        $disk = $this->storage();
        if (! $disk->exists($storagePath)) {
            throw new \InvalidArgumentException(__('ファイルが見つかりません。'));
        }

        $size = (int) $disk->size($storagePath);
        $sample = 2 * 1024 * 1024;

        if ($this->usesObjectStorage()) {
            return $this->computeContentHashFromObjectStorage($storagePath, $size, $sample);
        }

        if (method_exists($disk, 'path')) {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            return $this->computeContentHashFromPath($disk->path($storagePath));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'phash_');
        if ($tmp === false) {
            throw new \InvalidArgumentException('一時ファイルを作成できません');
        }
        try {
            $stream = $disk->readStream($storagePath);
            if ($stream === false) {
                throw new \InvalidArgumentException('ファイルを読み込めません');
            }
            $out = fopen($tmp, 'wb');
            if ($out === false) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw new \InvalidArgumentException('一時ファイルを開けません');
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $this->computeContentHashFromPath($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function computeContentHashFromObjectStorage(string $path, int $size, int $sample): string
    {
        $disk = Storage::disk($this->diskName());
        $client = $disk->getClient();
        $bucket = (string) config('filesystems.disks.'.$this->diskName().'.bucket', '');
        if ($bucket === '' || ! is_object($client)) {
            throw new \InvalidArgumentException('ストレージ設定が不正です');
        }

        $prefix = (string) config('filesystems.disks.'.$this->diskName().'.root', '');
        $key = ($prefix !== '' ? rtrim($prefix, '/').'/' : '').ltrim($path, '/');

        if ($size <= $sample * 2) {
            $result = $client->getObject(['Bucket' => $bucket, 'Key' => $key]);
            $body = (string) $result['Body'];

            return hash('sha256', $body);
        }

        $head = (string) $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Range' => 'bytes=0-'.($sample - 1),
        ])['Body'];
        $tailStart = max(0, $size - $sample);
        $tail = (string) $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Range' => 'bytes='.$tailStart.'-'.($size - 1),
        ])['Body'];

        return hash('sha256', 'sa2-photo-v1|'.$size.'|'.hash('sha256', $head).'|'.hash('sha256', $tail));
    }

    public function updateOriginalName(int $userId, int $photoId, string $originalName): array
    {
        $photo = $this->findOwnedPhoto($userId, $photoId);
        if (! $photo) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }

        $name = trim($originalName);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ファイル名を入力してください。'));
        }
        $photo->original_name = mb_substr($name, 0, 255);
        $photo->save();

        return $this->photoToArray($photo->fresh(), $userId);
    }

    public function findOwnedByContentHash(int $userId, string $hash): ?Photo
    {
        $hash = strtolower(trim($hash));
        if ($hash === '' || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return null;
        }

        return Photo::query()
            ->where('user_id', $userId)
            ->where('content_hash', $hash)
            ->first();
    }

    /**
     * @param  list<string>  $hashes
     * @return list<string> 既存のハッシュ
     */
    public function findExistingContentHashes(int $userId, array $hashes): array
    {
        $normalized = [];
        foreach ($hashes as $hash) {
            if (! is_string($hash)) {
                continue;
            }
            $h = strtolower(trim($hash));
            if (preg_match('/^[a-f0-9]{64}$/', $h)) {
                $normalized[$h] = true;
            }
        }
        $list = array_keys($normalized);
        if ($list === []) {
            return [];
        }

        return Photo::query()
            ->where('user_id', $userId)
            ->whereIn('content_hash', $list)
            ->pluck('content_hash')
            ->map(static fn ($h) => strtolower((string) $h))
            ->unique()
            ->values()
            ->all();
    }

    public function receiveUploadChunk(
        int $userId,
        string $uploadId,
        int $chunkIndex,
        int $chunkTotal,
        UploadedFile $chunk
    ): void {
        $uploadId = $this->assertChunkUploadId($uploadId);
        if ($chunkIndex < 0 || $chunkTotal < 1 || $chunkIndex >= $chunkTotal) {
            throw new \InvalidArgumentException('チャンク情報が正しくありません');
        }
        if ($chunkTotal > 256) {
            throw new \InvalidArgumentException('ファイルが大きすぎます');
        }
        if (! $chunk->isValid()) {
            $this->throwUploadError($chunk);
        }
        // 4MBチャンク想定。multipart 余白込みで 12MB まで許容
        if ($chunk->getSize() > 12 * 1024 * 1024) {
            throw new \InvalidArgumentException('チャンクサイズが大きすぎます');
        }

        $dir = $this->chunkDir($userId, $uploadId);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \InvalidArgumentException('一時保存領域を作成できません');
        }

        $metaPath = $dir.DIRECTORY_SEPARATOR.'meta.json';
        if (is_file($metaPath)) {
            $meta = json_decode((string) file_get_contents($metaPath), true);
            if (is_array($meta) && (int) ($meta['total'] ?? 0) !== $chunkTotal) {
                throw new \InvalidArgumentException('チャンク総数が一致しません');
            }
        } else {
            file_put_contents($metaPath, json_encode([
                'total' => $chunkTotal,
                'created_at' => time(),
            ]));
        }

        $target = $dir.DIRECTORY_SEPARATOR.sprintf('part_%05d', $chunkIndex);
        if (! @move_uploaded_file($chunk->getRealPath(), $target) && ! @rename($chunk->getRealPath(), $target)) {
            $contents = @file_get_contents($chunk->getRealPath());
            if ($contents === false || file_put_contents($target, $contents) === false) {
                throw new \InvalidArgumentException('チャンクの保存に失敗しました');
            }
        }
    }

    /**
     * @return array{created: ?array<string, mixed>, skipped: bool, skippedName: ?string}
     */
    public function finalizeChunkedUpload(
        int $userId,
        string $uploadId,
        string $originalName,
        ?int $albumId = null,
        ?UploadedFile $videoThumb = null,
        ?string $mimeHint = null,
        bool $allowDuplicates = false
    ): array {
        $uploadId = $this->assertChunkUploadId($uploadId);
        $dir = $this->chunkDir($userId, $uploadId);
        $metaPath = $dir.DIRECTORY_SEPARATOR.'meta.json';
        if (! is_file($metaPath)) {
            throw new \InvalidArgumentException('アップロードセッションが見つかりません');
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);
        $total = (int) ($meta['total'] ?? 0);
        if ($total < 1) {
            throw new \InvalidArgumentException('チャンク情報が不正です');
        }

        $parts = [];
        for ($i = 0; $i < $total; $i++) {
            $part = $dir.DIRECTORY_SEPARATOR.sprintf('part_%05d', $i);
            if (! is_file($part)) {
                throw new \InvalidArgumentException('欠落しているチャンクがあります（'.($i + 1).'/'.$total.'）');
            }
            $parts[] = $part;
        }

        $safeName = mb_substr(trim($originalName) !== '' ? $originalName : 'upload.bin', 0, 255);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION) ?: 'bin');
        $assembled = $dir.DIRECTORY_SEPARATOR.'assembled.'.$ext;
        $out = fopen($assembled, 'wb');
        if ($out === false) {
            throw new \InvalidArgumentException('結合用ファイルを作成できません');
        }
        try {
            foreach ($parts as $part) {
                $in = fopen($part, 'rb');
                if ($in === false) {
                    throw new \InvalidArgumentException('チャンクの読み込みに失敗しました');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        $mime = is_string($mimeHint) && $mimeHint !== ''
            ? $mimeHint
            : (mime_content_type($assembled) ?: 'application/octet-stream');

        $uploaded = new UploadedFile($assembled, $safeName, $mime, \UPLOAD_ERR_OK, true);
        try {
            $result = $this->uploadPhotos(
                $userId,
                [$uploaded],
                $albumId,
                $videoThumb ? [0 => $videoThumb] : [],
                $allowDuplicates
            );
        } finally {
            $this->deleteChunkDir($userId, $uploadId);
        }

        if (($result['created'][0] ?? null) !== null) {
            return [
                'created' => $result['created'][0],
                'skipped' => false,
                'skippedName' => null,
            ];
        }

        $skipped = $result['skipped'][0] ?? null;
        if ($skipped) {
            return [
                'created' => null,
                'skipped' => true,
                'skippedName' => $skipped['name'] ?? $safeName,
            ];
        }

        throw new \InvalidArgumentException('アップロードできるファイルがありません');
    }

    private function assertChunkUploadId(string $uploadId): string
    {
        $uploadId = trim($uploadId);
        if (! preg_match('/^[A-Za-z0-9_-]{8,80}$/', $uploadId)) {
            throw new \InvalidArgumentException('アップロードIDが不正です');
        }

        return $uploadId;
    }

    private function chunkDir(int $userId, string $uploadId): string
    {
        return storage_path('app/photo-chunks/'.$userId.'/'.$uploadId);
    }

    private function deleteChunkDir(int $userId, string $uploadId): void
    {
        $dir = $this->chunkDir($userId, $uploadId);
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function updateTakenAt(int $userId, int $photoId, ?string $takenAt): array
    {
        $photo = $this->findOwnedPhoto($userId, $photoId);
        if (! $photo) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }

        $normalized = $this->normalizeTakenAt($takenAt);
        if ($normalized === null) {
            throw new \InvalidArgumentException(__('登録日が正しくありません。'));
        }

        $photo->taken_at = $normalized;
        $photo->save();

        return $this->photoToArray($photo->fresh(), $userId);
    }

    private function normalizeTakenAt(?string $value): ?\Carbon\Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        $tz = config('app.timezone', 'Asia/Tokyo');

        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            try {
                $carbon = \Carbon\Carbon::createFromFormat($format, $raw, $tz);
                if ($carbon !== false) {
                    if ($format === 'Y-m-d') {
                        $carbon->setTime(12, 0, 0);
                    }

                    return $carbon;
                }
            } catch (\Throwable) {
                // try next format
            }
        }

        try {
            return \Carbon\Carbon::parse($raw, $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    public function deletePhoto(int $userId, int $photoId): bool
    {
        return $this->bulkDeletePhotos($userId, [$photoId]) === 1;
    }

    /** @param list<int> $ids */
    public function bulkDeletePhotos(int $userId, array $ids): int
    {
        $idSet = $this->parseIdList($ids);
        if ($idSet === []) {
            return 0;
        }

        $photos = Photo::query()
            ->where('user_id', $userId)
            ->whereIn('id', $idSet)
            ->get(['id', 'path', 'thumb_path', 'cloudinary_public_id', 'cold_disk', 'cold_path']);

        if ($photos->isEmpty()) {
            return 0;
        }

        $paths = [];
        foreach ($photos as $photo) {
            if (is_string($photo->path) && $photo->path !== '') {
                $paths[] = $photo->path;
            }
            if (is_string($photo->thumb_path) && $photo->thumb_path !== '' && $photo->thumb_path !== $photo->path) {
                $paths[] = $photo->thumb_path;
            }
            if (is_string($photo->cloudinary_public_id) && $photo->cloudinary_public_id !== '') {
                $this->cloudinary->deletePhoto(
                    $photo->cloudinary_public_id,
                    $photo->cloudinary_resource_type
                );
            }
            if (is_string($photo->cold_path) && $photo->cold_path !== '' && is_string($photo->cold_disk) && $photo->cold_disk !== '') {
                try {
                    Storage::disk($photo->cold_disk)->delete($photo->cold_path);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $this->deleteStoragePaths(array_values(array_unique($paths)));

        $photoIds = $photos->pluck('id')->map(static fn ($id) => (int) $id)->all();

        PhotoAlbum::query()
            ->where('user_id', $userId)
            ->whereIn('cover_photo_id', $photoIds)
            ->update(['cover_photo_id' => null]);

        Photo::query()
            ->where('user_id', $userId)
            ->whereIn('id', $photoIds)
            ->delete();

        return count($photoIds);
    }

    /** @param list<string> $paths */
    private function deleteStoragePaths(array $paths): void
    {
        $paths = array_values(array_filter($paths, static fn ($p) => is_string($p) && $p !== ''));
        if ($paths === []) {
            return;
        }

        if ($this->usesObjectStorage() && $this->deleteObjectStoragePathsBatched($paths)) {
            return;
        }

        // exists() を挟まない（S3/R2 では HEAD が増えてタイムアウトの原因になる）
        foreach (array_chunk($paths, 100) as $chunk) {
            try {
                $this->storage()->delete($chunk);
            } catch (\Throwable) {
                foreach ($chunk as $path) {
                    try {
                        $this->storage()->delete($path);
                    } catch (\Throwable) {
                        // 欠落ファイルは無視
                    }
                }
            }
        }
    }

    /** @param list<string> $paths */
    private function deleteObjectStoragePathsBatched(array $paths): bool
    {
        try {
            $disk = Storage::disk($this->diskName());
            $client = $disk->getClient();
            $bucket = (string) config('filesystems.disks.'.$this->diskName().'.bucket', '');
            if ($bucket === '' || ! is_object($client) || ! method_exists($client, 'deleteObjects')) {
                return false;
            }

            $prefix = (string) config('filesystems.disks.'.$this->diskName().'.root', '');
            $prefix = $prefix !== '' ? rtrim($prefix, '/').'/' : '';

            foreach (array_chunk($paths, 1000) as $chunk) {
                $objects = [];
                foreach ($chunk as $path) {
                    $objects[] = ['Key' => $prefix.ltrim($path, '/')];
                }

                $client->deleteObjects([
                    'Bucket' => $bucket,
                    'Delete' => [
                        'Objects' => $objects,
                        'Quiet' => true,
                    ],
                ]);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<int> $ids */
    public function bulkMovePhotos(int $userId, array $ids, ?int $albumId): int
    {
        $idSet = $this->parseIdList($ids);
        if ($idSet === []) {
            return 0;
        }

        if ($albumId !== null) {
            $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
            if (! $album) {
                throw new \InvalidArgumentException('アルバムが見つかりません');
            }
        }

        $photos = Photo::query()
            ->where('user_id', $userId)
            ->whereIn('id', $idSet)
            ->get();

        $moved = 0;
        foreach ($photos as $photo) {
            $oldAlbumId = $photo->album_id ? (int) $photo->album_id : null;
            $photo->album_id = $albumId;
            $photo->save();
            $moved++;

            if ($oldAlbumId && $oldAlbumId !== $albumId) {
                PhotoAlbum::query()
                    ->where('user_id', $userId)
                    ->where('id', $oldAlbumId)
                    ->where('cover_photo_id', $photo->id)
                    ->update(['cover_photo_id' => null]);
            }
        }

        return $moved;
    }

    /** @param mixed $raw @return list<int> */
    public function parseIdList(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $list = is_array($raw) ? $raw : [$raw];

        return array_values(array_unique(array_filter(
            array_map(static fn ($v) => (int) $v, $list),
            static fn ($id) => $id > 0
        )));
    }

    public function deleteAlbum(int $userId, int $albumId): bool
    {
        $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
        if (! $album) {
            return false;
        }

        $photoIds = Photo::query()
            ->where('user_id', $userId)
            ->where('album_id', $albumId)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($photoIds !== []) {
            $this->bulkDeletePhotos($userId, $photoIds);
        }

        $album->delete();

        return true;
    }

    private function assertValidUpload(UploadedFile $file): void
    {
        $mime = (string) $file->getMimeType();
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $isVideo = $this->isVideoMime($mime, $ext);

        $max = $isVideo ? $this->maxVideoUploadBytes() : $this->maxUploadBytes();
        if ($max > 0 && $file->getSize() > $max) {
            throw new \InvalidArgumentException(
                ($isVideo ? '動画' : '画像').'は'.$this->formatBytes($max).'以下にしてください'
            );
        }

        $okImageExt = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], true);
        $okVideoExt = $ext === 'mp4';
        $okMime = in_array($mime, self::ALLOWED_MIMES, true) || in_array($mime, self::ALLOWED_VIDEO_MIMES, true);

        if (! $okMime && ! ($isVideo ? $okVideoExt : $okImageExt)) {
            // 一部環境では MP4 が application/octet-stream になる
            if ($okVideoExt || $okImageExt) {
                return;
            }
            throw new \InvalidArgumentException('対応形式は JPEG / PNG / WebP / GIF / HEIC / MP4 です');
        }
    }

    private function throwUploadError(UploadedFile $file): void
    {
        $code = $file->getError();
        if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new \InvalidArgumentException(
                'ファイルが PHP のアップロード上限を超えています（upload_max_filesize='
                .ini_get('upload_max_filesize')
                .' / post_max_size='
                .ini_get('post_max_size')
                .'）。900M 以上に上げてサーバーを再起動してください。'
            );
        }
        if ($code !== UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException('ファイルのアップロードに失敗しました（エラーコード: '.$code.'）');
        }
    }

    /**
     * MP4 を保存し、クライアント生成サムネがあればそれを使う（なければ仮サムネ）。
     *
     * @return array{path: string, thumbPath: ?string, mime: string, sizeBytes: int, width: ?int, height: ?int}|null
     */
    private function storeVideo(UploadedFile $file, string $dir, ?UploadedFile $thumbFile = null): ?array
    {
        // 動画を丸ごとメモリに載せない（ストリーム転送）
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '1024M');
        }

        $basename = str_replace('.', '', uniqid('vid_', true));
        $filename = $basename.'.mp4';
        $dir = trim($dir, '/');

        try {
            $path = $this->storage()->putFileAs($dir, $file, $filename, [
                'visibility' => 'public',
                'ContentType' => 'video/mp4',
                'mimetype' => 'video/mp4',
            ]);
        } catch (\Throwable $e) {
            report($e);
            throw new \InvalidArgumentException(
                '動画の保存に失敗しました。サイズを小さくするか、しばらくして再試行してください。'
            );
        }

        if (! is_string($path) || $path === '') {
            throw new \InvalidArgumentException(
                '動画の保存に失敗しました。ストレージ設定（R2 / ディスク）を確認してください。'
            );
        }

        $width = 1280;
        $height = 720;
        $thumbPath = $this->storeUploadedVideoThumb($thumbFile, $dir.'/'.$basename.'_thumb.jpg', $width, $height)
            ?? $this->storeVideoPlaceholderThumb($dir.'/'.$basename.'_thumb.jpg');

        return [
            'path' => $path,
            'thumbPath' => $thumbPath,
            'mime' => 'video/mp4',
            'sizeBytes' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
        ];
    }

    private function storeUploadedVideoThumb(?UploadedFile $thumbFile, string $thumbPath, int &$width, int &$height): ?string
    {
        if (! $thumbFile instanceof UploadedFile || ! $thumbFile->isValid()) {
            return null;
        }
        $mime = strtolower((string) $thumbFile->getMimeType());
        if (! str_contains($mime, 'jpeg') && ! str_contains($mime, 'jpg') && ! str_contains($mime, 'png')) {
            return null;
        }
        if ($thumbFile->getSize() > 5 * 1024 * 1024) {
            return null;
        }

        $source = $thumbFile->getRealPath();
        if (! $source) {
            return null;
        }
        $size = @getimagesize($source);
        if (is_array($size) && ($size[0] ?? 0) > 0 && ($size[1] ?? 0) > 0) {
            $width = (int) $size[0];
            $height = (int) $size[1];
        }

        try {
            $this->putFileContents($thumbPath, (string) file_get_contents($source), 'image/jpeg');
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $this->storage()->exists($thumbPath) ? $thumbPath : null;
    }

    private function storeVideoPlaceholderThumb(string $thumbPath): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $w = 640;
        $h = 360;
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 26, 31, 36);
        $accent = imagecolorallocate($im, 47, 111, 126);
        $white = imagecolorallocate($im, 245, 247, 248);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);

        // 再生ボタン（三角）
        $cx = (int) ($w / 2);
        $cy = (int) ($h / 2);
        $r = 48;
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $accent);
        $triangle = [
            $cx - 14, $cy - 22,
            $cx - 14, $cy + 22,
            $cx + 24, $cy,
        ];
        imagefilledpolygon($im, $triangle, $white);

        $tmp = tempnam(sys_get_temp_dir(), 'vth');
        if ($tmp === false) {
            imagedestroy($im);

            return null;
        }
        imagejpeg($im, $tmp, 82);
        imagedestroy($im);

        try {
            $this->putFileContents($thumbPath, (string) file_get_contents($tmp), 'image/jpeg');
        } finally {
            @unlink($tmp);
        }

        return $this->storage()->exists($thumbPath) ? $thumbPath : null;
    }

    /**
     * 画像を解像度そのまま保存し、一覧用サムネだけ生成する。
     * ローカル / R2（S3互換）どちらでも動くよう putFileAs / 一時ファイル経由で put する。
     *
     * @return array{path: string, thumbPath: ?string, mime: string, sizeBytes: int, width: ?int, height: ?int}|null
     */
    private function storeOptimizedImage(UploadedFile $file, string $dir): ?array
    {
        $sourcePath = $file->getRealPath();
        if (! $sourcePath) {
            return null;
        }

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }

        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $ext = $this->imageStorageExtension($file, $mime);
        $basename = str_replace('.', '', uniqid('ph_', true));
        $dir = trim($dir, '/');
        $filename = $basename.'.'.$ext;
        $thumbRel = $dir.'/'.$basename.'_thumb.jpg';

        try {
            $path = $this->storage()->putFileAs($dir, $file, $filename, [
                'visibility' => 'public',
                'ContentType' => $mime,
                'mimetype' => $mime,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! is_string($path) || $path === '' || ! $this->storage()->exists($path)) {
            return null;
        }

        $width = null;
        $height = null;
        $thumbPath = null;
        $src = $this->createImageResource($sourcePath, $mime);
        $thumbEdge = max(240, (int) config('photos.thumb_long_edge', 720));
        $quality = max(40, min(95, (int) config('photos.jpeg_quality', 82)));

        if ($src) {
            $sw = imagesx($src);
            $sh = imagesy($src);
            if ($sw >= 1 && $sh >= 1) {
                $width = $sw;
                $height = $sh;
                [$tw, $th] = $this->scaledSize($sw, $sh, $thumbEdge);
                $thumb = imagecreatetruecolor($tw, $th);
                $this->fillWhite($thumb);
                imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
                $thumbTmp = tempnam(sys_get_temp_dir(), 'pht');
                if ($thumbTmp !== false) {
                    imagejpeg($thumb, $thumbTmp, $quality);
                    try {
                        $this->putFileContents($thumbRel, (string) file_get_contents($thumbTmp), 'image/jpeg');
                        if ($this->storage()->exists($thumbRel)) {
                            $thumbPath = $thumbRel;
                        }
                    } finally {
                        @unlink($thumbTmp);
                    }
                }
                imagedestroy($thumb);
            }
            imagedestroy($src);
        } else {
            $size = @getimagesize($sourcePath);
            if (is_array($size)) {
                $width = $size[0] ?? null;
                $height = $size[1] ?? null;
            }
        }

        return [
            'path' => $path,
            'thumbPath' => $thumbPath,
            'mime' => $mime,
            'sizeBytes' => (int) $this->storage()->size($path),
            'width' => $width,
            'height' => $height,
        ];
    }

    private function imageStorageExtension(UploadedFile $file, string $mime): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], true)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }

        return match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'heic') => 'heic',
            str_contains($mime, 'heif') => 'heif',
            default => 'bin',
        };
    }

    private function storage(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function putFileContents(string $path, string $contents, string $contentType): void
    {
        $options = [
            'visibility' => 'public',
            'ContentType' => $contentType,
        ];
        $this->storage()->put($path, $contents, $options);
    }

    /** @return \GdImage|false|resource */
    private function createImageResource(string $sourcePath, string $mime)
    {
        if (! function_exists('imagecreatetruecolor')) {
            return false;
        }

        return match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => @imagecreatefromjpeg($sourcePath),
            str_contains($mime, 'png') => @imagecreatefrompng($sourcePath),
            str_contains($mime, 'webp') && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($sourcePath),
            str_contains($mime, 'gif') => @imagecreatefromgif($sourcePath),
            default => false,
        };
    }

    /** @return array{0: int, 1: int} */
    private function scaledSize(int $width, int $height, int $maxEdge): array
    {
        $scale = min(1, $maxEdge / max($width, $height));

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }

    /** @param \GdImage|resource $image */
    private function fillWhite($image): void
    {
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $white);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }

    /**
     * Save an edited image as a new photo (original untouched).
     */
    public function findOwnedPhoto(int $userId, int $photoId): ?Photo
    {
        return Photo::query()->where('user_id', $userId)->find($photoId);
    }

    public function findViewablePhoto(int $userId, int $photoId): ?Photo
    {
        $photo = Photo::query()->with('album')->find($photoId);
        if (! $photo) {
            return null;
        }
        if ((int) $photo->user_id === $userId) {
            return $photo;
        }
        if ($photo->album_id) {
            $album = $photo->album ?: $this->findViewableAlbum($userId, (int) $photo->album_id);
            if ($album && $this->canViewAlbum($userId, $album)) {
                return $photo;
            }
        }

        return null;
    }

    /** @return array{contents: string, mime: string, name: string} */
    public function readPhotoFile(Photo $photo): array
    {
        $path = (string) $photo->path;
        $disk = $this->storageForPhoto($photo);
        if (! $disk->exists($path) && ($photo->storage_tier ?? 'hot') === 'cold') {
            // cold_path が別キーの場合
            $alt = (string) ($photo->cold_path ?: '');
            if ($alt !== '' && $disk->exists($alt)) {
                $path = $alt;
            }
        }
        if (! $disk->exists($path)) {
            // フォールバック: 主ディスク
            $disk = $this->storage();
        }
        if (! $disk->exists($path)) {
            throw new \InvalidArgumentException(__('ファイルが見つかりません。'));
        }

        return [
            'contents' => $disk->get($path),
            'mime' => (string) ($photo->mime ?: 'application/octet-stream'),
            'name' => (string) ($photo->original_name ?: basename($path)),
        ];
    }

    public function saveEditedImage(int $userId, int $photoId, UploadedFile $image, ?string $label = null): array
    {
        $source = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $source) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }
        if ($this->isVideoMime((string) $source->mime, pathinfo((string) $source->path, PATHINFO_EXTENSION))) {
            throw new \InvalidArgumentException(__('動画は画像編集できません。動画トリムを使ってください。'));
        }

        $dir = 'photos/'.$userId;
        $stored = $this->storeOptimizedImage($image, $dir);
        if (! $stored) {
            throw new \InvalidArgumentException(__('編集画像の保存に失敗しました。'));
        }

        $minSort = (int) Photo::query()->where('user_id', $userId)->min('sort_order');
        $photo = Photo::create([
            'user_id' => $userId,
            'album_id' => $source->album_id,
            'parent_photo_id' => $source->id,
            'path' => $stored['path'],
            'thumb_path' => $stored['thumbPath'],
            'original_name' => $source->original_name,
            'mime' => $stored['mime'],
            'size_bytes' => $stored['sizeBytes'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'caption' => $source->caption,
            'edit_label' => $label ? mb_substr(trim($label), 0, 120) : __('編集版'),
            'taken_at' => $source->taken_at,
            'sort_order' => $minSort - 10,
            'storage_tier' => 'hot',
        ]);

        // 表示用の常設 Cloudinary 同期はしない（編集専用方針）
        if ($this->mediaConfig->pipelineUsesCloudinaryDisplay()) {
            $this->maybeSyncCloudinary($photo);
        }

        return $this->photoToArray($photo->fresh() ?? $photo, $userId);
    }

    /**
     * Stability AI で鮮明化し、結果を R2（現行 photos.disk）へ新規保存する。元画像は残す。
     *
     * @return array<string, mixed>
     */
    public function enhanceWithStability(int $userId, int $photoId): array
    {
        if (! $this->mediaConfig->stabilityEnabled()) {
            throw new \InvalidArgumentException(__('Stability AI が有効ではありません。ストレージ設定を確認してください。'));
        }

        $source = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $source) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }
        if ($this->isVideoMime((string) $source->mime, pathinfo((string) $source->path, PATHINFO_EXTENSION))) {
            throw new \InvalidArgumentException(__('動画は AI 鮮明化の対象外です。'));
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $file = $this->readPhotoFile($source);
        $enhanced = $this->stability->enhanceImage(
            $file['contents'],
            $file['name'],
            $file['mime']
        );

        $tmp = tempnam(sys_get_temp_dir(), 'stab_out_');
        if ($tmp === false) {
            throw new \RuntimeException(__('一時ファイルを作成できません。'));
        }

        try {
            file_put_contents($tmp, $enhanced['binary']);
            $uploaded = new UploadedFile(
                $tmp,
                'stability-enhance.'.$enhanced['extension'],
                $enhanced['mime'],
                null,
                true
            );

            return $this->saveEditedImage($userId, $photoId, $uploaded, __('AI鮮明化'));
        } finally {
            @unlink($tmp);
        }
    }

    public function saveEditedImageFromUrl(int $userId, int $photoId, string $imageUrl, ?string $label = null): array
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '' || ! str_starts_with($imageUrl, 'https://')) {
            throw new \InvalidArgumentException(__('編集結果のURLが不正です。'));
        }

        // Cloudinary / 自前CDN以外は拒否
        $host = parse_url($imageUrl, PHP_URL_HOST) ?: '';
        if (! is_string($host) || (! str_ends_with($host, 'cloudinary.com') && ! str_ends_with($host, 'cloudinary.com.'))) {
            // allow res.cloudinary.com and subdomains
            if (! str_contains($host, 'cloudinary.com')) {
                throw new \InvalidArgumentException(__('許可されていない編集結果URLです。'));
            }
        }

        $response = \Illuminate\Support\Facades\Http::timeout(120)->get($imageUrl);
        if (! $response->successful()) {
            throw new \RuntimeException(__('編集結果の取得に失敗しました。'));
        }

        $binary = $response->body();
        if ($binary === '') {
            throw new \RuntimeException(__('編集結果が空です。'));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cld_edit_');
        if ($tmp === false) {
            throw new \RuntimeException(__('一時ファイルを作成できません。'));
        }

        try {
            file_put_contents($tmp, $binary);
            $uploaded = new UploadedFile($tmp, 'cloudinary-edit.jpg', 'image/jpeg', null, true);

            return $this->saveEditedImage($userId, $photoId, $uploaded, $label ?: __('Cloudinary編集'));
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Cloudinary Media Editor 用セッション開始。
     *
     * @return array{cloudName: string, publicId: string, resourceType: string}
     */
    public function startCloudinaryEdit(int $userId, int $photoId): array
    {
        if (! $this->mediaConfig->cloudinaryEditorEnabled()) {
            throw new \InvalidArgumentException(__('Cloudinary 編集が有効ではありません。'));
        }
        $photo = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $photo) {
            throw new \InvalidArgumentException(__('写真が見つかりません'));
        }

        return $this->cloudinary->startEditSession($photo);
    }

    /**
     * Media Editor の書き出し結果を R2 に保存し、一時アセットを削除する。
     *
     * @return array<string, mixed>
     */
    public function commitCloudinaryEdit(
        int $userId,
        int $photoId,
        string $exportUrl,
        string $tempPublicId,
        ?string $label = null
    ): array {
        $created = $this->saveEditedImageFromUrl($userId, $photoId, $exportUrl, $label);
        if ($tempPublicId !== '') {
            $this->cloudinary->deletePhoto($tempPublicId, 'image');
        }

        // 表示用の常設同期はしない（編集版も R2 のみ）
        return $created;
    }

    public function cancelCloudinaryEdit(string $tempPublicId): void
    {
        if ($tempPublicId !== '') {
            $this->cloudinary->deletePhoto($tempPublicId, 'image');
        }
    }

    public function trimVideo(int $userId, int $photoId, float $startSec, float $endSec): array
    {
        $source = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $source) {
            throw new \InvalidArgumentException(__('動画が見つかりません'));
        }
        if (! $this->isVideoMime((string) $source->mime, pathinfo((string) $source->path, PATHINFO_EXTENSION))) {
            throw new \InvalidArgumentException(__('動画以外はトリムできません。'));
        }

        $disk = $this->storage();
        $tmpIn = tempnam(sys_get_temp_dir(), 'vid_in_');
        $tmpOut = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('vid_out_', true).'.mp4';
        if ($tmpIn === false) {
            throw new \RuntimeException(__('一時ファイルを作成できません。'));
        }

        try {
            file_put_contents($tmpIn, $this->storageForPhoto($source)->get($source->path));
            $this->ffmpeg->trim($tmpIn, $tmpOut, $startSec, $endSec);

            $basename = str_replace('.', '', uniqid('vid_trim_', true)).'.mp4';
            $dir = 'photos/'.$userId;
            $path = $disk->putFileAs($dir, new UploadedFile($tmpOut, $basename, 'video/mp4', null, true), $basename, [
                'visibility' => 'public',
                'ContentType' => 'video/mp4',
            ]);
            if (! is_string($path) || $path === '') {
                throw new \RuntimeException(__('切り出し動画の保存に失敗しました。'));
            }

            $minSort = (int) Photo::query()->where('user_id', $userId)->min('sort_order');
            $photo = Photo::create([
                'user_id' => $userId,
                'album_id' => $source->album_id,
                'parent_photo_id' => $source->id,
                'path' => $path,
                'thumb_path' => $source->thumb_path,
                'original_name' => $source->original_name,
                'mime' => 'video/mp4',
                'size_bytes' => (int) filesize($tmpOut),
                'width' => $source->width,
                'height' => $source->height,
                'caption' => $source->caption,
                'edit_label' => sprintf('%s %.1f-%.1fs', __('トリム'), $startSec, $endSec),
                'taken_at' => $source->taken_at,
                'sort_order' => $minSort - 10,
            ]);

            return $this->photoToArray($photo, $userId);
        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
        }
    }

    /** @return array<string, mixed> */
    public function albumToArray(PhotoAlbum $album, ?int $viewerUserId = null): array
    {
        $cover = null;
        if ($album->cover_photo_id) {
            $cover = Photo::query()->find($album->cover_photo_id);
        }
        if (! $cover) {
            $cover = Photo::query()->where('album_id', $album->id)->orderByDesc('id')->first();
        }

        $coverIsVideo = $cover
            ? $this->isVideoMime((string) $cover->mime, pathinfo((string) $cover->path, PATHINFO_EXTENSION))
            : false;
        $coverArr = $cover ? $this->photoToArray($cover, $viewerUserId) : null;

        $visibility = $album->visibilityEnum();
        $isOwner = $viewerUserId !== null && (int) $album->user_id === $viewerUserId;

        return [
            'id' => $album->id,
            'name' => $album->name,
            'description' => $album->description,
            'visibility' => $visibility->value,
            'visibilityLabel' => __($visibility->label()),
            'groupId' => $album->group_id,
            'groupName' => $album->relationLoaded('group') ? $album->group?->name : null,
            'ownerUserId' => $album->user_id,
            'ownerName' => $album->relationLoaded('user') ? $album->user?->display_name : null,
            'isOwner' => $isOwner,
            'canManage' => $isOwner,
            'coverPhotoId' => $album->cover_photo_id,
            'photoCount' => (int) ($album->photos_count ?? $album->photos()->count()),
            'coverUrl' => $coverArr
                ? ($coverIsVideo ? ($coverArr['url'] ?? null) : ($coverArr['thumbUrl'] ?? null))
                : null,
            'coverMediaKind' => $coverIsVideo ? 'video' : 'image',
            'sortOrder' => (int) $album->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    public function photoToArray(Photo $photo, ?int $viewerUserId = null): array
    {
        $takenAt = $photo->taken_at?->format('Y-m-d H:i');
        $takenDate = $photo->taken_at?->format('Y-m-d');
        $takenAtLocal = $photo->taken_at?->format('Y-m-d\TH:i');
        $mime = (string) ($photo->mime ?? '');
        $mediaKind = $this->isVideoMime($mime, pathinfo((string) $photo->path, PATHINFO_EXTENSION))
            ? 'video'
            : 'image';

        $storageUrl = $this->publicUrlForPhoto($photo, $photo->path);
        $storageThumb = $this->publicUrlForPhoto($photo, $photo->thumb_path ?: $photo->path) ?: asset('icons/pwa-192.png');
        $url = $storageUrl;
        $thumbUrl = $storageThumb;

        if ($this->mediaConfig->pipelineUsesCloudinaryDisplay()
            && is_string($photo->cloudinary_public_id)
            && $photo->cloudinary_public_id !== '') {
            $resourceType = ($photo->cloudinary_resource_type === 'video' || $mediaKind === 'video')
                ? 'video'
                : 'image';
            $thumbEdge = max(240, (int) config('photos.thumb_long_edge', 720));

            if ($resourceType === 'video') {
                $cdn = $this->cloudinary->deliveryUrl($photo->cloudinary_public_id, null, 'video');
                $cdnThumb = $this->cloudinary->deliveryUrl($photo->cloudinary_public_id, $thumbEdge, 'video', true);
            } else {
                $cdn = $this->cloudinary->deliveryUrl($photo->cloudinary_public_id, null, 'image');
                $cdnThumb = $this->cloudinary->deliveryUrl($photo->cloudinary_public_id, $thumbEdge, 'image');
            }
            if ($cdn) {
                $url = $cdn;
            }
            if ($cdnThumb) {
                $thumbUrl = $cdnThumb;
            }
        }

        return [
            'id' => $photo->id,
            'albumId' => $photo->album_id,
            'parentPhotoId' => $photo->parent_photo_id,
            'editLabel' => $photo->edit_label,
            'url' => $url,
            'thumbUrl' => $thumbUrl,
            'originalName' => $photo->original_name,
            'caption' => $photo->caption,
            'mime' => $mime,
            'mediaKind' => $mediaKind,
            'width' => $photo->width,
            'height' => $photo->height,
            'takenAt' => $takenAt,
            'takenDate' => $takenDate,
            'takenAtLocal' => $takenAtLocal,
            'createdAt' => $photo->created_at?->toIso8601String(),
            'canEdit' => $viewerUserId !== null && (int) $photo->user_id === $viewerUserId,
            'fileUrl' => '/photos/'.$photo->id.'/file',
            'storageTier' => $photo->storage_tier ?? 'hot',
        ];
    }

    private function formatDateGroupLabel(string $date, string $today, string $yesterday): string
    {
        if ($date === 'unknown') {
            return __('日付不明');
        }
        if ($date === $today) {
            return __('今日');
        }
        if ($date === $yesterday) {
            return __('昨日');
        }

        try {
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d', $date, config('app.timezone', 'Asia/Tokyo'));

            return app()->getLocale() === 'en'
                ? $carbon->locale('en')->isoFormat('MMM D, YYYY')
                : $carbon->format('Y年n月j日');
        } catch (\Throwable) {
            return $date;
        }
    }

    private function maybeSyncCloudinary(Photo $photo): void
    {
        if (! $this->mediaConfig->pipelineUsesCloudinaryDisplay() || ! $this->cloudinary->isReady()) {
            return;
        }

        $isVideo = $this->isVideoMime((string) $photo->mime, pathinfo((string) $photo->path, PATHINFO_EXTENSION));

        try {
            if ($isVideo) {
                // 大容量になりやすいのでキューへ
                SyncPhotoToCloudinary::dispatch($photo->id);
            } else {
                $this->cloudinary->syncPhoto($photo);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function diskForPhoto(Photo $photo): string
    {
        if (($photo->storage_tier ?? 'hot') === 'cold'
            && is_string($photo->cold_disk)
            && $photo->cold_disk !== '') {
            return $photo->cold_disk;
        }

        return $this->diskName();
    }

    private function storageForPhoto(Photo $photo): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($this->diskForPhoto($photo));
    }

    private function publicUrlForPhoto(Photo $photo, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        // サムネはホット側に残す運用のため、thumb は常に主ディスクを優先
        if ($path === $photo->thumb_path && $path !== $photo->path) {
            try {
                return $this->storage()->url($path);
            } catch (\Throwable) {
                // fall through
            }
        }

        try {
            return $this->storageForPhoto($photo)->url($path);
        } catch (\Throwable) {
            return $this->storage()->url($path);
        }
    }

    private function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return $this->storage()->url($path);
    }
}
