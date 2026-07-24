<?php

namespace App\Services;

use App\Models\MusicTrack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MusicService
{
    public function diskName(): string
    {
        return (string) config('music.disk', config('photos.disk', 'public'));
    }

    public function maxUploadBytes(): int
    {
        return max(1, (int) config('music.max_upload_bytes', 100 * 1024 * 1024));
    }

    /** @return list<array<string, mixed>> */
    public function listTracks(int $userId): array
    {
        return MusicTrack::query()
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MusicTrack $track) => $this->toArray($track))
            ->all();
    }

    public function findOwned(int $userId, int $id): ?MusicTrack
    {
        return MusicTrack::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();
    }

    /**
     * @param  list<UploadedFile|null>  $files
     * @return list<array<string, mixed>>
     */
    public function addTracks(int $userId, array $files, ?string $title = null): array
    {
        $created = [];
        $dir = 'music/'.$userId;
        $disk = Storage::disk($this->diskName());
        $max = $this->maxUploadBytes();
        $allowedMimes = config('music.allowed_mimes', []);
        $allowedExt = config('music.allowed_extensions', []);
        $sortBase = (int) MusicTrack::query()->where('user_id', $userId)->max('sort_order');

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
            $mime = strtolower((string) ($file->getMimeType() ?: ''));
            $okMime = $mime !== '' && (
                str_starts_with($mime, 'audio/')
                || in_array($mime, $allowedMimes, true)
            );
            $okExt = $ext !== '' && in_array($ext, $allowedExt, true);
            if (! $okMime && ! $okExt) {
                throw new \InvalidArgumentException(__('対応していない音声形式です（mp3 / m4a / wav など）。'));
            }
            if ($file->getSize() > $max) {
                throw new \InvalidArgumentException(__('音声ファイルは :size 以下にしてください。', [
                    'size' => $this->formatBytes($max),
                ]));
            }

            $basename = uniqid('trk_', true).($ext !== '' ? '.'.$ext : '.mp3');
            $path = $disk->putFileAs($dir, $file, $basename, [
                'visibility' => 'private',
                'ContentType' => $mime !== '' ? $mime : 'audio/mpeg',
            ]);
            if (! is_string($path) || $path === '') {
                throw new \RuntimeException(__('音声の保存に失敗しました。'));
            }

            $original = (string) $file->getClientOriginalName();
            $trackTitle = trim((string) ($title ?? ''));
            if ($trackTitle === '') {
                $trackTitle = pathinfo($original, PATHINFO_FILENAME) ?: __('無題');
            }

            $sortBase += 10;
            $track = MusicTrack::query()->create([
                'user_id' => $userId,
                'title' => mb_substr($trackTitle, 0, 200),
                'original_name' => mb_substr($original, 0, 255),
                'path' => $path,
                'mime' => $mime !== '' ? $mime : 'audio/mpeg',
                'size_bytes' => (int) $file->getSize(),
                'duration_seconds' => null,
                'sort_order' => $sortBase,
            ]);
            $created[] = $this->toArray($track);
        }

        if ($created === []) {
            throw new \InvalidArgumentException(__('アップロードする音声ファイルを選択してください。'));
        }

        return $created;
    }

    public function deleteTrack(int $userId, int $id): bool
    {
        $track = $this->findOwned($userId, $id);
        if (! $track) {
            return false;
        }

        $disk = Storage::disk($this->diskName());
        if ($track->path && $disk->exists($track->path)) {
            $disk->delete($track->path);
        }
        $track->delete();

        return true;
    }

    public function stream(int $userId, int $id): StreamedResponse
    {
        $track = $this->findOwned($userId, $id);
        if (! $track) {
            abort(404);
        }

        $disk = Storage::disk($this->diskName());
        if (! $track->path || ! $disk->exists($track->path)) {
            abort(404);
        }

        $mime = $track->mime ?: 'audio/mpeg';
        $filename = $track->original_name ?: ($track->title.'.mp3');

        return $disk->response($track->path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(MusicTrack $track): array
    {
        return [
            'id' => $track->id,
            'title' => $track->title,
            'originalName' => $track->original_name,
            'mime' => $track->mime,
            'sizeBytes' => $track->size_bytes,
            'sizeLabel' => $this->formatBytes((int) $track->size_bytes),
            'durationSeconds' => $track->duration_seconds,
            'fileUrl' => '/music/'.$track->id.'/file',
            'createdAt' => optional($track->created_at)?->format('Y-m-d H:i'),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.').' KB';
        }

        return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.').' MB';
    }
}
