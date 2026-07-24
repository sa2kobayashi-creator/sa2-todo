<?php

namespace App\Http\Controllers;

use App\Services\MusicService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MusicController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private MusicService $music) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;

        return view('music.index', [
            'tracks' => $this->music->listTracks($userId),
            'maxUploadLabel' => $this->formatBytes($this->music->maxUploadBytes()),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function store(Request $request)
    {
        $userId = (int) $request->user()->id;
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/music');

        try {
            $created = $this->music->addTracks(
                $userId,
                $request->file('tracks', []) ?: [],
                $request->input('title')
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $count = count($created);

        return $this->redirectWithMessage(
            $returnTo,
            $count === 1 ? __('曲を追加しました。') : __(':count曲を追加しました。', ['count' => $count])
        );
    }

    public function destroy(Request $request, int $id)
    {
        $userId = (int) $request->user()->id;
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/music');
        if (! $this->music->deleteTrack($userId, $id)) {
            return $this->redirectWithMessage($returnTo, __('曲が見つかりません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('曲を削除しました。'));
    }

    public function file(Request $request, int $id): StreamedResponse
    {
        return $this->music->stream((int) $request->user()->id, $id);
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
