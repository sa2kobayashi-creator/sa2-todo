<?php

namespace App\Services;

use App\Enums\AlbumVisibility;
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
    ) {}

    public function maxUploadBytes(): int
    {
        // 0 = アプリ側の画像サイズ上限なし（PHP の upload_max_filesize は別途あり）
        return max(0, (int) config('photos.max_upload_bytes', 0));
    }

    public function maxVideoUploadBytes(): int
    {
        return max(1, (int) config('photos.max_video_upload_bytes', 100 * 1024 * 1024));
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

    /** @return array{usedBytes: int, quotaBytes: int, percent: float, photoCount: int, remainingBytes: int, formattedUsed: string, formattedQuota: string, disk: string, diskLabel: string, overFreeTier: bool, overagePriceLabel: string} */
    public function storageStats(int $userId): array
    {
        $used = (int) Photo::query()->where('user_id', $userId)->sum('size_bytes');
        // サムネ分は size_bytes に含まれないため、実ディスクは少し多い。表示は DB 上の保存サイズを基準にする。
        $thumbExtra = (int) Photo::query()
            ->where('user_id', $userId)
            ->whereNotNull('thumb_path')
            ->count() * 80_000; // おおよそ 80KB/枚
        $usedApprox = $used + $thumbExtra;
        $quota = $this->userQuotaBytes();
        $percent = round(($usedApprox / $quota) * 100, 1);
        $disk = $this->diskName();
        $price = $this->overagePricePerGbMonthUsd();

        return [
            'usedBytes' => $usedApprox,
            'quotaBytes' => $quota,
            'percent' => $percent,
            'photoCount' => (int) Photo::query()->where('user_id', $userId)->count(),
            'remainingBytes' => max(0, $quota - $usedApprox),
            'formattedUsed' => $this->formatBytes($usedApprox),
            'formattedQuota' => $this->formatBytes($quota),
            'disk' => $disk,
            'diskLabel' => $disk === 'r2' ? 'Cloudflare R2' : ($disk === 'public' ? __('サーバーローカル') : $disk),
            'overFreeTier' => $usedApprox > $quota,
            'overagePriceLabel' => '$'.rtrim(rtrim(number_format($price, 3, '.', ''), '0'), '.').__('/GB/月'),
        ];
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
     */
    public function uploadPhotos(int $userId, array $files, ?int $albumId = null, array $videoThumbsByIndex = []): array
    {
        if ($albumId !== null) {
            $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
            if (! $album) {
                throw new \InvalidArgumentException('アルバムが見つかりません');
            }
        }

        $created = [];
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

            $dir = 'photos/'.$userId.'/'.now()->format('Y/m');
            $videoThumb = $videoThumbsByIndex[$index] ?? null;
            $stored = $this->isVideoMime($file->getMimeType(), $file->getClientOriginalExtension())
                ? $this->storeVideo($file, $dir, $videoThumb instanceof UploadedFile ? $videoThumb : null)
                : $this->storeOptimizedImage($file, $dir);
            if ($stored === null) {
                continue;
            }

            $photo = Photo::create([
                'user_id' => $userId,
                'album_id' => $albumId,
                'path' => $stored['path'],
                'thumb_path' => $stored['thumbPath'],
                'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'mime' => $stored['mime'],
                'size_bytes' => $stored['sizeBytes'],
                'width' => $stored['width'],
                'height' => $stored['height'],
                'taken_at' => now(),
                'sort_order' => $nextOrder,
            ]);
            $nextOrder -= 10;
            $created[] = $this->photoToArray($photo, $userId);

            if ($albumId !== null) {
                $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
                if ($album && ! $album->cover_photo_id) {
                    $album->cover_photo_id = $photo->id;
                    $album->save();
                }
            }
        }

        if ($created === []) {
            throw new \InvalidArgumentException('アップロードできるファイルがありません');
        }

        return $created;
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
        $photo = Photo::query()->where('user_id', $userId)->find($photoId);
        if (! $photo) {
            return false;
        }

        foreach ([$photo->path, $photo->thumb_path] as $path) {
            if ($path && $this->storage()->exists($path)) {
                $this->storage()->delete($path);
            }
        }

        PhotoAlbum::query()
            ->where('user_id', $userId)
            ->where('cover_photo_id', $photo->id)
            ->update(['cover_photo_id' => null]);

        $photo->delete();

        return true;
    }

    /** @param list<int> $ids */
    public function bulkDeletePhotos(int $userId, array $ids): int
    {
        $count = 0;
        foreach ($this->parseIdList($ids) as $id) {
            if ($this->deletePhoto($userId, $id)) {
                $count++;
            }
        }

        return $count;
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

        $photos = Photo::query()->where('user_id', $userId)->where('album_id', $albumId)->get();
        foreach ($photos as $photo) {
            $this->deletePhoto($userId, $photo->id);
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
                .'）。128M 以上に上げてサーバーを再起動してください。'
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
        // 動画を丸ごとメモリに載せない（128MB制限で 500 になるのを防ぐ）
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '256M');
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
        $disk = $this->storage();
        if (! $disk->exists($photo->path)) {
            throw new \InvalidArgumentException(__('ファイルが見つかりません。'));
        }

        return [
            'contents' => $disk->get($photo->path),
            'mime' => (string) ($photo->mime ?: 'application/octet-stream'),
            'name' => (string) ($photo->original_name ?: basename((string) $photo->path)),
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
        ]);

        return $this->photoToArray($photo, $userId);
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
            file_put_contents($tmpIn, $disk->get($source->path));
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
            'coverUrl' => $cover
                ? ($coverIsVideo
                    ? $this->publicUrl($cover->path)
                    : $this->publicUrl($cover->thumb_path ?: $cover->path))
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

        return [
            'id' => $photo->id,
            'albumId' => $photo->album_id,
            'parentPhotoId' => $photo->parent_photo_id,
            'editLabel' => $photo->edit_label,
            'url' => $this->publicUrl($photo->path),
            'thumbUrl' => $this->publicUrl($photo->thumb_path ?: $photo->path) ?: asset('icons/pwa-192.png'),
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

    private function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return $this->storage()->url($path);
    }
}
