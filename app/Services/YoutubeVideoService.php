<?php

namespace App\Services;

use App\Models\MediaStorageSetting;
use App\Models\VideoLibrary;
use App\Models\YoutubeVideo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YoutubeVideoService
{
    /** @var array<int, VideoLibrary> */
    private array $defaultLibraryCache = [];

    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_YOUTUBE);
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
        if ($this->apiKey() === '') {
            return false;
        }
        $row = $this->configRow();
        $dbKey = trim((string) $row->secret('api_key', ''));
        if ($dbKey !== '' && ! $row->enabled) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{api_key?: mixed}  $secrets
     */
    public function saveConfig(bool $enabled, array $secrets): MediaStorageSetting
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_YOUTUBE);
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
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_YOUTUBE);

        $dbKey = trim((string) $row->secret('api_key', ''));
        $enabled = (bool) $row->enabled;
        if (! $enabled && $dbKey === '' && $this->apiKey() !== '') {
            $enabled = true;
        }

        return [
            'enabled' => $enabled,
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
            'message' => $this->googleApiErrorMessage($error),
        ];
    }

    public function recordTestResult(bool $ok, string $message): void
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_YOUTUBE);
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

        $direct = $this->previewFromInput($query);
        if ($direct !== null) {
            return [
                'ok' => true,
                'direct' => true,
                'items' => [$direct],
                'nextPageToken' => null,
                'prevPageToken' => null,
                'totalResults' => 1,
            ];
        }

        if (! $this->isSearchReady()) {
            return [
                'ok' => false,
                'message' => __('キーワード検索には YouTube Data API キーが必要です。YouTube や TikTok のURLを貼るか、設定 → API設定 でキーを有効にしてください。'),
            ];
        }

        $params = [
            'part' => 'snippet',
            'type' => 'video',
            'videoEmbeddable' => 'true',
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

            return ['ok' => false, 'message' => $this->googleApiErrorMessage($error)];
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
            $items[] = $this->youtubeResultItem($videoId, $snippet, $thumb);
        }

        app(IntegrationUsageService::class)->increment('youtube');

        return [
            'ok' => true,
            'items' => $items,
            'nextPageToken' => is_string($data['nextPageToken'] ?? null) ? $data['nextPageToken'] : null,
            'prevPageToken' => is_string($data['prevPageToken'] ?? null) ? $data['prevPageToken'] : null,
            'totalResults' => isset($data['pageInfo']['totalResults']) ? (int) $data['pageInfo']['totalResults'] : null,
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   items?: list<array<string, mixed>>
     * }
     */
    public function popularVideos(): array
    {
        if (! $this->isSearchReady()) {
            return [
                'ok' => false,
                'message' => __('キーワード検索には YouTube Data API キーが必要です。YouTube や TikTok のURLを貼るか、設定 → API設定 でキーを有効にしてください。'),
                'items' => [],
            ];
        }

        $region = trim((string) config('youtube.search_region_code', 'JP')) ?: 'JP';
        $cacheKey = 'youtube.popular.'.$region;
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached['ok'])) {
            return $cached;
        }

        $params = [
            'part' => 'snippet,status',
            'chart' => 'mostPopular',
            'maxResults' => max(1, min(16, (int) config('youtube.search_max_results', 12))),
            'regionCode' => $region,
            'key' => $this->apiKey(),
        ];

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get('https://www.googleapis.com/youtube/v3/videos', $params);
        } catch (\Throwable $e) {
            Log::warning('YouTube popular failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => __('検索に失敗しました: :msg', ['msg' => mb_substr($e->getMessage(), 0, 160)]), 'items' => []];
        }

        if (! $response->successful()) {
            $error = $response->json('error.message') ?? $response->body();

            return [
                'ok' => false,
                'message' => $this->googleApiErrorMessage($error),
                'items' => [],
            ];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return ['ok' => false, 'message' => __('検索結果を取得できませんでした。'), 'items' => []];
        }

        $items = [];
        foreach ($data['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $videoId = (string) ($item['id'] ?? '');
            if ($videoId === '' || ! preg_match('/^[A-Za-z0-9_-]{6,32}$/', $videoId)) {
                continue;
            }
            $status = is_array($item['status'] ?? null) ? $item['status'] : [];
            if (array_key_exists('embeddable', $status) && $status['embeddable'] === false) {
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
            $items[] = $this->youtubeResultItem($videoId, $snippet, $thumb);
        }

        app(IntegrationUsageService::class)->increment('youtube');

        $result = ['ok' => true, 'items' => $items];
        Cache::put($cacheKey, $result, 1800);

        return $result;
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
        if (isset($this->defaultLibraryCache[$userId])) {
            return $this->defaultLibraryCache[$userId];
        }

        $existing = VideoLibrary::query()
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->first();
        if ($existing) {
            $hasOrphans = YoutubeVideo::query()
                ->where('user_id', $userId)
                ->whereNull('video_library_id')
                ->exists();
            if ($hasOrphans) {
                YoutubeVideo::query()
                    ->where('user_id', $userId)
                    ->whereNull('video_library_id')
                    ->update(['video_library_id' => $existing->id]);
            }

            return $this->defaultLibraryCache[$userId] = $existing;
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

        return $this->defaultLibraryCache[$userId] = $library;
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
        $preview = $this->previewFromInput($rawUrl);
        if ($preview === null) {
            throw new \InvalidArgumentException(__('YouTube または TikTok のURLを認識できませんでした。'));
        }

        $source = (string) ($preview['source'] ?? YoutubeVideo::PROVIDER_YOUTUBE);

        return $this->addFromVideoId(
            $userId,
            (string) $preview['youtubeId'],
            $title !== null && trim($title) !== '' ? $title : (string) ($preview['title'] ?? ''),
            is_string($preview['thumbUrl'] ?? null) ? (string) $preview['thumbUrl'] : null,
            $libraryId,
            $source === YoutubeVideo::PROVIDER_TIKTOK ? YoutubeVideo::PROVIDER_TIKTOK : YoutubeVideo::PROVIDER_YOUTUBE
        );
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
        string $provider = YoutubeVideo::PROVIDER_YOUTUBE,
    ): array {
        $youtubeId = trim($youtubeId);
        $provider = $provider === YoutubeVideo::PROVIDER_TIKTOK
            ? YoutubeVideo::PROVIDER_TIKTOK
            : YoutubeVideo::PROVIDER_YOUTUBE;
        if ($provider === YoutubeVideo::PROVIDER_TIKTOK) {
            if (! preg_match('/^\d{5,32}$/', $youtubeId)) {
                throw new \InvalidArgumentException(__('TikTokのURLを認識できませんでした。'));
            }
        } elseif (! preg_match('/^[A-Za-z0-9_-]{6,32}$/', $youtubeId)) {
            throw new \InvalidArgumentException(__('YouTubeのURLを認識できませんでした。'));
        }

        $library = $this->resolveLibrary($userId, $libraryId);

        $existing = YoutubeVideo::query()
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->where('youtube_id', $youtubeId)
            ->first();
        if ($existing) {
            if ((int) $existing->video_library_id !== (int) $library->id) {
                $existing->video_library_id = $library->id;
                $existing->save();
            }

            return $this->toArray($existing->fresh() ?? $existing);
        }

        $canonical = $provider === YoutubeVideo::PROVIDER_TIKTOK
            ? 'https://www.tiktok.com/video/'.$youtubeId
            : 'https://www.youtube.com/watch?v='.$youtubeId;
        $meta = $provider === YoutubeVideo::PROVIDER_TIKTOK
            ? $this->fetchTikTokOEmbed('https://www.tiktok.com/@tiktok/video/'.$youtubeId)
            : $this->fetchOEmbed($canonical);
        $sortOrder = (int) YoutubeVideo::query()
            ->where('user_id', $userId)
            ->where('video_library_id', $library->id)
            ->max('sort_order') + 10;

        $fallbackThumb = $provider === YoutubeVideo::PROVIDER_TIKTOK
            ? null
            : ('https://i.ytimg.com/vi/'.$youtubeId.'/hqdefault.jpg');
        $fallbackTitle = $provider === YoutubeVideo::PROVIDER_TIKTOK
            ? ('TikTok '.$youtubeId)
            : ('YouTube '.$youtubeId);

        $video = YoutubeVideo::query()->create([
            'user_id' => $userId,
            'video_library_id' => $library->id,
            'provider' => $provider,
            'youtube_id' => $youtubeId,
            'title' => $this->resolveTitle($title, $meta['title'] ?? null, $fallbackTitle),
            'url' => $canonical,
            'thumbnail_url' => $thumbnailUrl
                ?: ($meta['thumbnail_url'] ?? null)
                ?: $fallbackThumb,
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
        if (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'], true)) {
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
        $provider = (string) ($video->provider ?: YoutubeVideo::PROVIDER_YOUTUBE);
        $isTiktok = $provider === YoutubeVideo::PROVIDER_TIKTOK;
        $id = (string) $video->youtube_id;

        return [
            'id' => $video->id,
            'source' => $isTiktok ? YoutubeVideo::PROVIDER_TIKTOK : YoutubeVideo::PROVIDER_YOUTUBE,
            'libraryId' => $video->video_library_id,
            'youtubeId' => $id,
            'title' => $video->title ?: ($isTiktok ? __('TikTok動画') : __('YouTube動画')),
            'url' => $video->url,
            'embedUrl' => $isTiktok ? $this->tiktokEmbedUrlFor($id) : $this->embedUrlFor($id),
            'thumbUrl' => $video->thumbnail_url
                ?: ($isTiktok ? null : ('https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg')),
            'createdAt' => $video->created_at?->format('Y-m-d H:i'),
        ];
    }

    public function embedUrlFor(string $youtubeId, bool $autoplay = false): string
    {
        $params = [
            'rel' => '0',
            'playsinline' => '1',
            'controls' => '1',
            'fs' => '1',
        ];
        if ($autoplay) {
            $params['autoplay'] = '1';
        }

        return 'https://www.youtube.com/embed/'.$youtubeId.'?'.http_build_query($params);
    }

    public function tiktokEmbedUrlFor(string $tiktokId, bool $autoplay = false): string
    {
        $params = [
            'music_info' => '0',
            'description' => '0',
            'loop' => '0',
        ];
        if ($autoplay) {
            $params['autoplay'] = '1';
        }

        return 'https://www.tiktok.com/player/v1/'.$tiktokId.'?'.http_build_query($params);
    }

    /**
     * URL や短縮リンクから、API なしで再生できる1件を返す。
     *
     * @return array<string, mixed>|null
     */
    public function previewFromInput(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $youtubeId = $this->extractVideoId($raw);
        if ($youtubeId !== null) {
            $url = 'https://www.youtube.com/watch?v='.$youtubeId;
            $meta = $this->fetchOEmbed($url);
            $title = trim((string) ($meta['title'] ?? ''));

            return [
                'source' => YoutubeVideo::PROVIDER_YOUTUBE,
                'youtubeId' => $youtubeId,
                'title' => $title !== '' ? $title : ('YouTube '.$youtubeId),
                'channelTitle' => '',
                'description' => '',
                'thumbUrl' => $meta['thumbnail_url'] ?? ('https://i.ytimg.com/vi/'.$youtubeId.'/hqdefault.jpg'),
                'url' => $url,
                'embedUrl' => $this->embedUrlFor($youtubeId),
                'publishedAt' => '',
            ];
        }

        if (! $this->looksLikeUrl($raw)) {
            return null;
        }
        $url = $this->normalizeWatchUrl($raw);
        if ($url === null) {
            return null;
        }
        $tiktokId = $this->extractTikTokVideoId($url);
        if ($tiktokId === null && $this->isTikTokShortUrl($url)) {
            $url = $this->followAllowedRedirects($url);
            $tiktokId = $this->extractTikTokVideoId($url);
        }
        if ($tiktokId === null) {
            return null;
        }

        $canonical = 'https://www.tiktok.com/@tiktok/video/'.$tiktokId;
        $meta = $this->fetchTikTokOEmbed($canonical);
        $title = trim((string) ($meta['title'] ?? ''));

        return [
            'source' => YoutubeVideo::PROVIDER_TIKTOK,
            'youtubeId' => $tiktokId,
            'title' => $title !== '' ? $title : ('TikTok '.$tiktokId),
            'channelTitle' => is_string($meta['author_name'] ?? null) ? (string) $meta['author_name'] : 'TikTok',
            'description' => '',
            'thumbUrl' => $meta['thumbnail_url'] ?? null,
            'url' => $canonical,
            'embedUrl' => $this->tiktokEmbedUrlFor($tiktokId),
            'publishedAt' => '',
        ];
    }

    public function extractTikTokVideoId(string $rawUrl): ?string
    {
        $url = $this->normalizeWatchUrl($rawUrl);
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        if (! $this->isAllowedWatchHost((string) $parts['host'])) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#/@[^/]+/video/(\d{5,32})#', $path, $m)
            || preg_match('#^/video/(\d{5,32})#', $path, $m)
            || preg_match('#^/v/(\d{5,32})#', $path, $m)
            || preg_match('#/(?:embed/v2|player/v1)/(\d{5,32})#', $path, $m)
        ) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snippet
     * @return array<string, mixed>
     */
    private function youtubeResultItem(string $videoId, array $snippet, string $thumb): array
    {
        $title = trim((string) ($snippet['title'] ?? ''));
        $channel = trim((string) ($snippet['channelTitle'] ?? ''));
        $published = (string) ($snippet['publishedAt'] ?? '');

        return [
            'source' => YoutubeVideo::PROVIDER_YOUTUBE,
            'youtubeId' => $videoId,
            'title' => $title !== '' ? html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') : ('YouTube '.$videoId),
            'channelTitle' => $channel !== '' ? html_entity_decode($channel, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
            'description' => mb_substr(trim((string) ($snippet['description'] ?? '')), 0, 240),
            'thumbUrl' => $thumb,
            'url' => 'https://www.youtube.com/watch?v='.$videoId,
            'embedUrl' => $this->embedUrlFor($videoId),
            'publishedAt' => $published !== '' ? substr($published, 0, 10) : '',
        ];
    }

    /**
     * @return array{title?: string, thumbnail_url?: string, author_name?: string}
     */
    public function fetchTikTokOEmbed(string $url): array
    {
        try {
            $response = Http::timeout(6)
                ->acceptJson()
                ->get('https://www.tiktok.com/oembed', ['url' => $url]);
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
                'author_name' => is_string($data['author_name'] ?? null) ? $data['author_name'] : null,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    private function isTikTokShortUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        $path = (string) ($parts['path'] ?? '');
        if (in_array($host, ['vm.tiktok.com', 'vt.tiktok.com'], true)) {
            return true;
        }

        return $host === 'tiktok.com' && (bool) preg_match('#^/t/#', $path);
    }

    private function googleApiErrorMessage(mixed $error): string
    {
        $raw = trim(is_string($error) ? $error : '');
        if ($raw === '') {
            return __('YouTube API エラー: :msg', ['msg' => __('応答を解析できませんでした。')]);
        }
        $lower = strtolower($raw);
        if (str_contains($lower, 'v3datasearchservice.list') && str_contains($lower, 'blocked')) {
            return __('このAPIキーでは YouTube のキーワード検索が許可されていません。Google Cloud で YouTube Data API v3 を有効にし、キーの「API の制限」に YouTube Data API v3 を入れてください。メソッド制限がある場合は search.list を許可します。サーバーから呼ぶため HTTP リファラ制限は使わず、Maps 用キーとは分けてください。');
        }
        if (str_contains($lower, 'referer restrictions') || str_contains($lower, 'referrer restrictions')) {
            return __('このAPIキーはウェブサイト（HTTPリファラ）制限付きです。動画検索はサーバーから呼ぶため、制限なし、またはサーバーの IP 制限にしてください。');
        }
        if (str_contains($lower, 'has not been used') || (str_contains($lower, 'youtube data api') && str_contains($lower, 'disabled'))) {
            return __('この Google Cloud プロジェクトで YouTube Data API v3 が有効になっていません。API ライブラリから有効化してください。');
        }
        if (str_contains($lower, 'quota') || str_contains($lower, 'rate limit')) {
            return __('YouTube API の当日クォータを使い切りました。翌日まで待つか、Cloud コンソールでクォータを確認してください。');
        }

        return __('YouTube API エラー: :msg', ['msg' => mb_substr($raw, 0, 200)]);
    }

    private function looksLikeUrl(string $value): bool
    {
        if (preg_match('#^https?://#i', $value)) {
            return true;
        }
        if (str_contains($value, ' ') || ! str_contains($value, '.')) {
            return false;
        }

        return (bool) preg_match('#^(www\.)?(youtube\.com|youtu\.be|m\.youtube\.com|tiktok\.com|vm\.tiktok\.com|vt\.tiktok\.com|m\.tiktok\.com)/#i', $value);
    }

    private function normalizeWatchUrl(string $raw): ?string
    {
        $url = trim($raw);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || ! $this->isAllowedWatchHost((string) $parts['host'])) {
            return null;
        }

        return $url;
    }

    private function isAllowedWatchHost(string $host): bool
    {
        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return in_array($host, [
            'youtube.com',
            'm.youtube.com',
            'music.youtube.com',
            'youtu.be',
            'youtube-nocookie.com',
            'tiktok.com',
            'm.tiktok.com',
            'vm.tiktok.com',
            'vt.tiktok.com',
        ], true);
    }

    private function followAllowedRedirects(string $url, int $maxHops = 5): string
    {
        $current = $url;
        for ($i = 0; $i < $maxHops; $i++) {
            if ($this->normalizeWatchUrl($current) === null) {
                break;
            }
            try {
                $response = Http::timeout(8)
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Sa2Plus/1.0)'])
                    ->get($current);
            } catch (\Throwable) {
                break;
            }
            $status = $response->status();
            if ($status < 300 || $status >= 400) {
                return $current;
            }
            $location = $response->header('Location');
            if (! is_string($location) || $location === '') {
                return $current;
            }
            $next = $this->absolutizeUrl($current, $location);
            if ($this->normalizeWatchUrl($next) === null) {
                return $current;
            }
            $current = $next;
        }

        return $current;
    }

    private function absolutizeUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        if (! is_array($parts) || empty($parts['host'])) {
            return $location;
        }
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) $parts['host'];
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$location;
        }

        return $scheme.'://'.$host.'/'.$location;
    }

    private function resolveTitle(?string $input, ?string $oembed, string $fallback): string
    {
        $input = is_string($input) ? trim($input) : '';
        if ($input !== '') {
            return mb_substr($input, 0, 255);
        }
        if (is_string($oembed) && trim($oembed) !== '') {
            return mb_substr(trim($oembed), 0, 255);
        }

        return mb_substr($fallback, 0, 255);
    }
}
