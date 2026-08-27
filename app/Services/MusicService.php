<?php

namespace App\Services;

use App\Models\MusicLibrary;
use App\Models\MusicTrack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MusicService
{
    /** @var array<int, MusicLibrary> */
    private array $defaultLibraryCache = [];

    public function diskName(): string
    {
        return (string) config('music.disk', config('photos.disk', 'public'));
    }

    public function maxUploadBytes(): int
    {
        return max(1, (int) config('music.max_upload_bytes', 100 * 1024 * 1024));
    }

    /** @return list<array<string, mixed>> */
    public function listTracks(int $userId, ?int $libraryId = null): array
    {
        $library = $this->resolveLibrary($userId, $libraryId);

        return MusicTrack::query()
            ->where('user_id', $userId)
            ->where('music_library_id', $library->id)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MusicTrack $track) => $this->toArray($track))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function listLibraries(int $userId): array
    {
        $this->ensureDefaultLibrary($userId);

        return MusicLibrary::query()
            ->where('user_id', $userId)
            ->withCount('musicTracks')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MusicLibrary $lib) => $this->libraryToArray($lib))
            ->all();
    }

    public function ensureDefaultLibrary(int $userId): MusicLibrary
    {
        if (isset($this->defaultLibraryCache[$userId])) {
            return $this->defaultLibraryCache[$userId];
        }

        $library = MusicLibrary::query()
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->first();

        if (! $library) {
            $library = MusicLibrary::query()->create([
                'user_id' => $userId,
                'name' => __('マイリスト'),
                'is_default' => true,
                'sort_order' => 0,
            ]);
        }

        MusicTrack::query()
            ->where('user_id', $userId)
            ->whereNull('music_library_id')
            ->update(['music_library_id' => $library->id]);

        return $this->defaultLibraryCache[$userId] = $library;
    }

    public function findOwnedLibrary(int $userId, int $id): ?MusicLibrary
    {
        return MusicLibrary::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();
    }

    public function resolveLibrary(int $userId, ?int $libraryId): MusicLibrary
    {
        $default = $this->ensureDefaultLibrary($userId);
        if ($libraryId === null || $libraryId <= 0) {
            return $default;
        }

        return $this->findOwnedLibrary($userId, $libraryId) ?: $default;
    }

    /** @return array<string, mixed> */
    public function createLibrary(int $userId, string $name): array
    {
        $this->ensureDefaultLibrary($userId);
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ライブラリ名を入力してください。'));
        }
        if (mb_strlen($name) > 120) {
            throw new \InvalidArgumentException(__('ライブラリ名は120文字以内にしてください。'));
        }

        $dup = MusicLibrary::query()
            ->where('user_id', $userId)
            ->where('name', $name)
            ->exists();
        if ($dup) {
            throw new \InvalidArgumentException(__('同名のライブラリがすでにあります。'));
        }

        $sort = (int) MusicLibrary::query()->where('user_id', $userId)->max('sort_order') + 10;
        $library = MusicLibrary::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'is_default' => false,
            'sort_order' => $sort,
        ]);

        return $this->libraryToArray($library->loadCount('musicTracks'));
    }

    /** @return array<string, mixed> */
    public function renameLibrary(int $userId, int $id, string $name): array
    {
        $library = $this->findOwnedLibrary($userId, $id);
        if (! $library) {
            throw new \InvalidArgumentException(__('ライブラリが見つかりません。'));
        }
        if ($library->is_default) {
            throw new \InvalidArgumentException(__('マイリストの名前は変更できません。'));
        }

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ライブラリ名を入力してください。'));
        }

        $dup = MusicLibrary::query()
            ->where('user_id', $userId)
            ->where('name', $name)
            ->where('id', '!=', $library->id)
            ->exists();
        if ($dup) {
            throw new \InvalidArgumentException(__('同名のライブラリがすでにあります。'));
        }

        $library->name = mb_substr($name, 0, 120);
        $library->save();

        return $this->libraryToArray($library->loadCount('musicTracks'));
    }

    public function deleteLibrary(int $userId, int $id): bool
    {
        $library = $this->findOwnedLibrary($userId, $id);
        if (! $library) {
            return false;
        }
        if ($library->is_default) {
            throw new \InvalidArgumentException(__('マイリストは削除できません。'));
        }

        $default = $this->ensureDefaultLibrary($userId);
        MusicTrack::query()
            ->where('user_id', $userId)
            ->where('music_library_id', $library->id)
            ->update(['music_library_id' => $default->id]);

        return (bool) $library->delete();
    }

    /** @return array<string, mixed>|null */
    public function moveToLibrary(int $userId, int $trackId, ?int $libraryId): ?array
    {
        $track = $this->findOwned($userId, $trackId);
        if (! $track) {
            return null;
        }
        $library = $this->resolveLibrary($userId, $libraryId);
        $track->music_library_id = $library->id;
        $track->save();

        return $this->toArray($track);
    }

    /** @return array<string, mixed> */
    public function libraryToArray(MusicLibrary $library): array
    {
        return [
            'id' => $library->id,
            'name' => $library->name,
            'isDefault' => (bool) $library->is_default,
            'sortOrder' => (int) $library->sort_order,
            'trackCount' => (int) ($library->music_tracks_count ?? $library->musicTracks()->count()),
        ];
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
    public function addTracks(int $userId, array $files, ?string $title = null, ?int $libraryId = null): array
    {
        $created = [];
        $dir = 'music/'.$userId;
        $disk = Storage::disk($this->diskName());
        $max = $this->maxUploadBytes();
        $allowedMimes = config('music.allowed_mimes', []);
        $allowedExt = config('music.allowed_extensions', []);
        $library = $this->resolveLibrary($userId, $libraryId);
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
                'music_library_id' => $library->id,
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
            'libraryId' => $track->music_library_id,
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
