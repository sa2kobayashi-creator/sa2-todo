<?php

namespace App\Services;

use App\Models\Photo;
use App\Models\PhotoAlbum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PhotoService
{
    public const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;

    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/heif',
    ];

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

        $created = [];
        $existingMin = Photo::query()->where('user_id', $userId)->min('sort_order');
        $nextOrder = $existingMin === null ? 0 : ((int) $existingMin - 10);

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $this->assertValidUpload($file);

            $dir = 'photos/'.$userId.'/'.now()->format('Y/m');
            $path = $file->store($dir, 'public');
            if (! $path) {
                continue;
            }

            $size = @getimagesize($file->getRealPath());
            $width = is_array($size) ? ($size[0] ?? null) : null;
            $height = is_array($size) ? ($size[1] ?? null) : null;
            $thumbPath = $this->makeThumbnail($path, $file);

            $photo = Photo::create([
                'user_id' => $userId,
                'album_id' => $albumId,
                'path' => $path,
                'thumb_path' => $thumbPath,
                'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'mime' => $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'width' => $width,
                'height' => $height,
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
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
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
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('ファイルサイズは12MB以下にしてください');
        }

        $mime = (string) $file->getMimeType();
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $okExt = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], true);
        if (! in_array($mime, self::ALLOWED_MIMES, true) && ! $okExt) {
            throw new \InvalidArgumentException('対応形式は JPEG / PNG / WebP / GIF / HEIC です');
        }
    }

    private function makeThumbnail(string $storedPath, UploadedFile $file): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $sourcePath = $file->getRealPath();
        if (! $sourcePath) {
            return null;
        }

        $mime = (string) $file->getMimeType();
        $src = match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => @imagecreatefromjpeg($sourcePath),
            str_contains($mime, 'png') => @imagecreatefrompng($sourcePath),
            str_contains($mime, 'webp') && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($sourcePath),
            str_contains($mime, 'gif') => @imagecreatefromgif($sourcePath),
            default => false,
        };
        if (! $src) {
            return null;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            imagedestroy($src);

            return null;
        }

        $max = 720;
        $scale = min(1, $max / max($sw, $sh));
        $tw = max(1, (int) round($sw * $scale));
        $th = max(1, (int) round($sh * $scale));
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);

        $thumbRel = preg_replace('/(\.[^.]+)$/', '_thumb.jpg', $storedPath) ?: ($storedPath.'_thumb.jpg');
        $abs = Storage::disk('public')->path($thumbRel);
        @mkdir(dirname($abs), 0775, true);
        imagejpeg($dst, $abs, 82);
        imagedestroy($src);
        imagedestroy($dst);

        return Storage::disk('public')->exists($thumbRel) ? $thumbRel : null;
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

        return Storage::disk('public')->url($path);
    }
}
