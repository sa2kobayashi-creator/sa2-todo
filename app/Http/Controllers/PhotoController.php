<?php

namespace App\Http\Controllers;

use App\Services\PhotoService;
use Illuminate\Http\Request;

class PhotoController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private PhotoService $photos) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $albumId = $request->query('album') !== null && $request->query('album') !== ''
            ? (int) $request->query('album')
            : null;
        $albums = $this->photos->listAlbums($userId);
        $selectedAlbum = $albumId
            ? collect($albums)->firstWhere('id', $albumId)
            : null;
        $photoList = $this->photos->listPhotos($userId, $albumId);

        return view('photos.index', [
            'albums' => $albums,
            'photos' => $photoList,
            'photoGroups' => $this->photos->groupPhotosByDate($photoList),
            'selectedAlbumId' => $albumId,
            'selectedAlbum' => $selectedAlbum,
            'storageStats' => $this->photos->storageStats($userId),
            'returnTo' => '/photos'.($albumId ? '?album='.$albumId : ''),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function storeAlbum(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        try {
            $album = $this->photos->createAlbum(
                (int) $request->user()->id,
                (string) $request->input('name'),
                $request->input('description')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/photos?album='.$album['id'], 'アルバムを作成しました');
    }

    public function updateAlbum(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos?album='.$id);
        try {
            $this->photos->updateAlbum(
                (int) $request->user()->id,
                $id,
                (string) $request->input('name'),
                $request->input('description')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, 'アルバム名を更新しました');
    }

    public function store(Request $request)
    {
        $albumId = $request->filled('album_id') ? (int) $request->input('album_id') : null;
        $returnTo = $this->safeReturnTo(
            $request->input('returnTo'),
            '/photos'.($albumId ? '?album='.$albumId : '')
        );

        if ($message = $this->uploadLimitMessage($request)) {
            return $this->redirectWithMessage($returnTo, $message, 'error');
        }

        $files = $request->file('photos', []);
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }

        $thumbs = $request->file('video_thumbs', []);
        if (! is_array($thumbs)) {
            $thumbs = $thumbs ? [$thumbs] : [];
        }
        $thumbFor = array_values(array_filter(array_map(
            static fn ($v) => is_numeric($v) ? (int) $v : null,
            explode(',', (string) $request->input('video_thumb_for', ''))
        ), static fn ($v) => $v !== null && $v >= 0));
        $thumbsByIndex = [];
        foreach ($thumbFor as $thumbPos => $fileIndex) {
            if (isset($thumbs[$thumbPos]) && $thumbs[$thumbPos] instanceof \Illuminate\Http\UploadedFile) {
                $thumbsByIndex[$fileIndex] = $thumbs[$thumbPos];
            }
        }

        try {
            $created = $this->photos->uploadPhotos((int) $request->user()->id, $files, $albumId, $thumbsByIndex);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            report($e);

            return $this->redirectWithMessage(
                $returnTo,
                'アップロードに失敗しました。動画は100MB以下のMP4でお試しください。',
                'error'
            );
        }

        $count = count($created);
        $hasVideo = collect($created)->contains(fn ($item) => ($item['mediaKind'] ?? '') === 'video');
        $hasImage = collect($created)->contains(fn ($item) => ($item['mediaKind'] ?? '') !== 'video');
        $label = match (true) {
            $hasVideo && $hasImage => 'メディア',
            $hasVideo => '動画',
            default => '写真',
        };

        return $this->redirectWithMessage($returnTo, $count.'件の'.$label.'を追加しました');
    }

    private function uploadLimitMessage(Request $request): ?string
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        $postMax = $this->iniBytes((string) ini_get('post_max_size'));
        if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax && ! $request->files->count()) {
            return 'アップロードがサーバー上限を超えています（post_max_size='
                .ini_get('post_max_size')
                .'）。php-upload.ini 付きでサーバーを起動するか、PHP設定を 128M 以上にしてください。';
        }

        return null;
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function setCover(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos?album='.$id);
        $photoId = (int) $request->input('photo_id');

        try {
            $this->photos->setAlbumCover((int) $request->user()->id, $id, $photoId);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, 'アルバムの表紙を更新しました');
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        if (! $this->photos->deletePhoto((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, '写真が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, '写真を削除しました');
    }

    public function bulkDestroy(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        $count = $this->photos->bulkDeletePhotos(
            (int) $request->user()->id,
            $this->photos->parseIdList($request->input('ids'))
        );

        return $this->redirectWithMessage($returnTo, $count.'件のメディアを削除しました');
    }

    public function bulkMove(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        $albumRaw = $request->input('album_id');
        $albumId = ($albumRaw === null || $albumRaw === '') ? null : (int) $albumRaw;

        try {
            $count = $this->photos->bulkMovePhotos(
                (int) $request->user()->id,
                $this->photos->parseIdList($request->input('ids')),
                $albumId
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, $count.'件のメディアを移動しました');
    }

    public function destroyAlbum(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        if (! $this->photos->deleteAlbum((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, 'アルバムが見つかりません', 'error');
        }

        return $this->redirectWithMessage('/photos', 'アルバムを削除しました');
    }
}
