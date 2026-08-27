<?php

namespace App\Http\Controllers;

use App\Services\MusicService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MusicController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public const LIBRARY_PAGE_SIZE = 20;

    public function __construct(private MusicService $music) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $libraries = $this->music->listLibraries($userId);
        $defaultId = (int) collect($libraries)->firstWhere('isDefault', true)['id'];
        $libraryId = (int) ($request->query('library') ?: $defaultId);
        $current = collect($libraries)->firstWhere('id', $libraryId);
        if (! $current) {
            $libraryId = $defaultId;
            $current = collect($libraries)->firstWhere('id', $libraryId);
        }

        $tracks = $this->music->listTracks($userId, $libraryId);

        $filter = trim((string) $request->query('q', ''));
        if ($filter !== '') {
            $needle = mb_strtolower($filter);
            $tracks = array_values(array_filter(
                $tracks,
                static fn (array $track): bool => str_contains(mb_strtolower((string) ($track['title'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($track['originalName'] ?? '')), $needle)
            ));
        }

        $total = count($tracks);
        $totalPages = max(1, (int) ceil($total / self::LIBRARY_PAGE_SIZE) ?: 1);
        $page = max(1, min((int) $request->query('page', 1), $totalPages));
        $offset = ($page - 1) * self::LIBRARY_PAGE_SIZE;
        $pageTracks = array_values(array_slice($tracks, $offset, self::LIBRARY_PAGE_SIZE));

        $baseQuery = array_filter([
            'q' => $filter !== '' ? $filter : null,
            'page' => $page > 1 ? $page : null,
        ]);
        $returnTo = $this->musicUrl($libraryId, $baseQuery);

        return view('music.index', [
            'tracks' => $pageTracks,
            'libraries' => $libraries,
            'currentLibrary' => $current,
            'currentLibraryId' => $libraryId,
            'filter' => $filter,
            'returnTo' => $returnTo,
            'trackOffset' => $offset,
            'page' => $page,
            'totalPages' => $totalPages,
            'pageLabel' => $total === 0
                ? ''
                : __(':from–:to / :total件', [
                    'from' => $offset + 1,
                    'to' => $offset + count($pageTracks),
                    'total' => $total,
                ]),
            'prevUrl' => $page > 1
                ? $this->musicUrl($libraryId, array_filter([
                    'q' => $filter !== '' ? $filter : null,
                    'page' => $page - 1 > 1 ? $page - 1 : null,
                ])).'#music-track-list'
                : null,
            'nextUrl' => $page < $totalPages
                ? $this->musicUrl($libraryId, array_filter([
                    'q' => $filter !== '' ? $filter : null,
                    'page' => $page + 1,
                ])).'#music-track-list'
                : null,
            'maxUploadLabel' => $this->formatBytes($this->music->maxUploadBytes()),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function store(Request $request)
    {
        $userId = (int) $request->user()->id;
        $libraryId = (int) $request->input('library_id', 0) ?: null;
        $returnTo = $this->safeReturnTo(
            $request->input('returnTo'),
            $this->musicUrl((int) ($libraryId ?? 0))
        );
        $wantsJson = $request->expectsJson() || $request->ajax();

        try {
            $created = $this->music->addTracks(
                $userId,
                $request->file('tracks', []) ?: [],
                $request->input('title'),
                $libraryId
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            if ($wantsJson) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        $count = count($created);
        $message = $count === 1
            ? __('曲を追加しました。')
            : __(':count曲を追加しました。', ['count' => $count]);

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'count' => $count,
                'message' => $message,
            ]);
        }

        return $this->redirectWithMessage($returnTo, $message);
    }

    /**
     * Android の共有シートから送られた音声の受け皿。
     * Service Worker が POST を横取りして Cache Storage に置くので、ここは表示だけを担う。
     */
    public function share(Request $request)
    {
        $userId = (int) $request->user()->id;
        $libraries = $this->music->listLibraries($userId);
        $defaultId = (int) collect($libraries)->firstWhere('isDefault', true)['id'];

        return view('music.share', [
            'libraries' => $libraries,
            'defaultLibraryId' => $defaultId,
            'sharedCount' => max(0, (int) $request->query('shared', 0)),
            ...$this->flashFromQuery($request),
        ]);
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

    public function move(Request $request, int $id)
    {
        $libraryId = (int) $request->input('library_id', 0);
        $returnTo = $this->safeReturnTo($request->input('returnTo'), $this->musicUrl($libraryId));
        $moved = $this->music->moveToLibrary((int) $request->user()->id, $id, $libraryId ?: null);
        if (! $moved) {
            return $this->redirectWithMessage($returnTo, __('曲が見つかりません。'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('ライブラリへ移動しました。'));
    }

    public function storeLibrary(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/music');
        try {
            $library = $this->music->createLibrary(
                (int) $request->user()->id,
                (string) $request->input('name', '')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage(
            $this->musicUrl((int) $library['id']),
            __('ライブラリ「:name」を作成しました。', ['name' => $library['name']])
        );
    }

    public function updateLibrary(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), $this->musicUrl($id));
        try {
            $this->music->renameLibrary(
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
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/music');
        try {
            if (! $this->music->deleteLibrary((int) $request->user()->id, $id)) {
                return $this->redirectWithMessage($returnTo, __('ライブラリが見つかりません。'), 'error');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/music', __('ライブラリを削除しました。曲はマイリストへ移動しました。'));
    }

    public function file(Request $request, int $id): StreamedResponse
    {
        return $this->music->stream((int) $request->user()->id, $id);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    private function musicUrl(int $libraryId, array $params = []): string
    {
        $query = array_filter(
            ($libraryId > 0 ? ['library' => $libraryId] : []) + $params,
            static fn ($value): bool => $value !== null && $value !== '' && $value !== false
        );

        return $query === [] ? '/music' : '/music?'.http_build_query($query);
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
