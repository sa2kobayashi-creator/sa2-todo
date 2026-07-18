<?php

namespace App\Services;

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

    public function maxUploadBytes(): int
    {
        return max(1, (int) config('photos.max_upload_bytes', 12 * 1024 * 1024));
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
        return max(1, (int) config('photos.user_quota_bytes', 500 * 1024 * 1024));
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

    /** @return array{usedBytes: int, quotaBytes: int, percent: float, photoCount: int, remainingBytes: int, formattedUsed: string, formattedQuota: string, disk: string, diskLabel: string} */
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
        $percent = min(100, round(($usedApprox / $quota) * 100, 1));
        $disk = $this->diskName();

        return [
            'usedBytes' => $usedApprox,
            'quotaBytes' => $quota,
            'percent' => $percent,
            'photoCount' => (int) Photo::query()->where('user_id', $userId)->count(),
            'remainingBytes' => max(0, $quota - $usedApprox),
            'formattedUsed' => $this->formatBytes($usedApprox),
            'formattedQuota' => $this->formatBytes($quota),
            'disk' => $disk,
            'diskLabel' => $disk === 'r2' ? 'Cloudflare R2' : ($disk === 'public' ? 'サーバーローカル' : $disk),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listAlbums(int $userId): array
    {
        return PhotoAlbum::query()
            ->where('user_id', $userId)
            ->withCount('photos')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (PhotoAlbum $album) => $this->albumToArray($album))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function listPhotos(int $userId, ?int $albumId = null): array
    {
        $query = Photo::query()->where('user_id', $userId);
        if ($albumId !== null) {
            $query->where('album_id', $albumId);
        }

        return $query
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Photo $photo) => $this->photoToArray($photo))
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

    public function createAlbum(int $userId, string $name, ?string $description = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('アルバム名を入力してください');
        }

        $max = (int) PhotoAlbum::query()->where('user_id', $userId)->max('sort_order');
        $album = PhotoAlbum::create([
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 120),
            'description' => $description !== null ? mb_substr(trim($description), 0, 500) : null,
            'sort_order' => $max + 10,
        ]);

        return $this->albumToArray($album->loadCount('photos'));
    }

    public function updateAlbum(int $userId, int $albumId, string $name, ?string $description = null): array
    {
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
        $album->save();

        return $this->albumToArray($album->loadCount('photos'));
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

        return $this->albumToArray($album->loadCount('photos'));
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

        $stats = $this->storageStats($userId);
        $incomingEstimate = 0;
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $size = (int) $file->getSize();
            if ($this->isVideoMime($file->getMimeType(), $file->getClientOriginalExtension())) {
                $incomingEstimate += $size;
            } else {
                $incomingEstimate += (int) ($size * 0.35);
            }
        }
        if ($stats['usedBytes'] >= $stats['quotaBytes']) {
            throw new \InvalidArgumentException('保存容量上限（'.$stats['formattedQuota'].'）に達しています');
        }
        if ($stats['usedBytes'] + $incomingEstimate > $stats['quotaBytes']) {
            throw new \InvalidArgumentException('保存容量が不足しています（使用中 '.$stats['formattedUsed'].' / '.$stats['formattedQuota'].'）');
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
            $created[] = $this->photoToArray($photo);

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
        if ($file->getSize() > $max) {
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
     * 長辺を縮小して JPEG 保存し、サムネも生成する。
     * GD で扱えない形式（HEIC 等）は原本をそのまま保存する。
     * ローカル / R2（S3互換）どちらでも動くよう一時ファイル経由で put する。
     *
     * @return array{path: string, thumbPath: ?string, mime: string, sizeBytes: int, width: ?int, height: ?int}|null
     */
    private function storeOptimizedImage(UploadedFile $file, string $dir): ?array
    {
        $sourcePath = $file->getRealPath();
        if (! $sourcePath) {
            return null;
        }

        $mime = (string) $file->getMimeType();
        $src = $this->createImageResource($sourcePath, $mime);
        $quality = max(40, min(95, (int) config('photos.jpeg_quality', 82)));
        $maxEdge = max(640, (int) config('photos.max_long_edge', 1920));
        $thumbEdge = max(240, (int) config('photos.thumb_long_edge', 720));

        if (! $src) {
            $path = $file->store(trim($dir, '/'), $this->diskName());
            if (! $path) {
                return null;
            }
            $size = @getimagesize($sourcePath);

            return [
                'path' => $path,
                'thumbPath' => null,
                'mime' => $mime ?: 'application/octet-stream',
                'sizeBytes' => (int) $this->storage()->size($path),
                'width' => is_array($size) ? ($size[0] ?? null) : null,
                'height' => is_array($size) ? ($size[1] ?? null) : null,
            ];
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            imagedestroy($src);

            return null;
        }

        [$dw, $dh] = $this->scaledSize($sw, $sh, $maxEdge);
        $main = imagecreatetruecolor($dw, $dh);
        $this->fillWhite($main);
        imagecopyresampled($main, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);

        $basename = str_replace('.', '', uniqid('ph_', true));
        $path = trim($dir, '/').'/'.$basename.'.jpg';
        $thumbPath = trim($dir, '/').'/'.$basename.'_thumb.jpg';

        $mainTmp = tempnam(sys_get_temp_dir(), 'phm');
        $thumbTmp = tempnam(sys_get_temp_dir(), 'pht');
        if ($mainTmp === false || $thumbTmp === false) {
            imagedestroy($src);
            imagedestroy($main);

            return null;
        }

        imagejpeg($main, $mainTmp, $quality);

        [$tw, $th] = $this->scaledSize($dw, $dh, $thumbEdge);
        $thumb = imagecreatetruecolor($tw, $th);
        $this->fillWhite($thumb);
        imagecopyresampled($thumb, $main, 0, 0, 0, 0, $tw, $th, $dw, $dh);
        imagejpeg($thumb, $thumbTmp, $quality);

        imagedestroy($src);
        imagedestroy($main);
        imagedestroy($thumb);

        try {
            $this->putFileContents($path, (string) file_get_contents($mainTmp), 'image/jpeg');
            $this->putFileContents($thumbPath, (string) file_get_contents($thumbTmp), 'image/jpeg');
        } finally {
            @unlink($mainTmp);
            @unlink($thumbTmp);
        }

        if (! $this->storage()->exists($path)) {
            return null;
        }

        return [
            'path' => $path,
            'thumbPath' => $this->storage()->exists($thumbPath) ? $thumbPath : null,
            'mime' => 'image/jpeg',
            'sizeBytes' => (int) $this->storage()->size($path),
            'width' => $dw,
            'height' => $dh,
        ];
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

    /** @return array<string, mixed> */
    public function albumToArray(PhotoAlbum $album): array
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

        return [
            'id' => $album->id,
            'name' => $album->name,
            'description' => $album->description,
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
    public function photoToArray(Photo $photo): array
    {
        $takenAt = $photo->taken_at?->format('Y-m-d H:i');
        $takenDate = $photo->taken_at?->format('Y-m-d');
        $mime = (string) ($photo->mime ?? '');
        $mediaKind = $this->isVideoMime($mime, pathinfo((string) $photo->path, PATHINFO_EXTENSION))
            ? 'video'
            : 'image';

        return [
            'id' => $photo->id,
            'albumId' => $photo->album_id,
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
            'createdAt' => $photo->created_at?->toIso8601String(),
        ];
    }

    private function formatDateGroupLabel(string $date, string $today, string $yesterday): string
    {
        if ($date === 'unknown') {
            return '日付不明';
        }
        if ($date === $today) {
            return '今日';
        }
        if ($date === $yesterday) {
            return '昨日';
        }

        try {
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d', $date, config('app.timezone', 'Asia/Tokyo'));

            return $carbon->format('Y年n月j日');
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
