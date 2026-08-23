<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Exceptions\UsageLimitExceededException;
use App\Services\GroupService;
use App\Services\NoteCsvService;
use App\Services\NoteService;
use App\Services\NoteVoiceParseService;
use App\Services\TranslationService;
use App\Services\UserUsageLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NoteController extends Controller
{
    use Concerns\RedirectsWithFlash;
    use Concerns\ParsesVoiceTranscript;

    public function __construct(
        private NoteService $notes,
        private NoteCsvService $noteCsv,
        private GroupService $groups,
        private NoteVoiceParseService $voiceParse,
        private UserUsageLimitService $usageLimits,
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

        $sourceBlob = trim($note->title.' '.$note->body.' '.$this->itemsText($note));
        try {
            $this->usageLimits->consume($request->user(), UserUsageLimitService::FEATURE_TRANSLATE, mb_strlen($sourceBlob));
        } catch (UsageLimitExceededException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 429);
        }

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
            'status' => $filters['status'],
            'date' => $filters['date'] ?: null,
            'year' => $filters['date'] ? null : $filters['year'],
            'month' => $filters['date'] ? null : $filters['month'],
            'page' => $filters['page'],
        ]);
        $pageNotes = $pageResult['items'];
        $pinnedNotes = array_values(array_filter($pageNotes, fn ($n) => ! empty($n['pinned'])));
        $otherNotes = array_values(array_filter($pageNotes, fn ($n) => empty($n['pinned'])));
        $pinnedNoteGroups = $this->notes->groupNotesByMonth($pinnedNotes);
        $otherNoteGroups = $this->notes->groupNotesByMonth($otherNotes);
        $returnTo = $this->notes->buildNotesQuery($filters, [
            'page' => $pageResult['page'],
            'note' => $highlightId > 0 ? $highlightId : null,
        ]);
        $periodValue = (! empty($filters['year']) && ! empty($filters['month']))
            ? sprintf('%04d-%02d', $filters['year'], $filters['month'])
            : '';

        return view('notes.index', [
            'pinnedNotes' => $pinnedNotes,
            'pinnedNoteGroups' => $pinnedNoteGroups,
            'otherNotes' => $otherNotes,
            'otherNoteGroups' => $otherNoteGroups,
            'notePeriodOptions' => $this->notes->notePeriodOptions($userId, $filters['archived']),
            'showArchived' => $filters['archived'],
            'searchQuery' => $filters['q'],
            'filterCategory' => $filters['category'],
            'filterStatus' => $filters['status'] ?? 'all',
            'filterDate' => $filters['date'] ?? '',
            'periodValue' => $periodValue,
            'filters' => $filters,
            'pagination' => $pageResult,
            'highlightId' => $highlightId > 0 ? $highlightId : null,
            'defaultRegisteredDate' => $filters['date'] ?: $this->notes->todayIso(),
            'returnTo' => $returnTo,
            'noteColors' => NoteService::NOTE_COLORS,
            'colorKeys' => NoteService::COLOR_KEYS,
            'noteCategories' => NoteService::NOTE_CATEGORIES,
            'defaultCategory' => NoteService::DEFAULT_CATEGORY,
            'noteAttachmentMaxCount' => $this->notes->maxAttachmentsPerNote(),
            'noteAttachmentMaxSizeLabel' => $this->formatNoteAttachmentSize($this->notes->maxAttachmentBytes()),
            'approvedGroups' => $this->groups->listApprovedForUser($userId),
            'voiceAiReady' => $this->voiceParse->isReady(),
            'voiceAiProvider' => $this->voiceParse->isReady() ? $this->voiceParse->activeProviderLabel() : null,
            'buildNotesQuery' => fn (array $f, array $extra = [], string $path = '/notes') => $this->notes->buildNotesQuery($f, $extra, $path),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function parseVoice(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $transcript = trim((string) $request->input('transcript', ''));
        if ($transcript === '') {
            return response()->json(['ok' => false, 'message' => __('音声テキストが空です。')], 422);
        }

        try {
            $this->usageLimits->consume($request->user(), UserUsageLimitService::FEATURE_LLM_VOICE_NOTE, 1);
        } catch (UsageLimitExceededException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 429);
        }

        return $this->voiceParseJsonResponse(fn () => $this->voiceParse->parse(
            $transcript,
            $this->voiceGroups($userId, $this->groups),
            $this->notes->todayIso()
        ));
    }

    private function formatNoteAttachmentSize(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.').' KB';
        }

        return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.').' MB';
    }

    public function store(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $type = $this->notes->normalizeType($request->input('type'));
        $items = $this->notes->parseChecklistItems($request->input('items', []));

        if ($type === 'checklist' && count($items) === 0) {
            return $this->redirectWithMessage($returnTo, __('チェックリストの項目を1つ以上入力してください'), 'error');
        }
        $hasFiles = collect($request->file('attachments', []) ?: [])
            ->filter(fn ($f) => $f && $f->isValid())
            ->isNotEmpty();
        if (
            $type === 'text'
            && trim((string) $request->input('title')) === ''
            && trim((string) $request->input('body')) === ''
            && ! $hasFiles
        ) {
            return $this->redirectWithMessage($returnTo, __('メモの内容を入力してください'), 'error');
        }

        $updated = null;
        try {
            $created = $this->notes->createNote([
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
            if ($hasFiles) {
                $this->notes->addAttachments((int) $request->user()->id, (int) $created['id'], $request->file('attachments', []));
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('メモを追加しました'));
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

        $updated = null;
        try {
            $updated = $this->notes->updateNote($userId, $id, $patch);
            if ($updated) {
                $removeIds = $request->input('remove_attachment_ids', []);
                if (is_array($removeIds) && $removeIds !== []) {
                    $this->notes->removeAttachments($userId, $id, $removeIds);
                }
                $files = $request->file('attachments', []);
                if ($files) {
                    $this->notes->addAttachments($userId, $id, $files);
                }
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, __('メモが見つかりません'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('メモを更新しました'));
    }

    public function pin(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $this->notes->togglePin((int) $request->user()->id, $id);

        return redirect($returnTo);
    }

    public function complete(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $note = $this->notes->toggleComplete((int) $request->user()->id, $id);
        if (! $note) {
            return $this->redirectWithMessage($returnTo, __('メモが見つかりません'), 'error');
        }

        return $this->redirectWithMessage(
            $returnTo,
            ! empty($note['completed']) ? __('メモを完了にしました') : __('メモを未完了に戻しました')
        );
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
        $note = $this->notes->toggleArchive((int) $request->user()->id, $id);
        if (! $note) {
            return $this->redirectWithMessage($returnTo, __('メモが見つかりません'), 'error');
        }

        if (empty($note['archived'])) {
            // アーカイブ一覧へ戻すと復元後のメモが見えないため、通常一覧へ
            $returnTo = $this->notesUrlWithoutArchived($returnTo);

            return $this->redirectWithMessage($returnTo, __('アーカイブから戻しました'));
        }

        return $this->redirectWithMessage($returnTo, __('アーカイブしました'));
    }

    private function notesUrlWithoutArchived(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '/notes';
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        unset($query['archived']);

        $path = $parts['path'] ?? '/notes';
        if ($query === []) {
            return $path;
        }

        return $path.'?'.http_build_query($query);
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
            return $this->redirectWithMessage($returnTo, __('メモを移動できませんでした'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('メモの日付を変更しました'));
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        if (! $this->notes->deleteNote((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, __('メモが見つかりません'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('メモを削除しました'));
    }

    public function attachmentFile(Request $request, int $id)
    {
        return $this->notes->streamAttachment((int) $request->user()->id, $id, false);
    }

    public function attachmentDownload(Request $request, int $id)
    {
        return $this->notes->streamAttachment((int) $request->user()->id, $id, true);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $userId = (int) $request->user()->id;
        $filters = $this->notes->parseNoteFilters($request->query());
        $csv = $this->noteCsv->export([
            'userId' => $userId,
            'archived' => $filters['archived'],
            'q' => $filters['q'],
            'category' => $filters['category'],
            'status' => $filters['status'],
            'date' => $filters['date'] ?: null,
            'year' => $filters['date'] ? null : $filters['year'],
            'month' => $filters['date'] ? null : $filters['month'],
        ]);
        $filename = 'notes_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(
            static function () use ($csv) {
                echo $csv;
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]
        );
    }

    public function importCsv(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
        ]);

        $content = (string) file_get_contents($request->file('csv_file')->getRealPath());
        if (trim($content) === '') {
            return $this->redirectWithMessage($returnTo, __('CSVファイルが空です'), 'error');
        }

        try {
            $result = $this->noteCsv->import((int) $request->user()->id, $content);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            return $this->redirectWithMessage(
                $returnTo,
                __('CSVのインポートに失敗しました: :msg', ['msg' => $e->getMessage()]),
                'error'
            );
        }

        $message = __('CSVをインポートしました（:created件追加、:skipped件スキップ）。', [
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]);
        if ($result['messages'] !== []) {
            $message .= ' '.implode(' ', array_slice($result['messages'], 0, 3));
        }

        return $this->redirectWithMessage($returnTo, $message);
    }

    public function bulkArchive(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/notes');
        $ids = $this->notes->parseIdList($request->input('ids'));
        $unarchive = $request->boolean('unarchive');
        $count = $this->notes->bulkArchive((int) $request->user()->id, $ids, ! $unarchive);

        if ($unarchive && $count > 0) {
            $returnTo = $this->notesUrlWithoutArchived($returnTo);
        }

        return $this->redirectWithMessage(
            $returnTo,
            $count > 0
                ? ($unarchive ? __(':count件をアーカイブから戻しました', ['count' => $count]) : __(':count件をアーカイブしました', ['count' => $count]))
                : __('対象が選択されていません'),
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
            return $this->redirectWithMessage($returnTo, __('追加する内容を入力してください'), 'error');
        }
        $count = $this->notes->bulkAppend((int) $request->user()->id, $this->notes->parseIdList($request->input('ids')), $text);

        return $this->redirectWithMessage(
            $returnTo,
            $count > 0 ? "{$count}件に追記しました" : '対象が選択されていません',
            $count > 0 ? 'notice' : 'error'
        );
    }
}
