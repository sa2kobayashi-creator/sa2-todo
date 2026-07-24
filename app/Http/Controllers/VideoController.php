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
        $uploads = $this->photos->listVideos($userId);
        $youtube = $this->youtube->listForUser($userId);

        $playlist = [];
        foreach ($youtube as $item) {
            $playlist[] = $item;
        }
        foreach ($uploads as $video) {
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

        return view('video.index', [
            'playlist' => $playlist,
            'youtubeVideos' => $youtube,
            'uploadedVideos' => $uploads,
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
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/video');
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
                    $thumb !== '' ? $thumb : null
                );
            } else {
                $item = $this->youtube->addFromUrl($userId, $url, $title !== '' ? $title : null);
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

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.').' KB';
        }

        return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.').' MB';
    }
}
