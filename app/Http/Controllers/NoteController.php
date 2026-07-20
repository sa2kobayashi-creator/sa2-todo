<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Services\GroupService;
use App\Services\NoteService;
use App\Services\TranslationService;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private NoteService $notes,
        private GroupService $groups,
    ) {}

    /**
     * メモのタイトル・本文・チェックリストを翻訳して JSON で返す。
     * target_lang（ja / en）を省略した場合は原文から自動判定する。
     */
    public function translate(Request $request, int $id, TranslationService $translator)
    {
        if (! $translator->isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => 'AI翻訳が設定されていません。設定 > AI設定 からDeepL APIキーを登録してください。',
            ], 422);
        }

        $userId = (int) $request->user()->id;
        $note = $this->notes->findAccessibleNote($userId, $id);
        if (! $note) {
            return response()->json(['ok' => false, 'message' => 'メモが見つかりません'], 404);
        }

        $target = in_array($request->input('target_lang'), ['ja', 'en'], true)
            ? $request->input('target_lang')
            : ($this->containsJapanese($note->title.' '.$note->body.' '.$this->itemsText($note)) ? 'en' : 'ja');
        $source = $target === 'en' ? 'ja' : 'en';

        $items = [];
        foreach ($note->items ?? [] as $item) {
            $items[] = $translator->translate((string) ($item['text'] ?? ''), $source, $target) ?? ($item['text'] ?? '');
        }

        $result = [
            'ok' => true,
            'target' => $target,
            'title' => $note->title !== '' ? ($translator->translate($note->title, $source, $target) ?? $note->title) : '',
            'body' => $note->body ? ($translator->translate($note->body, $source, $target) ?? $note->body) : '',
            'items' => $items,
        ];

        return response()->json($result);
    }

    private function containsJapanese(string $text): bool
    {
        return (bool) preg_match('/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]/u', $text);
    }

    private function itemsText(Note $note): string
    {
        return collect($note->items ?? [])->map(fn ($i) => $i['text'] ?? '')->implode(' ');
    }

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $filters = $this->notes->parseNoteFilters($request->query());
        $highlightId = (int) $request->query('note');
        $pageResult = $this->notes->listNotesPage([
            'userId' => $userId,
            'archived' => $filters['archived'],
            'q' => $filters['q'],
            'category' => $filters['category'],
            'date' => $filters['date'] ?: null,
            'year' => $filters['date'] ? null : $filters['year'],
            'month' => $filters['date'] ? null : $filters['month'],
            'page' => $filters['page'],
        ]);
        $pageNotes = $pageResult['items'];
        $returnTo = $this->notes->buildNotesQuery($filters, [
            'page' => $pageResult['page'],
            'note' => $highlightId > 0 ? $highlightId : null,
        ]);

        return view('notes.index', [
            'pinnedNotes' => array_values(array_filter($pageNotes, fn ($n) => ! empty($n['pinned']))),
            'otherNotes' => array_values(array_filter($pageNotes, fn ($n) => empty($n['pinned']))),
            'showArchived' => $filters['archived'],
            'searchQuery' => $filters['q'],
            'filterCategory' => $filters['category'],
            'filterDate' => $filters['date'] ?? '',
            'periodValue' => sprintf('%04d-%02d', $filters['year'], $filters['month']),
            'filters' => $filters,
            'pagination' => $pageResult,
            'highlightId' => $highlightId > 0 ? $highlightId : null,
            'defaultRegisteredDate' => $filters['date'] ?: $this->notes->todayIso(),
            'returnTo' => $returnTo,
            'noteColors' => NoteService::NOTE_COLORS,
            'colorKeys' => NoteService::COLOR_KEYS,
            'noteCategories' => NoteService::NOTE_CATEGORIES,
            'defaultCategory' => NoteService::DEFAULT_CATEGORY,
            'approvedGroups' => $this->groups->listApprovedForUser($userId),
            'buildNotesQuery' => fn (array $f, array $extra = []) => $this->notes->buildNotesQuery($f, $extra),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function store(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $type = $this->notes->normalizeType($request->input('type'));
        $items = $this->notes->parseChecklistItems($request->input('items', []));

        if ($type === 'checklist' && count($items) === 0) {
            return $this->redirectWithMessage($returnTo, 'チェックリストの項目を1つ以上入力してください', 'error');
        }
        if ($type === 'text' && trim((string) $request->input('title')) === '' && trim((string) $request->input('body')) === '') {
            return $this->redirectWithMessage($returnTo, 'メモの内容を入力してください', 'error');
        }

        try {
            $this->notes->createNote([
                'userId' => (int) $request->user()->id,
                'groupId' => $request->input('groupId'),
                'title' => $request->input('title'),
                'body' => $request->input('body'),
                'color' => $request->input('color'),
                'category' => $request->input('category'),
                'type' => $type,
                'items' => $items,
                'registeredDate' => $request->input('registeredDate'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, 'メモを追加しました');
    }

    public function update(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $userId = (int) $request->user()->id;
        $patch = [
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'color' => $request->input('color'),
            'category' => $request->input('category'),
            'type' => $request->input('type'),
            'registeredDate' => $request->input('registeredDate'),
            'groupId' => $request->input('groupId'),
        ];
        if ($request->has('items')) {
            $patch['items'] = $request->input('items');
        }

        try {
            $updated = $this->notes->updateNote($userId, $id, $patch);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, 'メモが見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, 'メモを更新しました');
    }

    public function pin(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $this->notes->togglePin((int) $request->user()->id, $id);

        return redirect($returnTo);
    }

    public function reorder(Request $request)
    {
        $noteIds = $request->input('noteIds', []);
        if (! is_array($noteIds)) {
            return response()->json(['ok' => false, 'message' => '不正なリクエストです'], 422);
        }

        try {
            $this->notes->reorderNotes((int) $request->user()->id, $noteIds);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function archive(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $this->notes->toggleArchive((int) $request->user()->id, $id);

        return redirect($returnTo);
    }

    public function reschedule(Request $request, int $id)
    {
        $userId = (int) $request->user()->id;
        $date = (string) ($request->input('date') ?: $request->json('date') ?: '');
        $updated = $this->notes->rescheduleNote($userId, $id, $date);
        if ($request->expectsJson() || $request->ajax()) {
            if (! $updated) {
                return response()->json(['ok' => false, 'message' => 'メモを移動できませんでした'], 422);
            }

            return response()->json(['ok' => true, 'note' => $updated]);
        }

        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        if (! $updated) {
            return $this->redirectWithMessage($returnTo, 'メモを移動できませんでした', 'error');
        }

        return $this->redirectWithMessage($returnTo, 'メモの日付を変更しました');
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        if (! $this->notes->deleteNote((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, 'メモが見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, 'メモを削除しました');
    }

    public function bulkArchive(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $ids = $this->notes->parseIdList($request->input('ids'));
        $unarchive = $request->boolean('unarchive');
        $count = $this->notes->bulkArchive((int) $request->user()->id, $ids, ! $unarchive);

        return $this->redirectWithMessage(
            $returnTo,
            $count > 0
                ? ($unarchive ? "{$count}件をアーカイブから戻しました" : "{$count}件をアーカイブしました")
                : '対象が選択されていません',
            $count > 0 ? 'notice' : 'error'
        );
    }

    public function bulkDelete(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $count = $this->notes->bulkDelete((int) $request->user()->id, $this->notes->parseIdList($request->input('ids')));

        return $this->redirectWithMessage(
            $returnTo,
            $count > 0 ? "{$count}件を削除しました" : '対象が選択されていません',
            $count > 0 ? 'notice' : 'error'
        );
    }

    public function bulkAppend(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $text = trim((string) $request->input('appendText'));
        if ($text === '') {
            return $this->redirectWithMessage($returnTo, '追加する内容を入力してください', 'error');
        }
        $count = $this->notes->bulkAppend((int) $request->user()->id, $this->notes->parseIdList($request->input('ids')), $text);

        return $this->redirectWithMessage(
            $returnTo,
            $count > 0 ? "{$count}件に追記しました" : '対象が選択されていません',
            $count > 0 ? 'notice' : 'error'
        );
    }
}
