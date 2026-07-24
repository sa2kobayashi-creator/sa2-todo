<?php

namespace App\Http\Controllers;

use App\Services\PhotoService;
use App\Services\YoutubeVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    use Concerns\RedirectsWithFlash;

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

        // アップロード動画はマイリスト（デフォルト）にのみ表示
        if (! empty($current['isDefault'])) {
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

        return view('video.index', [
            'playlist' => $playlist,
            'libraries' => $libraries,
            'currentLibrary' => $current,
            'currentLibraryId' => $libraryId,
            'youtubeSearchReady' => $this->youtube->isSearchReady(),
            'maxUploadLabel' => $this->formatBytes($this->photos->maxVideoUploadBytes()),
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
            return $this->redirectWithMessage($returnTo, __('動画ファイル（MP4）を選択してください。'), 'error');
        }

        $count = count($videos);

        return $this->redirectWithMessage(
            $returnTo,
            $count === 1 ? __('動画を追加しました。') : __(':count本の動画を追加しました。', ['count' => $count])
        );
    }

    public function searchYoutube(Request $request): JsonResponse
    {
        $result = $this->youtube->search(
            (string) $request->input('q', ''),
            $request->input('pageToken')
        );

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

        try {
            if ($youtubeId !== '') {
                $item = $this->youtube->addFromVideoId(
                    $userId,
                    $youtubeId,
                    $title !== '' ? $title : null,
                    $thumb !== '' ? $thumb : null,
                    $libraryId
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

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'message' => __('YouTube動画を追加しました。'),
                'item' => $item,
            ]);
        }

        return $this->redirectWithMessage($returnTo, __('YouTube動画を追加しました。'));
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

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.').' KB';
        }

        return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.').' MB';
    }
}
