<?php

namespace App\Http\Controllers;

use App\Services\GroupService;
use App\Services\PhotoService;
use Illuminate\Http\Request;

class PhotoController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private PhotoService $photos,
        private GroupService $groups,
    ) {}

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
        $ownedAlbums = array_values(array_filter($albums, fn ($a) => ! empty($a['canManage'])));

        return view('photos.index', [
            'albums' => $albums,
            'ownedAlbums' => $ownedAlbums,
            'photos' => $photoList,
            'photoGroups' => $this->photos->groupPhotosByDate($photoList),
            'selectedAlbumId' => $albumId,
            'selectedAlbum' => $selectedAlbum,
            'canManageSelected' => ! empty($selectedAlbum['canManage']),
            'approvedGroups' => $this->groups->listApprovedForUser($userId),
            'storageStats' => $this->photos->storageStats($userId),
            'returnTo' => '/photos'.($albumId ? '?album='.$albumId : ''),
            'uploadLimits' => [
                'postMaxBytes' => $this->iniBytes((string) ini_get('post_max_size')),
                'uploadMaxBytes' => $this->iniBytes((string) ini_get('upload_max_filesize')),
                'videoMaxBytes' => $this->photos->maxVideoUploadBytes(),
                'chunkBytes' => 4 * 1024 * 1024,
            ],
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
                $request->input('description'),
                (string) $request->input('visibility', 'private'),
                $request->input('group_id')
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
                $request->input('description'),
                (string) $request->input('visibility', 'private'),
                $request->input('group_id')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, 'アルバム名を更新しました');
    }

    public function store(Request $request)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $albumId = $request->filled('album_id') ? (int) $request->input('album_id') : null;
        $returnTo = $this->safeReturnTo(
            $request->input('returnTo'),
            '/photos'.($albumId ? '?album='.$albumId : '')
        );
        $wantsJson = $request->expectsJson() || $request->ajax();

        if ($message = $this->uploadLimitMessage($request)) {
            if ($wantsJson) {
                return response()->json(['ok' => false, 'message' => $message], 413);
            }

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

        $allowDuplicates = $request->boolean('allow_duplicates');

        try {
            $result = $this->photos->uploadPhotos(
                (int) $request->user()->id,
                $files,
                $albumId,
                $thumbsByIndex,
                $allowDuplicates
            );
        } catch (\InvalidArgumentException $e) {
            if ($wantsJson) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            report($e);
            $message = 'アップロードに失敗しました。動画は800MB以下のMP4でお試しください。';
            if ($wantsJson) {
                return response()->json(['ok' => false, 'message' => $message], 500);
            }

            return $this->redirectWithMessage($returnTo, $message, 'error');
        }

        $created = $result['created'];
        $skipped = $result['skipped'];
        $count = count($created);
        $skipCount = count($skipped);
        $hasVideo = collect($created)->contains(fn ($item) => ($item['mediaKind'] ?? '') === 'video');
        $hasImage = collect($created)->contains(fn ($item) => ($item['mediaKind'] ?? '') !== 'video');
        $label = match (true) {
            $count === 0 => 'メディア',
            $hasVideo && $hasImage => 'メディア',
            $hasVideo => '動画',
            default => '写真',
        };
        $message = $count > 0
            ? $count.'件の'.$label.'を追加しました'
            : '追加する新規ファイルはありませんでした';
        if ($skipCount > 0) {
            $message .= '（重複スキップ '.$skipCount.'件）';
        }

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'count' => $count,
                'skipped' => $skipCount,
                'message' => $message,
            ]);
        }

        return $this->redirectWithMessage($returnTo, $message);
    }

    public function checkDuplicates(Request $request)
    {
        $hashes = $request->input('hashes', []);
        if (! is_array($hashes)) {
            $hashes = [];
        }

        $existing = $this->photos->findExistingContentHashes((int) $request->user()->id, $hashes);

        return response()->json([
            'ok' => true,
            'existing' => $existing,
        ]);
    }

    public function scanDuplicates(Request $request)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $albumId = $request->filled('album_id') ? (int) $request->input('album_id') : null;

        try {
            $groups = $this->photos->findDuplicateGroups((int) $request->user()->id, $albumId);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => '重複スキャンに失敗しました。しばらくして再試行してください。',
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'groups' => $groups,
            'groupCount' => count($groups),
            'duplicateCount' => array_sum(array_map(static fn ($g) => (int) ($g['count'] ?? 0), $groups)),
        ]);
    }

    public function rename(Request $request, int $id)
    {
        try {
            $photo = $this->photos->updateOriginalName(
                (int) $request->user()->id,
                $id,
                (string) $request->input('original_name', '')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'photo' => $photo]);
    }

    public function uploadChunk(Request $request)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $chunk = $request->file('chunk');
        if (! $chunk instanceof \Illuminate\Http\UploadedFile) {
            return response()->json(['ok' => false, 'message' => 'チャンクがありません'], 422);
        }

        try {
            $this->photos->receiveUploadChunk(
                (int) $request->user()->id,
                (string) $request->input('upload_id'),
                (int) $request->input('chunk_index'),
                (int) $request->input('chunk_total'),
                $chunk
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'チャンクの保存に失敗しました'], 500);
        }

        return response()->json(['ok' => true]);
    }

    public function uploadComplete(Request $request)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $albumId = $request->filled('album_id') ? (int) $request->input('album_id') : null;
        $thumb = $request->file('video_thumb');
        if (! $thumb instanceof \Illuminate\Http\UploadedFile) {
            $thumb = null;
        }

        $allowDuplicates = $request->boolean('allow_duplicates');

        try {
            $result = $this->photos->finalizeChunkedUpload(
                (int) $request->user()->id,
                (string) $request->input('upload_id'),
                (string) $request->input('original_name', 'upload.bin'),
                $albumId,
                $thumb,
                $request->input('mime'),
                $allowDuplicates
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'アップロードに失敗しました。動画は800MB以下のMP4でお試しください。',
            ], 500);
        }

        if (! empty($result['skipped'])) {
            return response()->json([
                'ok' => true,
                'count' => 0,
                'skipped' => 1,
                'message' => '重複のためスキップしました',
                'skippedName' => $result['skippedName'],
            ]);
        }

        return response()->json([
            'ok' => true,
            'count' => 1,
            'skipped' => 0,
            'message' => '1件のメディアを追加しました',
            'photo' => $result['created'],
        ]);
    }

    public function editImage(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        $image = $request->file('image');
        if (! $image) {
            return $this->redirectWithMessage($returnTo, __('編集画像を選択してください。'), 'error');
        }

        try {
            $this->photos->saveEditedImage(
                (int) $request->user()->id,
                $id,
                $image,
                $request->input('label')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('編集版を保存しました。'));
    }

    public function file(Request $request, int $id)
    {
        $photo = $this->photos->findViewablePhoto((int) $request->user()->id, $id);
        if (! $photo) {
            abort(404);
        }

        try {
            $file = $this->photos->readPhotoFile($photo);
        } catch (\InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.addslashes($file['name']).'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function trimVideo(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        try {
            $this->photos->trimVideo(
                (int) $request->user()->id,
                $id,
                (float) $request->input('start', 0),
                (float) $request->input('end', 0)
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('切り出し動画を保存しました。'));
    }

    public function updateTakenAt(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        try {
            $this->photos->updateTakenAt(
                (int) $request->user()->id,
                $id,
                $request->input('taken_at')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('登録日を更新しました。'));
    }

    private function uploadLimitMessage(Request $request): ?string
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        $postMax = $this->iniBytes((string) ini_get('post_max_size'));
        if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax && ! $request->files->count()) {
            return 'アップロードがサーバー上限を超えています（送信='
                .$this->formatMb($contentLength)
                .' / post_max_size='
                .ini_get('post_max_size')
                .'）。composer serve で起動するか、大きい動画は分割送信になります。サーバー再起動後も続く場合は php-upload.ini を確認してください。';
        }

        return null;
    }

    private function formatMb(int $bytes): string
    {
        return round($bytes / 1048576, 1).'MB';
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
        $wantsJson = $request->expectsJson() || $request->ajax();
        if (! $this->photos->deletePhoto((int) $request->user()->id, $id)) {
            if ($wantsJson) {
                return response()->json(['ok' => false, 'message' => '写真が見つかりません'], 404);
            }

            return $this->redirectWithMessage($returnTo, '写真が見つかりません', 'error');
        }

        if ($wantsJson) {
            return response()->json(['ok' => true, 'message' => '写真を削除しました']);
        }

        return $this->redirectWithMessage($returnTo, '写真を削除しました');
    }

    public function bulkDestroy(Request $request)
    {
        // 多数件＋オブジェクトストレージ削除向け（デフォルト 30s を超えないよう余裕を持たせる）
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        $count = $this->photos->bulkDeletePhotos(
            (int) $request->user()->id,
            $this->photos->parseIdList($request->input('ids'))
        );
        $message = $count.'件のメディアを削除しました';

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'count' => $count,
                'message' => $message,
            ]);
        }

        return $this->redirectWithMessage($returnTo, $message);
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
