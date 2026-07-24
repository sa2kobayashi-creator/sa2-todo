<?php

namespace App\Http\Controllers;

use App\Services\PhotoService;
use App\Services\YoutubeVideoService;
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

    public function storeYoutube(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/video');
        $url = trim((string) $request->input('youtube_url', ''));
        $title = trim((string) $request->input('title', ''));

        try {
            $this->youtube->addFromUrl((int) $request->user()->id, $url, $title !== '' ? $title : null);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
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
