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
        private \App\Services\MediaStorageConfigService $mediaStorage,
    ) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $albumId = $request->query('album') !== null && $request->query('album') !== ''
            ? (int) $request->query('album')
            : null;
        $sort = (string) $request->query('sort', 'taken_desc');
        if (! in_array($sort, ['taken_desc', 'taken_asc', 'name_asc', 'name_desc', 'size_desc', 'size_asc'], true)) {
            $sort = 'taken_desc';
        }
        $revealHidden = (bool) session('photos_reveal_hidden_'.$userId, false);
        $albums = $this->photos->listAlbums($userId, $revealHidden);
        $selectedAlbumModel = $albumId ? $this->photos->findViewableAlbum($userId, $albumId) : null;
        $selectedAlbum = $selectedAlbumModel
            ? $this->photos->albumToArray($selectedAlbumModel->loadCount('photos'), $userId)
            : null;

        // 一覧に出ていない隠しアルバムへ直接アクセスした場合も配列に載せる
        if ($selectedAlbum && ! collect($albums)->firstWhere('id', $albumId)) {
            array_unshift($albums, $selectedAlbum);
        }

        $albumLocked = $selectedAlbumModel
            && $this->photos->albumNeedsUnlock($selectedAlbumModel, $userId);

        // year=all で全件。未指定かつ件数が多いときは最新年に自動絞り込み（初回表示を軽くする）
        $yearRaw = $request->query('year');
        $explicitAllYears = $yearRaw === 'all';
        $year = (! $explicitAllYears && $request->filled('year') && is_numeric($yearRaw))
            ? (int) $yearRaw
            : null;

        $photoYears = $albumLocked ? [] : $this->photos->listPhotoYears($userId, $albumId);
        $totalInScope = $albumLocked ? 0 : $this->photos->countPhotos($userId, $albumId);
        $autoYearScoped = false;
        $autoYearThreshold = 120;
        if (! $albumLocked && ! $explicitAllYears && $year === null && $totalInScope > $autoYearThreshold && $photoYears !== []) {
            $year = $photoYears[0];
            $autoYearScoped = true;
        }

        $photoList = $albumLocked ? [] : $this->photos->listPhotos($userId, $albumId, $sort, $year);
        $photosForJs = array_map(static function (array $p): array {
            return [
                'id' => $p['id'] ?? null,
                'albumId' => $p['albumId'] ?? null,
                'url' => $p['url'] ?? null,
                'thumbUrl' => $p['thumbUrl'] ?? null,
                'originalName' => $p['originalName'] ?? null,
                'caption' => $p['caption'] ?? null,
                'mime' => $p['mime'] ?? null,
                'mediaKind' => $p['mediaKind'] ?? 'image',
                'width' => $p['width'] ?? null,
                'height' => $p['height'] ?? null,
                'takenAt' => $p['takenAt'] ?? null,
                'takenDate' => $p['takenDate'] ?? null,
                'takenAtLocal' => $p['takenAtLocal'] ?? null,
                'canEdit' => $p['canEdit'] ?? false,
                'fileUrl' => $p['fileUrl'] ?? null,
                'editLabel' => $p['editLabel'] ?? null,
                'parentPhotoId' => $p['parentPhotoId'] ?? null,
            ];
        }, $photoList);
        $ownedAlbums = array_values(array_filter($albums, fn ($a) => ! empty($a['canManage'])));
        $queryBase = [];
        if ($albumId) {
            $queryBase['album'] = $albumId;
        }
        if ($sort !== 'taken_desc') {
            $queryBase['sort'] = $sort;
        }
        if ($explicitAllYears) {
            $queryBase['year'] = 'all';
        } elseif ($year) {
            $queryBase['year'] = $year;
        }
        $returnQuery = $queryBase !== [] ? ('?'.http_build_query($queryBase)) : '';
        $photoGroups = $this->photos->groupPhotosForDisplay($photoList, $sort);

        return view('photos.index', [
            'albums' => $albums,
            'ownedAlbums' => $ownedAlbums,
            'photos' => $photoList,
            'photosForJs' => $photosForJs,
            'photoGroups' => $photoGroups,
            'photoYears' => $photoYears,
            'photoJumpYears' => $this->photos->groupYearOptions($photoGroups),
            'photosSort' => $sort,
            'photosYear' => $year,
            'photosYearExplicitAll' => $explicitAllYears,
            'photosYearAutoScoped' => $autoYearScoped,
            'photosTotalInScope' => $totalInScope,
            'selectedAlbumId' => $albumId,
            'selectedAlbum' => $selectedAlbum,
            'albumLocked' => $albumLocked,
            'revealHiddenAlbums' => $revealHidden,
            'canManageSelected' => ! empty($selectedAlbum['canManage']),
            'approvedGroups' => $this->groups->listApprovedForUser($userId),
            'storageStats' => $this->photos->storageStats($userId),
            'returnTo' => '/photos'.$returnQuery,
            'uploadLimits' => [
                'postMaxBytes' => $this->iniBytes((string) ini_get('post_max_size')),
                'uploadMaxBytes' => $this->iniBytes((string) ini_get('upload_max_filesize')),
                'videoMaxBytes' => $this->photos->maxVideoUploadBytes(),
                'chunkBytes' => 8 * 1024 * 1024,
            ],
            'videoLimitLabel' => $this->photos->maxVideoUploadLabel(),
            'cloudinaryEditorReady' => $this->mediaStorage->cloudinaryEditorEnabled(),
            'stabilityEnhanceReady' => $this->enhanceButtonReady(),
            ...$this->flashFromQuery($request),
        ]);
    }

    private function enhanceButtonReady(): bool
    {
        $enhance = app(\App\Services\EnhanceConfigService::class);

        return $enhance->isReady() && $enhance->isImplemented($enhance->activeProvider());
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
                $request->input('group_id'),
                $request->input('password'),
                $request->boolean('is_hidden')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/photos?album='.$album['id'], __('アルバムを作成しました'));
    }

    public function storeFolderAlbum(Request $request)
    {
        try {
            $album = $this->photos->createAlbumForFolder(
                (int) $request->user()->id,
                (string) $request->input('folder_name')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'album' => ['id' => $album['id'], 'name' => $album['name']],
        ]);
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
                $request->input('group_id'),
                $request->input('password'),
                $request->boolean('clear_password'),
                $request->boolean('is_hidden')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('アルバムを更新しました'));
    }

    public function unlockAlbum(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos?album='.$id);
        $ok = $this->photos->unlockAlbum(
            (int) $request->user()->id,
            $id,
            (string) $request->input('password', '')
        );
        if (! $ok) {
            return $this->redirectWithMessage($returnTo, __('パスワードが正しくありません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('アルバムのロックを解除しました。'));
    }

    public function toggleRevealHiddenAlbums(Request $request)
    {
        $userId = (int) $request->user()->id;
        $key = 'photos_reveal_hidden_'.$userId;
        $next = ! (bool) session($key, false);
        session([$key => $next]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'revealed' => $next,
                'message' => $next
                    ? __('隠しアルバムを表示しています。')
                    : __('隠しアルバムを非表示にしました。'),
            ]);
        }

        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');

        return $this->redirectWithMessage(
            $returnTo,
            $next ? __('隠しアルバムを表示しています。') : __('隠しアルバムを非表示にしました。')
        );
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
        $takenAts = $request->input('taken_ats', []);
        if (! is_array($takenAts)) {
            $takenAts = [];
        }
        $takenAtByIndex = [];
        foreach ($takenAts as $i => $value) {
            if (is_string($value) && trim($value) !== '') {
                $takenAtByIndex[(int) $i] = trim($value);
            }
        }
        $contentHashes = $request->input('content_hashes', []);
        if (! is_array($contentHashes)) {
            $contentHashes = [];
        }
        $contentHashByIndex = [];
        foreach ($contentHashes as $i => $value) {
            if (is_string($value) && preg_match('/^[a-f0-9]{64}$/i', trim($value))) {
                $contentHashByIndex[(int) $i] = strtolower(trim($value));
            }
        }

        try {
            $result = $this->photos->uploadPhotos(
                (int) $request->user()->id,
                $files,
                $albumId,
                $thumbsByIndex,
                $allowDuplicates,
                $takenAtByIndex,
                $contentHashByIndex
            );
        } catch (\InvalidArgumentException $e) {
            if ($wantsJson) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            report($e);
            $message = $this->uploadFailureMessage($e);
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
                $allowDuplicates,
                $request->input('taken_at'),
                $request->input('content_hash')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => $this->uploadFailureMessage($e),
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
            'message' => __('1件のメディアを追加しました'),
            'photo' => $result['created'],
        ]);
    }

    /**
     * B2アーカイブをバックグラウンドで開始する。
     * ブラウザを閉じても cron（毎分）と afterResponse で続きが進む。
     */
    public function archiveColdStart(Request $request)
    {
        $timeoutSec = max(60, (int) config('photos.archive_cold_request_timeout_seconds', 180));
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeoutSec);
        }
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }

        $runs = app(\App\Services\PhotoColdArchiveRunService::class);
        $state = $runs->start((int) $request->user()->id);

        // レスポンス後も時間予算内で続けて進め、ユーザーは他画面へ行ける
        if (($state['status'] ?? '') === \App\Services\PhotoColdArchiveRunService::STATUS_RUNNING) {
            $budget = max(15, min(50, (int) config('photos.archive_cold_batch_seconds', 45)));
            dispatch(function () use ($budget) {
                app(\App\Services\PhotoColdArchiveRunService::class)->continueAfterResponse($budget);
            })->afterResponse();
        }

        return response()->json($this->archiveRunPayload($state, (int) $request->user()->id));
    }

    /** バックグラウンドアーカイブの進捗（実行中ならここで1バッチ進める） */
    public function archiveColdStatus(Request $request)
    {
        $runs = app(\App\Services\PhotoColdArchiveRunService::class);
        $userId = (int) $request->user()->id;

        if ($request->boolean('dismiss')) {
            return response()->json($this->archiveRunPayload($runs->dismissTerminal(), $userId));
        }

        $state = $runs->current();
        // cron が無い共有ホストでも、Photos を開いている間はポーリングで進める
        if (($state['status'] ?? '') === \App\Services\PhotoColdArchiveRunService::STATUS_RUNNING) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(max(60, (int) config('photos.archive_cold_request_timeout_seconds', 180)));
            }
            $state = $runs->tick();
        }

        return response()->json($this->archiveRunPayload($state, $userId));
    }

    /** バックグラウンドアーカイブの中止依頼 */
    public function archiveColdCancel(Request $request)
    {
        $runs = app(\App\Services\PhotoColdArchiveRunService::class);
        $state = $runs->requestCancel();

        return response()->json($this->archiveRunPayload($state, (int) $request->user()->id));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function archiveRunPayload(array $state, int $userId): array
    {
        $running = ($state['status'] ?? '') === \App\Services\PhotoColdArchiveRunService::STATUS_RUNNING;

        return [
            'ok' => true,
            'run' => $state,
            'running' => $running,
            // 実行中も使用量を返し、画面上の R2 数字が動いていることを見える化する
            'storageStats' => $this->photos->storageStats($userId),
        ];
    }

    /**
     * R2 の古い原本を Backblaze B2 へ移す（1バッチ・互換用）。
     */
    public function archiveCold(Request $request)
    {
        $timeoutSec = max(60, (int) config('photos.archive_cold_request_timeout_seconds', 180));
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeoutSec);
        }
        // 共有ホストでも長めの転送が途中で切れにくいようメモリも引き上げる
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }

        $batchDefault = max(1, (int) config('photos.archive_cold_batch_size', 8));
        $limit = max(1, min(500, (int) $request->input('limit', $batchDefault)));
        $archive = app(\App\Services\PhotoColdArchiveService::class);

        try {
            $stats = $archive->archiveDuePhotos($limit);
        } catch (\Throwable $e) {
            report($e);
            $err = mb_substr(trim($e->getMessage()), 0, 240);

            return response()->json([
                'ok' => false,
                'message' => __('アーカイブに失敗しました: :err', [
                    'err' => $err !== '' ? $err : class_basename($e),
                ]),
                'archived' => 0,
                'skipped' => 0,
                'errors' => 1,
                'has_more' => false,
                'reason' => 'exception',
                'last_error' => $err !== '' ? $err : class_basename($e),
                'last_error_photo_id' => null,
            ], 500);
        }

        $archived = (int) ($stats['archived'] ?? 0);
        $skipped = (int) ($stats['skipped'] ?? 0);
        $errors = (int) ($stats['errors'] ?? 0);
        $bytesMoved = (int) ($stats['bytesMoved'] ?? 0);
        $mode = $this->mediaStorage->capacityMode();
        $hasMore = (bool) ($stats['hasMore'] ?? false);
        $reason = (string) ($stats['reason'] ?? '');
        $lastError = (string) ($stats['lastError'] ?? '');
        $lastErrorPhotoId = $stats['lastErrorPhotoId'] ?? null;

        $message = __('アーカイブ完了: 移動 :archived 件 · スキップ :skipped 件 · エラー :errors 件（mode=:mode, limit=:limit）', [
            'archived' => $archived,
            'skipped' => $skipped,
            'errors' => $errors,
            'mode' => $mode,
            'limit' => $limit,
        ]);
        if ($bytesMoved > 0) {
            $message .= ' '.__('転送量 約 :size', [
                'size' => $this->formatArchiveBytes($bytesMoved),
            ]);
        }

        if ($archived === 0) {
            $message .= ' '.$this->archiveIdleReason($reason);
        } elseif (in_array($reason, [
            \App\Services\PhotoColdArchiveService::REASON_TIME_BUDGET,
            \App\Services\PhotoColdArchiveService::REASON_LARGE_FILE,
        ], true)) {
            $message .= ' '.$this->archiveIdleReason($reason);
        }

        if ($lastError !== '') {
            $detail = $lastErrorPhotoId
                ? __('最後のエラー（写真ID :id）: :err', ['id' => $lastErrorPhotoId, 'err' => $lastError])
                : __('最後のエラー: :err', ['err' => $lastError]);
            $message .= ' '.$detail;
        }

        // 進捗があれば 200 で返し、クライアントが次バッチへ進めるようにする
        $hardFail = $archived === 0 && $errors > 0 && ! $hasMore;

        return response()->json([
            'ok' => ! $hardFail && $errors === 0,
            'message' => trim($message),
            'archived' => $archived,
            'skipped' => $skipped,
            'errors' => $errors,
            'bytes_moved' => $bytesMoved,
            'mode' => $mode,
            'limit' => $limit,
            'has_more' => $hasMore,
            'reason' => $reason,
            'last_error' => $lastError,
            'last_error_photo_id' => $lastErrorPhotoId,
            'storageStats' => $this->photos->storageStats((int) $request->user()->id),
        ], $hardFail ? 422 : 200);
    }

    private function formatArchiveBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }

    /**
     * 1件も移らなかったとき、設定の問題なのか対象が無いだけなのかを画面で分かるようにする。
     */
    private function archiveIdleReason(string $reason): string
    {
        return match ($reason) {
            \App\Services\PhotoColdArchiveService::REASON_DISABLED => __('B2連携が無効です。設定 → ストレージで Backblaze と長期保存を有効にしてください。'),
            \App\Services\PhotoColdArchiveService::REASON_WITHIN_QUOTA => __('常用ストレージは上限内のため、移動対象はありません。'),
            \App\Services\PhotoColdArchiveService::REASON_NO_DUE_PHOTOS => __('アーカイブ対象（:days 日より古い写真）がありません。R2 を上限まで減らすには、設定 → ストレージで容量モードを「R2上限」に変更してください。', [
                'days' => $this->mediaStorage->archiveAfterDays(),
            ]),
            \App\Services\PhotoColdArchiveService::REASON_ALL_BLOCKED => __('残りの原本は移動できませんでした（元ファイルが見つからない、または B2 への書き込みに失敗）。ログを確認してください。'),
            \App\Services\PhotoColdArchiveService::REASON_TIME_BUDGET => __('時間予算に達したため一旦区切りました。続けて実行すれば続きから移動します。'),
            \App\Services\PhotoColdArchiveService::REASON_LARGE_FILE => __('大きな動画を単独で移すため一旦区切りました。続けて実行すれば続きから移動します。'),
            default => '',
        };
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

    public function stabilityEnhance(Request $request, int $id)
    {
        try {
            $result = $this->photos->enhancePhoto((int) $request->user()->id, $id);
        } catch (\App\Exceptions\EnhanceCancelledException $e) {
            return response()->json(['ok' => false, 'cancelled' => true, 'message' => $e->getMessage()], 499);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $photo = $result['photo'];
        $from = ($result['sourceWidth'] && $result['sourceHeight'])
            ? $result['sourceWidth'].'×'.$result['sourceHeight']
            : null;
        $to = ($result['resultWidth'] && $result['resultHeight'])
            ? $result['resultWidth'].'×'.$result['resultHeight']
            : null;

        $message = ($from && $to)
            ? __('AI鮮明化版を保存しました（:from → :to）。拡大表示で差を確認できます。', ['from' => $from, 'to' => $to])
            : __('AI鮮明化版を保存しました。解像度が上がっているので、拡大して確認してください。');

        return response()->json([
            'ok' => true,
            'message' => $message,
            'photo' => $photo,
            'zoom' => 2,
        ]);
    }

    public function stabilityEnhanceCancel(Request $request, int $id)
    {
        $this->photos->cancelEnhance((int) $request->user()->id, $id);

        return response()->json([
            'ok' => true,
            'message' => __('鮮明化の中止を受け付けました。'),
        ]);
    }

    public function cloudinaryEditStart(Request $request, int $id)
    {
        try {
            $session = $this->photos->startCloudinaryEdit((int) $request->user()->id, $id);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, ...$session]);
    }

    public function cloudinaryEditCommit(Request $request, int $id)
    {
        $exportUrl = (string) $request->input('exportUrl', '');
        $tempPublicId = (string) $request->input('tempPublicId', '');
        $label = $request->input('label');

        try {
            $photo = $this->photos->commitCloudinaryEdit(
                (int) $request->user()->id,
                $id,
                $exportUrl,
                $tempPublicId,
                is_string($label) ? $label : null
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => __('Cloudinary編集版を保存しました。'),
            'photo' => $photo,
        ]);
    }

    public function cloudinaryEditCancel(Request $request, int $id)
    {
        $tempPublicId = (string) $request->input('tempPublicId', '');
        $this->photos->cancelCloudinaryEdit($tempPublicId);

        return response()->json(['ok' => true]);
    }

    public function file(Request $request, int $id)
    {
        $photo = $this->photos->findViewablePhoto((int) $request->user()->id, $id);
        if (! $photo) {
            abort(404);
        }

        try {
            $variant = (string) $request->query('variant', 'original');
            if (! in_array($variant, ['original', 'thumb'], true)) {
                $variant = 'original';
            }
            $file = $this->photos->readPhotoFile($photo, $variant);
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

    private function uploadFailureMessage(\Throwable $e): string
    {
        $detail = trim(mb_substr($e->getMessage(), 0, 240));
        if ($detail !== '') {
            return 'アップロードに失敗しました: '.$detail;
        }

        return 'アップロードに失敗しました。ストレージ設定（R2）と PHP の upload_max_filesize / post_max_size を確認してください。';
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

        return $this->redirectWithMessage($returnTo, __('アルバムの表紙を更新しました'));
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        $wantsJson = $request->expectsJson() || $request->ajax();
        if (! $this->photos->deletePhoto((int) $request->user()->id, $id)) {
            if ($wantsJson) {
                return response()->json(['ok' => false, 'message' => '写真が見つかりません'], 404);
            }

            return $this->redirectWithMessage($returnTo, __('写真が見つかりません'), 'error');
        }

        if ($wantsJson) {
            return response()->json(['ok' => true, 'message' => '写真を削除しました']);
        }

        return $this->redirectWithMessage($returnTo, __('写真を削除しました'));
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
        $message = __(':count件のメディアを削除しました', ['count' => $count]);

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

        return $this->redirectWithMessage($returnTo, __(':count件のメディアを移動しました', ['count' => $count]));
    }

    public function destroyAlbum(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/photos');
        if (! $this->photos->deleteAlbum((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, __('アルバムが見つかりません'), 'error');
        }

        return $this->redirectWithMessage('/photos', __('アルバムを削除しました'));
    }
}
