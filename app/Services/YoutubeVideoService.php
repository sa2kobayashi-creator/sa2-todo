<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use App\Models\VideoLibrary;
use App\Models\YoutubeVideo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YoutubeVideoService
{
    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_YOUTUBE);
    }

    public function apiKey(): string
    {
        $fromDb = trim((string) $this->configRow()->secret('api_key', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('youtube.data_api_key', ''));
    }

    public function isSearchReady(): bool
    {
        $row = $this->configRow();

        return $row->enabled && $this->apiKey() !== '';
    }

    /**
     * @param  array{api_key?: mixed}  $secrets
     */
    public function saveConfig(bool $enabled, array $secrets): MediaStorageSetting
    {
        $row = $this->configRow();
        $merged = $row->secretsArray();
        $key = is_string($secrets['api_key'] ?? null) ? trim($secrets['api_key']) : '';
        if ($key !== '' && $key !== '••••••••' && ! str_starts_with($key, '••••')) {
            $merged['api_key'] = $key;
        }

        $row->fill([
            'enabled' => $enabled,
            'settings' => $row->settingsArray(),
            'secrets' => $merged,
        ]);
        $row->save();

        return $row->fresh() ?? $row;
    }

    /** @return array{enabled: bool, api_key_masked: string, ready: bool, last_test_status: ?string, last_test_message: ?string, last_tested_at: ?string} */
    public function formState(): array
    {
        $row = $this->configRow();

        return [
            'enabled' => (bool) $row->enabled,
            'api_key_masked' => $row->maskedSecret('api_key'),
            'ready' => $this->isSearchReady(),
            'last_test_status' => $row->last_test_status,
            'last_test_message' => $row->last_test_message,
            'last_tested_at' => $row->last_tested_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            return ['ok' => false, 'message' => __('YouTube Data API キーを入力してください。')];
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get('https://www.googleapis.com/youtube/v3/search', [
                    'part' => 'snippet',
                    'type' => 'video',
                    'maxResults' => 1,
                    'q' => 'YouTube',
                    'key' => $key,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('接続に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 160)])];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => __('YouTube Data API への接続に成功しました。')];
        }

        $error = $response->json('error.message') ?? $response->body();

        return [
            'ok' => false,
            'message' => __('YouTube API エラー: :msg', ['msg' => mb_substr((string) $error, 0, 200)]),
        ];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = $this->configRow();
        $row->fill([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'ok' : 'fail',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
        $row->save();
    }

    /**
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   items?: list<array<string, mixed>>,
     *   nextPageToken?: string|null,
     *   prevPageToken?: string|null,
     *   totalResults?: int|null
     * }
     */
    public function search(string $query, ?string $pageToken = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['ok' => false, 'message' => __('検索キーワードを入力してください。')];
        }
        if (! $this->isSearchReady()) {
            return ['ok' => false, 'message' => __('YouTube検索が未設定です。設定 > AI設定 で Data API キーを有効化してください。')];
        }

        $params = [
            'part' => 'snippet',
            'type' => 'video',
            'maxResults' => max(1, min(25, (int) config('youtube.search_max_results', 12))),
            'q' => $query,
            'key' => $this->apiKey(),
            'safeSearch' => 'moderate',
        ];

        $region = trim((string) config('youtube.search_region_code', 'JP'));
        if ($region !== '') {
            $params['regionCode'] = $region;
        }
        $lang = trim((string) config('youtube.search_relevance_language', 'ja'));
        if ($lang !== '') {
            $params['relevanceLanguage'] = $lang;
        }
        if (is_string($pageToken) && $pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get('https://www.googleapis.com/youtube/v3/search', $params);
        } catch (\Throwable $e) {
            Log::warning('YouTube search failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => __('検索に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 160)])];
        }

        if (! $response->successful()) {
            $error = $response->json('error.message') ?? $response->body();

            return ['ok' => false, 'message' => __('YouTube API エラー: :msg', ['msg' => mb_substr((string) $error, 0, 200)])];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return ['ok' => false, 'message' => __('検索結果を取得できませんでした。')];
        }

        $items = [];
        foreach ($data['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $videoId = (string) data_get($item, 'id.videoId', '');
            if ($videoId === '' || ! preg_match('/^[A-Za-z0-9_-]{6,32}$/', $videoId)) {
                continue;
            }
            $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
            $thumbs = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
            $thumb = (string) (
                data_get($thumbs, 'medium.url')
                ?: data_get($thumbs, 'high.url')
                ?: data_get($thumbs, 'default.url')
                ?: ('https://i.ytimg.com/vi/'.$videoId.'/hqdefault.jpg')
            );
            $title = trim((string) ($snippet['title'] ?? ''));
            $channel = trim((string) ($snippet['channelTitle'] ?? ''));
            $published = (string) ($snippet['publishedAt'] ?? '');
            $items[] = [
                'youtubeId' => $videoId,
                'title' => $title !== '' ? html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') : ('YouTube '.$videoId),
                'channelTitle' => $channel !== '' ? html_entity_decode($channel, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
                'description' => mb_substr(trim((string) ($snippet['description'] ?? '')), 0, 240),
                'thumbUrl' => $thumb,
                'url' => 'https://www.youtube.com/watch?v='.$videoId,
                'embedUrl' => 'https://www.youtube.com/embed/'.$videoId.'?rel=0&modestbranding=1',
                'publishedAt' => $published !== '' ? substr($published, 0, 10) : '',
            ];
        }

        return [
            'ok' => true,
            'items' => $items,
            'nextPageToken' => is_string($data['nextPageToken'] ?? null) ? $data['nextPageToken'] : null,
            'prevPageToken' => is_string($data['prevPageToken'] ?? null) ? $data['prevPageToken'] : null,
            'totalResults' => isset($data['pageInfo']['totalResults']) ? (int) $data['pageInfo']['totalResults'] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLibraries(int $userId): array
    {
        $this->ensureDefaultLibrary($userId);

        return VideoLibrary::query()
            ->where('user_id', $userId)
            ->withCount('youtubeVideos')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (VideoLibrary $lib) => $this->libraryToArray($lib))
            ->all();
    }

    public function ensureDefaultLibrary(int $userId): VideoLibrary
    {
        $existing = VideoLibrary::query()
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->first();
        if ($existing) {
            YoutubeVideo::query()
                ->where('user_id', $userId)
                ->whereNull('video_library_id')
                ->update(['video_library_id' => $existing->id]);

            return $existing;
        }

        $library = VideoLibrary::query()->create([
            'user_id' => $userId,
            'name' => __('マイリスト'),
            'is_default' => true,
            'sort_order' => 0,
        ]);

        YoutubeVideo::query()
            ->where('user_id', $userId)
            ->whereNull('video_library_id')
            ->update(['video_library_id' => $library->id]);

        return $library;
    }

    public function findOwnedLibrary(int $userId, int $id): ?VideoLibrary
    {
        return VideoLibrary::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();
    }

    public function resolveLibrary(int $userId, ?int $libraryId): VideoLibrary
    {
        $default = $this->ensureDefaultLibrary($userId);
        if ($libraryId === null || $libraryId <= 0) {
            return $default;
        }
        $library = $this->findOwnedLibrary($userId, $libraryId);

        return $library ?: $default;
    }

    /**
     * @return array<string, mixed>
     */
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

        $dup = VideoLibrary::query()
            ->where('user_id', $userId)
            ->where('name', $name)
            ->exists();
        if ($dup) {
            throw new \InvalidArgumentException(__('同名のライブラリがすでにあります。'));
        }

        $sort = (int) VideoLibrary::query()->where('user_id', $userId)->max('sort_order') + 10;
        $library = VideoLibrary::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'is_default' => false,
            'sort_order' => $sort,
        ]);

        return $this->libraryToArray($library->loadCount('youtubeVideos'));
    }

    /**
     * @return array<string, mixed>
     */
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

        $dup = VideoLibrary::query()
            ->where('user_id', $userId)
            ->where('name', $name)
            ->where('id', '!=', $library->id)
            ->exists();
        if ($dup) {
            throw new \InvalidArgumentException(__('同名のライブラリがすでにあります。'));
        }

        $library->name = mb_substr($name, 0, 120);
        $library->save();

        return $this->libraryToArray($library->loadCount('youtubeVideos'));
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
        YoutubeVideo::query()
            ->where('user_id', $userId)
            ->where('video_library_id', $library->id)
            ->update(['video_library_id' => $default->id]);

        return (bool) $library->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId, ?int $libraryId = null): array
    {
        $library = $this->resolveLibrary($userId, $libraryId);

        return YoutubeVideo::query()
            ->where('user_id', $userId)
            ->where('video_library_id', $library->id)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (YoutubeVideo $video) => $this->toArray($video))
            ->all();
    }

    public function findOwned(int $userId, int $id): ?YoutubeVideo
    {
        return YoutubeVideo::query()
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function addFromUrl(int $userId, string $rawUrl, ?string $title = null, ?int $libraryId = null): array
    {
        $youtubeId = $this->extractVideoId($rawUrl);
        if ($youtubeId === null) {
            throw new \InvalidArgumentException(__('YouTubeのURLを認識できませんでした。'));
        }

        return $this->addFromVideoId($userId, $youtubeId, $title, null, $libraryId);
    }

    /**
     * @return array<string, mixed>
     */
    public function addFromVideoId(
        int $userId,
        string $youtubeId,
        ?string $title = null,
        ?string $thumbnailUrl = null,
        ?int $libraryId = null,
    ): array {
        $youtubeId = trim($youtubeId);
        if (! preg_match('/^[A-Za-z0-9_-]{6,32}$/', $youtubeId)) {
            throw new \InvalidArgumentException(__('YouTubeのURLを認識できませんでした。'));
        }

        $library = $this->resolveLibrary($userId, $libraryId);

        $existing = YoutubeVideo::query()
            ->where('user_id', $userId)
            ->where('youtube_id', $youtubeId)
            ->first();
        if ($existing) {
            if ((int) $existing->video_library_id !== (int) $library->id) {
                $existing->video_library_id = $library->id;
                $existing->save();
            }

            return $this->toArray($existing->fresh() ?? $existing);
        }

        $canonical = 'https://www.youtube.com/watch?v='.$youtubeId;
        $meta = $this->fetchOEmbed($canonical);
        $sortOrder = (int) YoutubeVideo::query()
            ->where('user_id', $userId)
            ->where('video_library_id', $library->id)
            ->max('sort_order') + 10;

        $video = YoutubeVideo::query()->create([
            'user_id' => $userId,
            'video_library_id' => $library->id,
            'youtube_id' => $youtubeId,
            'title' => $this->resolveTitle($title, $meta['title'] ?? null, $youtubeId),
            'url' => $canonical,
            'thumbnail_url' => $thumbnailUrl
                ?: ($meta['thumbnail_url'] ?? null)
                ?: ('https://i.ytimg.com/vi/'.$youtubeId.'/hqdefault.jpg'),
            'sort_order' => $sortOrder,
        ]);

        return $this->toArray($video);
    }

    public function moveToLibrary(int $userId, int $videoId, int $libraryId): ?array
    {
        $video = $this->findOwned($userId, $videoId);
        if (! $video) {
            return null;
        }
        $library = $this->resolveLibrary($userId, $libraryId);
        $video->video_library_id = $library->id;
        $video->save();

        return $this->toArray($video);
    }

    public function delete(int $userId, int $id): bool
    {
        $video = $this->findOwned($userId, $id);
        if (! $video) {
            return false;
        }

        return (bool) $video->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function libraryToArray(VideoLibrary $library): array
    {
        return [
            'id' => $library->id,
            'name' => $library->name,
            'isDefault' => (bool) $library->is_default,
            'sortOrder' => (int) $library->sort_order,
            'videoCount' => (int) ($library->youtube_videos_count ?? $library->youtubeVideos()->count()),
        ];
    }

    public function extractVideoId(string $rawUrl): ?string
    {
        $url = trim($rawUrl);
        if ($url === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        $path = (string) ($parts['path'] ?? '');
        $query = [];
        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        $id = null;
        if (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
            if (! empty($query['v']) && is_string($query['v'])) {
                $id = $query['v'];
            } elseif (preg_match('#^/(embed|shorts|live|v)/([A-Za-z0-9_-]{6,})#', $path, $m)) {
                $id = $m[2];
            }
        } elseif ($host === 'youtu.be') {
            if (preg_match('#^/([A-Za-z0-9_-]{6,})#', $path, $m)) {
                $id = $m[1];
            }
        }

        if ($id === null) {
            return null;
        }

        $id = strtok($id, '?&') ?: $id;
        if (! preg_match('/^[A-Za-z0-9_-]{6,32}$/', $id)) {
            return null;
        }

        return $id;
    }

    /**
     * @return array{title?: string, thumbnail_url?: string}
     */
    public function fetchOEmbed(string $url): array
    {
        try {
            $response = Http::timeout(6)
                ->acceptJson()
                ->get('https://www.youtube.com/oembed', [
                    'url' => $url,
                    'format' => 'json',
                ]);
            if (! $response->successful()) {
                return [];
            }
            $data = $response->json();
            if (! is_array($data)) {
                return [];
            }

            return [
                'title' => is_string($data['title'] ?? null) ? $data['title'] : null,
                'thumbnail_url' => is_string($data['thumbnail_url'] ?? null) ? $data['thumbnail_url'] : null,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(YoutubeVideo $video): array
    {
        return [
            'id' => $video->id,
            'source' => 'youtube',
            'libraryId' => $video->video_library_id,
            'youtubeId' => $video->youtube_id,
            'title' => $video->title ?: __('YouTube動画'),
            'url' => $video->url,
            'embedUrl' => 'https://www.youtube.com/embed/'.$video->youtube_id.'?rel=0&modestbranding=1',
            'thumbUrl' => $video->thumbnail_url
                ?: 'https://i.ytimg.com/vi/'.$video->youtube_id.'/hqdefault.jpg',
            'createdAt' => $video->created_at?->format('Y-m-d H:i'),
        ];
    }

    private function resolveTitle(?string $input, ?string $oembed, string $youtubeId): string
    {
        $input = is_string($input) ? trim($input) : '';
        if ($input !== '') {
            return mb_substr($input, 0, 255);
        }
        if (is_string($oembed) && trim($oembed) !== '') {
            return mb_substr(trim($oembed), 0, 255);
        }

        return 'YouTube '.$youtubeId;
    }
}
