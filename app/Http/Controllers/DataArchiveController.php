<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Services\DatabaseRecordArchiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DataArchiveController extends Controller
{
    use RedirectsWithFlash;

    public function index(Request $request, DatabaseRecordArchiveService $archives): View
    {
        $userId = (int) $request->user()->id;
        $q = trim((string) $request->query('q', ''));
        $rows = $archives->search($userId, $q !== '' ? $q : null);

        return view('archives.index', array_merge($this->flashFromQuery($request), [
            'q' => $q,
            'rows' => $rows,
            'candidates' => $archives->upcomingCandidates($userId),
        ]));
    }

    public function restore(Request $request, int $id, DatabaseRecordArchiveService $archives): RedirectResponse
    {
        try {
            $model = $archives->restore((int) $request->user()->id, $id);
            $label = $model instanceof \App\Models\Todo ? __('Todo') : __('メモ');

            return $this->redirectWithMessage('/archives', __('アーカイブから :kind を戻しました。', ['kind' => $label]));
        } catch (\Throwable $e) {
            return $this->redirectWithMessage('/archives', $e->getMessage() ?: __('復元に失敗しました。'), 'error');
        }
    }

    public function keep(Request $request, DatabaseRecordArchiveService $archives): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:todos,notes'],
            'id' => ['required', 'integer', 'min:1'],
            'keep' => ['required', 'boolean'],
        ]);

        $ok = $archives->setKeepOnServer(
            (int) $request->user()->id,
            $data['type'],
            (int) $data['id'],
            (bool) $data['keep'],
        );

        if (! $ok) {
            return $this->redirectWithMessage('/archives', __('対象が見つかりませんでした。'), 'error');
        }

        return $this->redirectWithMessage('/archives', $data['keep']
            ? __('サーバーに残す設定にしました。')
            : __('長期保存の対象に戻しました。'));
    }
}
