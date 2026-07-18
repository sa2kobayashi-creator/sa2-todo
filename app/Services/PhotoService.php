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

    public function maxUploadBytes(): int
    {
        return max(1, (int) config('photos.max_upload_bytes', 12 * 1024 * 1024));
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

    /** @param list<UploadedFile> $files */
    public function uploadPhotos(int $userId, array $files, ?int $albumId = null): array
    {
        if ($albumId !== null) {
            $album = PhotoAlbum::query()->where('user_id', $userId)->find($albumId);
            if (! $album) {
                throw new \InvalidArgumentException('アルバムが見つかりません');
            }
        }

        $stats = $this->storageStats($userId);
        $incoming = 0;
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $incoming += (int) $file->getSize();
            }
        }
        // 圧縮後は小さくなる想定だが、上限超過の粗いガード
        if ($stats['usedBytes'] >= $stats['quotaBytes']) {
            throw new \InvalidArgumentException('写真の保存容量上限（'.$stats['formattedQuota'].'）に達しています');
        }
        if ($stats['usedBytes'] + (int) ($incoming * 0.35) > $stats['quotaBytes']) {
            throw new \InvalidArgumentException('保存容量が不足しています（使用中 '.$stats['formattedUsed'].' / '.$stats['formattedQuota'].'）');
        }

        $created = [];
        $existingMin = Photo::query()->where('user_id', $userId)->min('sort_order');
        $nextOrder = $existingMin === null ? 0 : ((int) $existingMin - 10);

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $this->assertValidUpload($file);

            $dir = 'photos/'.$userId.'/'.now()->format('Y/m');
            $stored = $this->storeOptimizedImage($file, $dir);
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
            throw new \InvalidArgumentException('アップロードできる画像がありません');
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
        if ($file->getSize() > $this->maxUploadBytes()) {
            throw new \InvalidArgumentException('ファイルサイズは'.$this->formatBytes($this->maxUploadBytes()).'以下にしてください');
        }

        $mime = (string) $file->getMimeType();
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $okExt = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], true);
        if (! in_array($mime, self::ALLOWED_MIMES, true) && ! $okExt) {
            throw new \InvalidArgumentException('対応形式は JPEG / PNG / WebP / GIF / HEIC です');
        }
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

        return [
            'id' => $album->id,
            'name' => $album->name,
            'description' => $album->description,
            'coverPhotoId' => $album->cover_photo_id,
            'photoCount' => (int) ($album->photos_count ?? $album->photos()->count()),
            'coverUrl' => $cover ? $this->publicUrl($cover->thumb_path ?: $cover->path) : null,
            'sortOrder' => (int) $album->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    public function photoToArray(Photo $photo): array
    {
        $takenAt = $photo->taken_at?->format('Y-m-d H:i');
        $takenDate = $photo->taken_at?->format('Y-m-d');

        return [
            'id' => $photo->id,
            'albumId' => $photo->album_id,
            'url' => $this->publicUrl($photo->path),
            'thumbUrl' => $this->publicUrl($photo->thumb_path ?: $photo->path),
            'originalName' => $photo->original_name,
            'caption' => $photo->caption,
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
