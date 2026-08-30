<?php

namespace App\Http\Controllers;

use App\Exceptions\UsageLimitExceededException;
use App\Services\PhotoService;
use App\Services\UserUsageLimitService;
use App\Services\YoutubeVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public const LIBRARY_PAGE_SIZE = 20;

    public function __construct(
        private PhotoService $photos,
        private YoutubeVideoService $youtube,
    ) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $libraries = $this->youtube->listLibraries($userId);
        $defaultId = (int) collect($libraries)->firstWhere('isDefault', true)['id'];
        $libraryId = (int) ($request->query('library') ?: $defaultId);
        $current = collect($libraries)->firstWhere('id', $libraryId);
        if (! $current) {
            $libraryId = $defaultId;
            $current = collect($libraries)->firstWhere('id', $libraryId);
        }

        $youtube = $this->youtube->listForUser($userId, $libraryId);
        $playlist = [];
        foreach ($youtube as $item) {
            $playlist[] = $item;
        }

        // 件数は常に COUNT。一覧はマイリスト時のみ（全写真ロードはしない）
        $uploadCount = $this->photos->countVideosForUser($userId);

        // アップロード動画はマイリスト（デフォルト）にのみ表示
        if (! empty($current['isDefault']) && $uploadCount > 0) {
            foreach ($this->photos->listVideos($userId) as $video) {
                $playlist[] = [
                    'id' => $video['id'],
                    'source' => 'upload',
                    'title' => $video['caption'] ?: ($video['originalName'] ?: __('動画')),
                    'url' => $video['fileUrl'] ?? ('/photos/'.$video['id'].'/file'),
                    'embedUrl' => null,
                    'thumbUrl' => $video['thumbUrl'] ?? null,
                    'meta' => $video['takenAt'] ?? '',
                    'photoId' => $video['id'],
                ];
            }
        }

        // マイリスト件数にアップロード動画を含める
        if ($uploadCount > 0) {
            $libraries = array_map(static function (array $lib) use ($uploadCount): array {
                if (! empty($lib['isDefault'])) {
                    $lib['videoCount'] = (int) ($lib['videoCount'] ?? 0) + $uploadCount;
                }

                return $lib;
            }, $libraries);
            if (is_array($current) && ! empty($current['isDefault'])) {
                $current['videoCount'] = (int) ($current['videoCount'] ?? 0) + $uploadCount;
            }
        }

        $searchQuery = trim((string) $request->query('q', ''));
        $libraryFilter = trim((string) $request->query('lq', ''));
        $searchResults = [];
        $searchMessage = null;
        $searchIsError = false;
        $searchNextToken = null;
        $searchPrevToken = null;
        $searchTotalResults = null;
        $pageToken = trim((string) $request->query('pageToken', ''));
        $nowPlaying = null;

        if ($libraryFilter !== '') {
            $needle = mb_strtolower($libraryFilter);
            $playlist = array_values(array_filter(
                $playlist,
                static fn (array $item): bool => str_contains(mb_strtolower((string) ($item['title'] ?? '')), $needle)
            ));
        }

        if ($searchQuery !== '') {
            try {
                app(UserUsageLimitService::class)->assertWithin(
                    $request->user(),
                    UserUsageLimitService::FEATURE_YOUTUBE,
                    1
                );
            } catch (UsageLimitExceededException $e) {
                $searchMessage = $e->getMessage();
                $searchIsError = true;
                $searchQuery = '';
            }
        }

        if ($searchQuery !== '') {
            $result = $this->youtube->search($searchQuery, $pageToken !== '' ? $pageToken : null);
            if (! empty($result['ok'])) {
                app(UserUsageLimitService::class)->consume(
                    $request->user(),
                    UserUsageLimitService::FEATURE_YOUTUBE,
                    1
                );
                $searchResults = is_array($result['items'] ?? null) ? $result['items'] : [];
                $searchNextToken = is_string($result['nextPageToken'] ?? null) ? $result['nextPageToken'] : null;
                $searchPrevToken = is_string($result['prevPageToken'] ?? null) ? $result['prevPageToken'] : null;
                $searchTotalResults = isset($result['totalResults']) ? (int) $result['totalResults'] : null;
                $count = count($searchResults);
                if ($count === 0) {
                    $searchMessage = __('該当する動画がありません。');
                } elseif ($searchTotalResults !== null && $searchTotalResults > $count) {
                    $searchMessage = __(':count件表示（全約:total件）', [
                        'count' => $count,
                        'total' => $searchTotalResults,
                    ]);
                } else {
                    $searchMessage = __(':count件表示', ['count' => $count]);
                }
                if (! empty($result['direct']) && $searchResults !== []) {
                    $nowPlaying = $this->withAutoplay($searchResults[0]);
                }
            } else {
                $searchIsError = true;
                $searchMessage = (string) ($result['message'] ?? __('検索に失敗しました。'));
            }
        }

        $searchResults = array_map(
            fn (array $item): array => [...$item, 'playHref' => $this->playHref($libraryId, $item, array_filter([
                'q' => $searchQuery !== '' ? $searchQuery : null,
                'pageToken' => $pageToken !== '' ? $pageToken : null,
                'lq' => $libraryFilter !== '' ? $libraryFilter : null,
            ]))],
            $searchResults
        );

        $popular = [];
        if ($this->youtube->isSearchReady() && $searchQuery === '') {
            $pop = $this->youtube->popularVideos();
            if (! empty($pop['ok']) && is_array($pop['items'] ?? null)) {
                $popular = array_map(
                    fn (array $item): array => [...$item, 'playHref' => $this->playHref($libraryId, $item)],
                    $pop['items']
                );
            }
        }

        $requested = $this->nowPlayingFromRequest($request, $playlist);
        if ($requested !== null) {
            $nowPlaying = $requested;
        }

        $libraryPaging = $this->paginatePlaylist(
            $playlist,
            (int) $request->query('page', 0),
            is_array($nowPlaying) ? $nowPlaying : null
        );
        $libraryPage = (int) $libraryPaging['page'];
        $libraryTotalPages = (int) $libraryPaging['totalPages'];
        $sharedQuery = array_filter([
            'q' => $searchQuery !== '' ? $searchQuery : null,
            'pageToken' => $pageToken !== '' ? $pageToken : null,
            'lq' => $libraryFilter !== '' ? $libraryFilter : null,
        ]);
        $libraryQuery = array_filter($sharedQuery + [
            'page' => $libraryPage > 1 ? $libraryPage : null,
        ]);
        $searchPagerQuery = $sharedQuery;
        unset($searchPagerQuery['pageToken']);
        $playlist = array_map(function (array $item) use ($libraryId, $libraryQuery): array {
            $item['playHref'] = $this->playHref($libraryId, $item, $libraryQuery);

            return $item;
        }, $libraryPaging['items']);
        $libraryReturnTo = $this->videoIndexUrl($libraryId, $libraryQuery);

        return view('video.index', [
            'playlist' => $playlist,
            'libraries' => $libraries,
            'currentLibrary' => $current,
            'currentLibraryId' => $libraryId,
            'youtubeSearchReady' => $this->youtube->isSearchReady(),
            'maxUploadLabel' => $this->formatBytes($this->photos->maxVideoUploadBytes()),
            'searchQuery' => $searchQuery,
            'searchResults' => $searchResults,
            'searchMessage' => $searchMessage,
            'searchIsError' => $searchIsError,
            'searchNextUrl' => $searchNextToken
                ? $this->videoIndexUrl($libraryId, $searchPagerQuery + ['pageToken' => $searchNextToken]).'#youtube-search-panel'
                : null,
            'searchPrevUrl' => $searchPrevToken
                ? $this->videoIndexUrl($libraryId, $searchPagerQuery + ['pageToken' => $searchPrevToken]).'#youtube-search-panel'
                : null,
            'popular' => $popular,
            'nowPlaying' => $nowPlaying,
            'libraryFilter' => $libraryFilter,
            'libraryPage' => $libraryPage,
            'libraryTotalPages' => $libraryTotalPages,
            'libraryPageLabel' => $libraryPaging['total'] === 0
                ? ''
                : __(':from–:to / :total件', [
                    'from' => $libraryPaging['from'],
                    'to' => $libraryPaging['to'],
                    'total' => $libraryPaging['total'],
                ]),
            'libraryPrevUrl' => $libraryPage > 1
                ? $this->videoIndexUrl($libraryId, $sharedQuery + ['page' => $libraryPage - 1]).'#library-list-panel'
                : null,
            'libraryNextUrl' => $libraryPage < $libraryTotalPages
                ? $this->videoIndexUrl($libraryId, $sharedQuery + ['page' => $libraryPage + 1]).'#library-list-panel'
                : null,
            'libraryReturnTo' => $libraryReturnTo,
            ...$this->flashFromQuery($request),
        ]);
    }

    public function store(Request $request)
    {
        $userId = (int) $request->user()->id;
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/video');
        $files = $request->file('videos', []) ?: [];
        if (! is_array($files)) {
            $files = [$files];
        }

        try {
            $result = $this->photos->uploadPhotos($userId, $files, null, [], true);
            $created = $result['created'] ?? [];
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $videos = array_values(array_filter(
            is_array($created) ? $created : [],
            static fn (array $item): bool => ($item['mediaKind'] ?? '') === 'video'
        ));
        if ($videos === []) {
            return $this->redirectWithMessage($returnTo, __('動画ファイル（MP4 / MOV）を選択してください。'), 'error');
        }

        $count = count($videos);

        return $this->redirectWithMessage(
            $returnTo,
            $count === 1 ? __('動画を追加しました。') : __(':count本の動画を追加しました。', ['count' => $count])
        );
    }

    public function searchYoutube(Request $request): JsonResponse
    {
        try {
            app(UserUsageLimitService::class)->assertWithin(
                $request->user(),
                UserUsageLimitService::FEATURE_YOUTUBE,
                1
            );
        } catch (UsageLimitExceededException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 429);
        }

        $result = $this->youtube->search(
            (string) $request->input('q', ''),
            $request->input('pageToken')
        );
        if (! empty($result['ok'])) {
            app(UserUsageLimitService::class)->consume(
                $request->user(),
                UserUsageLimitService::FEATURE_YOUTUBE,
                1
            );
        }

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
    }

    public function popularYoutube(Request $request): JsonResponse
    {
        try {
            app(UserUsageLimitService::class)->assertWithin(
                $request->user(),
                UserUsageLimitService::FEATURE_YOUTUBE,
                1
            );
        } catch (UsageLimitExceededException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 429);
        }

        $result = $this->youtube->popularVideos();
        if (! empty($result['ok'])) {
            app(UserUsageLimitService::class)->consume(
                $request->user(),
                UserUsageLimitService::FEATURE_YOUTUBE,
                1
            );
        }

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
    }

    public function storeYoutube(Request $request)
    {
        $libraryId = (int) $request->input('library_id', 0) ?: null;
        $returnTo = $this->safeReturnTo(
            $request->input('returnTo'),
            '/video'.($libraryId ? '?library='.$libraryId : '')
        );
        $wantsJson = $request->expectsJson() || $request->ajax();
        $userId = (int) $request->user()->id;
        $youtubeId = trim((string) $request->input('youtube_id', ''));
        $url = trim((string) $request->input('youtube_url', ''));
        $title = trim((string) $request->input('title', ''));
        $thumb = trim((string) $request->input('thumb_url', ''));
        $source = trim((string) $request->input('source', ''));
        $provider = $source === 'tiktok' ? 'tiktok' : 'youtube';

        try {
            if ($youtubeId !== '') {
                $item = $this->youtube->addFromVideoId(
                    $userId,
                    $youtubeId,
                    $title !== '' ? $title : null,
                    $thumb !== '' ? $thumb : null,
                    $libraryId,
                    $provider
                );
            } else {
                $item = $this->youtube->addFromUrl(
                    $userId,
                    $url,
                    $title !== '' ? $title : null,
                    $libraryId
                );
            }
        } catch (\InvalidArgumentException $e) {
            if ($wantsJson) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $savedMessage = (($item['source'] ?? '') === 'tiktok')
            ? __('TikTok動画を追加しました。')
            : __('YouTube動画を追加しました。');

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'message' => $savedMessage,
                'item' => $item,
            ]);
        }

        return $this->redirectWithMessage($returnTo, $savedMessage);
    }

    public function destroyYoutube(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/video');
        if (! $this->youtube->delete((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, __('動画が見つかりません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('YouTube動画を削除しました。'));
    }

    public function storeLibrary(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/video');
        try {
            $library = $this->youtube->createLibrary(
                (int) $request->user()->id,
                (string) $request->input('name', '')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            '/video?library='.$library['id'],
            __('ライブラリ「:name」を作成しました。', ['name' => $library['name']])
        );
    }

    public function updateLibrary(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/video?library='.$id);
        try {
            $this->youtube->renameLibrary(
                (int) $request->user()->id,
                $id,
                (string) $request->input('name', '')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('ライブラリ名を変更しました。'));
    }

    public function destroyLibrary(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/video');
        try {
            if (! $this->youtube->deleteLibrary((int) $request->user()->id, $id)) {
                return $this->redirectWithMessage($returnTo, __('ライブラリが見つかりません。'), 'error');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('ライブラリを削除しました。動画はマイリストへ移動しました。'));
    }

    public function moveYoutube(Request $request, int $id)
    {
        $libraryId = (int) $request->input('library_id', 0);
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/video?library='.$libraryId);
        $moved = $this->youtube->moveToLibrary((int) $request->user()->id, $id, $libraryId);
        if (! $moved) {
            return $this->redirectWithMessage($returnTo, __('動画が見つかりません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('ライブラリへ移動しました。'));
    }

    /**
     * @param  list<array<string, mixed>>  $playlist
     * @return array<string, mixed>|null
     */
    private function nowPlayingFromRequest(Request $request, array $playlist): ?array
    {
        $play = trim((string) $request->query('play', ''));
        if ($play === '') {
            return null;
        }
        $src = trim((string) $request->query('src', 'youtube'));
        if ($src === '') {
            $src = 'youtube';
        }

        foreach ($playlist as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemSrc = (string) ($item['source'] ?? '');
            if ($itemSrc !== $src) {
                continue;
            }
            if ($src === 'upload' && (string) ($item['id'] ?? '') === $play) {
                return $item;
            }
            if ((string) ($item['youtubeId'] ?? '') === $play) {
                return $this->withAutoplay($item);
            }
        }

        if ($src === 'tiktok' && preg_match('/^\d{5,32}$/', $play)) {
            return $this->withAutoplay([
                'source' => 'tiktok',
                'youtubeId' => $play,
                'title' => 'TikTok '.$play,
                'embedUrl' => $this->youtube->tiktokEmbedUrlFor($play, true),
                'url' => 'https://www.tiktok.com/video/'.$play,
            ]);
        }

        if ($src === 'youtube' && preg_match('/^[A-Za-z0-9_-]{6,32}$/', $play)) {
            return $this->withAutoplay([
                'source' => 'youtube',
                'youtubeId' => $play,
                'title' => 'YouTube '.$play,
                'embedUrl' => $this->youtube->embedUrlFor($play, true),
                'url' => 'https://www.youtube.com/watch?v='.$play,
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function withAutoplay(array $item): array
    {
        $source = (string) ($item['source'] ?? 'youtube');
        $id = (string) ($item['youtubeId'] ?? '');
        if ($source === 'tiktok' && $id !== '') {
            $item['embedUrl'] = $this->youtube->tiktokEmbedUrlFor($id, true);
        } elseif ($source === 'youtube' && $id !== '') {
            $item['embedUrl'] = $this->youtube->embedUrlFor($id, true);
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, scalar|null>  $extra
     */
    private function playHref(int $libraryId, array $item, array $extra = []): string
    {
        $source = (string) ($item['source'] ?? 'youtube');
        $play = $source === 'upload'
            ? (string) ($item['id'] ?? '')
            : (string) ($item['youtubeId'] ?? '');

        return $this->videoIndexUrl($libraryId, $extra + [
            'play' => $play,
            'src' => $source,
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    private function videoIndexUrl(int $libraryId, array $params = []): string
    {
        $query = ['library' => $libraryId] + $params;
        foreach ($query as $key => $value) {
            if ($value === null || $value === '' || $value === false) {
                unset($query[$key]);
            }
        }

        return '/video?'.http_build_query($query);
    }

    /**
     * @param  list<array<string, mixed>>  $playlist
     * @param  array<string, mixed>|null  $nowPlaying
     * @return array{items: list<array<string, mixed>>, page: int, totalPages: int, total: int, from: int, to: int}
     */
    private function paginatePlaylist(array $playlist, int $requestedPage, ?array $nowPlaying): array
    {
        $total = count($playlist);
        $perPage = self::LIBRARY_PAGE_SIZE;
        $totalPages = max(1, (int) ceil($total / $perPage) ?: 1);
        $page = $requestedPage;
        if ($page < 1) {
            $page = 1;
            if (is_array($nowPlaying)) {
                $index = $this->playlistIndexOf($playlist, $nowPlaying);
                if ($index !== null) {
                    $page = intdiv($index, $perPage) + 1;
                }
            }
        }
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $items = array_values(array_slice($playlist, $offset, $perPage));
        $from = $total === 0 ? 0 : $offset + 1;
        $to = $offset + count($items);

        return [
            'items' => $items,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $playlist
     * @param  array<string, mixed>  $playing
     */
    private function playlistIndexOf(array $playlist, array $playing): ?int
    {
        $src = (string) ($playing['source'] ?? '');
        $playId = $src === 'upload'
            ? (string) ($playing['id'] ?? '')
            : (string) ($playing['youtubeId'] ?? '');
        if ($playId === '') {
            return null;
        }

        foreach ($playlist as $index => $item) {
            if (! is_array($item) || (string) ($item['source'] ?? '') !== $src) {
                continue;
            }
            $itemId = $src === 'upload'
                ? (string) ($item['id'] ?? '')
                : (string) ($item['youtubeId'] ?? '');
            if ($itemId === $playId) {
                return (int) $index;
            }
        }

        return null;
    }

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.').' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.').' MB';
        }

        return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1, '.', ''), '0'), '.').' GB';
    }
}
