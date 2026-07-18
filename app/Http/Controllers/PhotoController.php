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

    public function store(Request $request)
    {
        $albumId = $request->filled('album_id') ? (int) $request->input('album_id') : null;
        $returnTo = $this->safeReturnTo(
            $request->input('returnTo'),
            '/photos'.($albumId ? '?album='.$albumId : '')
        );

        $files = $request->file('photos', []);
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }

        try {
            $created = $this->photos->uploadPhotos((int) $request->user()->id, $files, $albumId);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $count = count($created);

        return $this->redirectWithMessage($returnTo, $count.'枚の写真を追加しました');
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

    public function destroyAlbum(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        if (! $this->photos->deleteAlbum((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, 'アルバムが見つかりません', 'error');
        }

        return $this->redirectWithMessage('/photos', 'アルバムを削除しました');
    }
}
