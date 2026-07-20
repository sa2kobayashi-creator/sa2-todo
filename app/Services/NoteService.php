<?php

namespace App\Services;

use App\Models\Note;
use Carbon\Carbon;

class NoteService
{
    public const PAGE_SIZE = 20;

    public const NOTE_COLORS = [
        'default' => ['label' => '白', 'bg' => '#ffffff', 'border' => '#dadce0'],
        'coral' => ['label' => 'コーラル', 'bg' => '#f28b82', 'border' => '#e8716a'],
        'peach' => ['label' => 'ピーチ', 'bg' => '#fbbc04', 'border' => '#f0b429'],
        'sand' => ['label' => 'サンド', 'bg' => '#fff475', 'border' => '#f5e663'],
        'mint' => ['label' => 'ミント', 'bg' => '#ccff90', 'border' => '#b8e986'],
        'sage' => ['label' => 'セージ', 'bg' => '#a7ffeb', 'border' => '#8eedd8'],
        'fog' => ['label' => 'フォグ', 'bg' => '#cbf0f8', 'border' => '#a8e4f0'],
        'storm' => ['label' => 'ストーム', 'bg' => '#aecbfa', 'border' => '#8ab4f8'],
        'dusk' => ['label' => 'ダスク', 'bg' => '#d7aefb', 'border' => '#c58af9'],
        'blossom' => ['label' => 'ブロッサム', 'bg' => '#fdcfe8', 'border' => '#f8b4d9'],
        'clay' => ['label' => 'クレイ', 'bg' => '#e6c9a8', 'border' => '#d4b896'],
        'chalk' => ['label' => 'チョーク', 'bg' => '#e8eaed', 'border' => '#dadce0'],
    ];

    public const COLOR_KEYS = ['default', 'coral', 'peach', 'sand', 'mint', 'sage', 'fog', 'storm', 'dusk', 'blossom', 'clay', 'chalk'];

    public const NOTE_CATEGORIES = [
        'personal' => '個人',
        'work' => '仕事',
        'money' => 'お金',
        'idea' => 'アイデア',
        'word' => '言葉',
        'cooking' => '料理',
        'hobby' => '趣味',
        'other' => 'その他',
    ];

    public const DEFAULT_CATEGORY = 'personal';

    public function __construct(
        private GroupService $groups,
    ) {}

    public function resolveGroupIdForUser(int $userId, mixed $groupId): ?int
    {
        if ($groupId === null || $groupId === '' || $groupId === '0') {
            return null;
        }
        $id = (int) $groupId;
        if ($id <= 0 || ! $this->groups->userBelongsToApprovedGroup($userId, $id)) {
            throw new \InvalidArgumentException(__('共有先のグループが無効です。'));
        }

        return $id;
    }

    public function userCanAccessNote(int $userId, Note|array|null $note): bool
    {
        if ($note === null) {
            return false;
        }
        $ownerId = is_array($note) ? (int) ($note['userId'] ?? 0) : (int) $note->user_id;
        $groupId = is_array($note) ? ($note['groupId'] ?? null) : $note->group_id;
        if ($groupId) {
            return $this->groups->userBelongsToApprovedGroup($userId, (int) $groupId);
        }

        return $ownerId === $userId;
    }

    public function findAccessibleNote(int $userId, int $id): ?Note
    {
        $note = Note::query()->with('group')->find($id);
        if (! $note || ! $this->userCanAccessNote($userId, $note)) {
            return null;
        }

        return $note;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\Note> */
    public function visibleNotesQuery(int $userId)
    {
        $groupIds = $this->groups->approvedGroupIdsForUser($userId);

        return Note::query()->with('group')->where(function ($q) use ($userId, $groupIds) {
            $q->where(function ($personal) use ($userId) {
                $personal->where('user_id', $userId)->whereNull('group_id');
            });
            if ($groupIds !== []) {
                $q->orWhereIn('group_id', $groupIds);
            }
        });
    }

    /** @param array<string, mixed> $query */
    public function parseNoteFilters(array $query): array
    {
        $period = $this->parsePeriod($query['period'] ?? null);
        $page = max(1, (int) ($query['page'] ?? 1));
        $archived = ($query['archived'] ?? '') === '1' || ($query['archived'] ?? '') === 'true';
        $q = is_string($query['q'] ?? null) ? trim($query['q']) : '';
        $date = is_string($query['date'] ?? null) ? $this->normalizeRegisteredDate(trim($query['date'])) : null;
        $category = $this->normalizeCategoryFilter($query['category'] ?? null);

        return [
            'year' => $period['year'],
            'month' => $period['month'],
            'page' => $page,
            'archived' => $archived,
            'q' => $q,
            'date' => $date,
            'category' => $category,
        ];
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $extra */
    public function buildNotesQuery(array $filters, array $extra = []): string
    {
        $params = [];
        if ($filters['archived'] ?? false) {
            $params['archived'] = '1';
        }
        if (! empty($filters['q'])) {
            $params['q'] = $filters['q'];
        }
        if (! empty($filters['category'])) {
            $params['category'] = $filters['category'];
        }
        if (! empty($filters['date'])) {
            $params['date'] = $filters['date'];
        } else {
            $params['period'] = sprintf('%04d-%02d', $filters['year'], $filters['month']);
        }
        if (($extra['page'] ?? 0) > 1) {
            $params['page'] = (string) $extra['page'];
        }
        if (! empty($extra['note'])) {
            $params['note'] = (string) $extra['note'];
        }
        $qs = http_build_query($params);

        return '/notes'.($qs ? '?'.$qs : '');
    }

    /** @param array<string, mixed> $options */
    public function listNotesPage(array $options = []): array
    {
        $list = $this->listNotes($options);

        return $this->paginateList($list, (int) ($options['page'] ?? 1));
    }

    /** @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listNotes(array $options = []): array
    {
        $userId = (int) ($options['userId'] ?? 0);
        if ($userId <= 0) {
            return [];
        }

        $archived = ($options['archived'] ?? false) === true;
        $q = strtolower(trim((string) ($options['q'] ?? '')));
        $date = $this->normalizeRegisteredDate($options['date'] ?? null);
        $year = isset($options['year']) ? (int) $options['year'] : null;
        $month = isset($options['month']) ? (int) $options['month'] : null;
        $category = $this->normalizeCategoryFilter($options['category'] ?? null);

        $notes = $this->visibleNotesQuery($userId)->get()->map(fn (Note $n) => $this->toArray($n));
        $list = $notes->filter(fn ($note) => ($note['archived'] ?? false) === $archived)->values()->all();

        if ($date) {
            $list = array_values(array_filter($list, fn ($note) => $this->getRegisteredDate($note) === $date));
        } elseif ($year && $month >= 1 && $month <= 12) {
            $list = $this->filterNotesByMonth($list, $year, $month);
        }

        if ($category !== '') {
            $list = array_values(array_filter(
                $list,
                fn ($note) => ($note['category'] ?? self::DEFAULT_CATEGORY) === $category
            ));
        }

        if ($q !== '') {
            $list = array_values(array_filter($list, function ($note) use ($q) {
                $parts = [$note['title'] ?? '', $note['body'] ?? ''];
                foreach ($note['items'] ?? [] as $item) {
                    $parts[] = $item['text'] ?? '';
                }

                return str_contains(strtolower(implode("\n", $parts)), $q);
            }));
        }

        return $this->sortNotesForDisplay($list);
    }

    /** @return list<array<string, mixed>> */
    public function listActiveNotesForCalendar(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return $this->visibleNotesQuery($userId)
            ->where('archived', false)
            ->get()
            ->map(fn (Note $n) => $this->toArray($n))
            ->filter(fn ($note) => (bool) $this->getRegisteredDate($note))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function listNotesForMonth(int $userId, int $year, int $month): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, Carbon::create($year, $month, 1)->daysInMonth);

        return array_values(array_filter(
            $this->listActiveNotesForCalendar($userId),
            fn ($note) => ($d = $this->getRegisteredDate($note)) && $d >= $monthStart && $d <= $monthEnd
        ));
    }

    /** @param array<string, mixed> $input */
    public function createNote(array $input): array
    {
        $userId = (int) ($input['userId'] ?? 0);
        if ($userId <= 0) {
            throw new \InvalidArgumentException(__('ユーザーが無効です。'));
        }
        $groupId = $this->resolveGroupIdForUser($userId, $input['groupId'] ?? null);

        $type = $this->normalizeType($input['type'] ?? 'text');
        $items = $this->parseChecklistItems($input['items'] ?? []);
        if ($type === 'checklist' && count($items) === 0 && ! empty($input['body'])) {
            $items = $this->parseChecklistFromBody((string) $input['body']);
        }

        $pinned = ($input['pinned'] ?? false) === true;
        $note = Note::create([
            'user_id' => $userId,
            'group_id' => $groupId,
            'title' => trim((string) ($input['title'] ?? '')),
            'body' => $type === 'text' ? trim((string) ($input['body'] ?? '')) : '',
            'color' => $this->normalizeColor($input['color'] ?? 'default'),
            'pinned' => $pinned,
            'sort_order' => $this->nextFrontSortOrder($userId, $pinned, false),
            'archived' => false,
            'type' => $type,
            'category' => $this->normalizeCategory($input['category'] ?? null),
            'items' => $type === 'checklist' ? $items : [],
            'registered_date' => $this->normalizeRegisteredDate($input['registeredDate'] ?? null) ?? $this->todayIso(),
        ]);
        $note->load('group');

        return $this->toArray($note);
    }

    /** @param array<string, mixed> $patch */
    public function updateNote(int $userId, int $id, array $patch): ?array
    {
        $note = $this->findAccessibleNote($userId, $id);
        if (! $note) {
            return null;
        }

        if (isset($patch['title']) && is_string($patch['title'])) {
            $note->title = trim($patch['title']);
        }
        if (isset($patch['body']) && is_string($patch['body'])) {
            $note->body = trim($patch['body']);
        }
        if (array_key_exists('color', $patch)) {
            $note->color = $this->normalizeColor($patch['color']);
        }
        if (array_key_exists('category', $patch)) {
            $note->category = $this->normalizeCategory($patch['category']);
        }
        if (array_key_exists('pinned', $patch)) {
            $note->pinned = $patch['pinned'] === true;
        }
        if (array_key_exists('archived', $patch)) {
            $note->archived = $patch['archived'] === true;
        }
        if (array_key_exists('registeredDate', $patch)) {
            $note->registered_date = $this->normalizeRegisteredDate($patch['registeredDate']) ?? $note->registered_date;
        }
        if (array_key_exists('groupId', $patch) && (int) $note->user_id === $userId) {
            $note->group_id = $this->resolveGroupIdForUser($userId, $patch['groupId']);
        }

        $requestedType = array_key_exists('type', $patch) ? $this->normalizeType($patch['type']) : null;
        $hasItems = isset($patch['items']) && is_array($patch['items']);

        if ($requestedType === 'text') {
            $note->type = 'text';
            $note->items = [];
        } elseif ($hasItems || $requestedType === 'checklist') {
            if ($hasItems) {
                $note->items = $this->parseChecklistItems($patch['items']);
            }
            $note->type = 'checklist';
            $note->body = '';
        }

        $note->save();
        $note->load('group');

        return $this->toArray($note);
    }

    public function togglePin(int $userId, int $id): ?array
    {
        $note = $this->findAccessibleNote($userId, $id);
        if (! $note) {
            return null;
        }
        $note->pinned = ! $note->pinned;
        $note->sort_order = $this->nextFrontSortOrder((int) $note->user_id, $note->pinned, $note->archived);
        $note->save();
        $note->load('group');

        return $this->toArray($note);
    }

    /** @param list<int|string> $orderedIds */
    public function reorderNotes(int $userId, array $orderedIds): bool
    {
        $orderedIds = array_values(array_unique(array_filter(array_map('intval', $orderedIds))));
        if ($orderedIds === []) {
            throw new \InvalidArgumentException('並び替えるメモがありません');
        }

        $notes = $this->visibleNotesQuery($userId)->whereIn('id', $orderedIds)->get()->keyBy('id');
        if ($notes->count() !== count($orderedIds)) {
            throw new \InvalidArgumentException('無効なメモが含まれています');
        }

        $pinnedFlags = $notes->pluck('pinned')->unique();
        $archivedFlags = $notes->pluck('archived')->unique();
        if ($pinnedFlags->count() > 1 || $archivedFlags->count() > 1) {
            throw new \InvalidArgumentException('同じグループのメモのみ並び替えできます');
        }

        $pinned = (bool) $pinnedFlags->first();
        $archived = (bool) $archivedFlags->first();

        $fullIds = $this->visibleNotesQuery($userId)
            ->where('pinned', $pinned)
            ->where('archived', $archived)
            ->orderBy('sort_order')
            ->orderByDesc('registered_date')
            ->orderByDesc('updated_at')
            ->pluck('id')
            ->all();

        $orderedSet = array_flip($orderedIds);
        $positions = [];
        foreach ($fullIds as $index => $id) {
            if (isset($orderedSet[$id])) {
                $positions[] = $index;
            }
        }

        if (count($positions) !== count($orderedIds)) {
            throw new \InvalidArgumentException('無効なメモが含まれています');
        }

        foreach ($positions as $i => $pos) {
            $fullIds[$pos] = $orderedIds[$i];
        }

        foreach ($fullIds as $index => $id) {
            Note::query()->where('id', $id)->update([
                'sort_order' => ($index + 1) * 10,
            ]);
        }

        return true;
    }

    public function toggleArchive(int $userId, int $id): ?array
    {
        $note = $this->findAccessibleNote($userId, $id);
        if (! $note) {
            return null;
        }
        $note->archived = ! $note->archived;
        if ($note->archived) {
            $note->pinned = false;
        }
        $note->sort_order = $this->nextFrontSortOrder((int) $note->user_id, $note->pinned, $note->archived);
        $note->save();
        $note->load('group');

        return $this->toArray($note);
    }

    public function deleteNote(int $userId, int $id): bool
    {
        $note = $this->findAccessibleNote($userId, $id);
        if (! $note) {
            return false;
        }

        return (bool) $note->delete();
    }

    public function rescheduleNote(int $userId, int $id, string $newDate): ?array
    {
        $normalized = $this->normalizeRegisteredDate($newDate);
        if (! $normalized) {
            return null;
        }

        return $this->updateNote($userId, $id, ['registeredDate' => $normalized]);
    }

    /** @param list<int> $ids */
    public function bulkArchive(int $userId, array $ids, bool $archived = true): int
    {
        $count = 0;
        foreach (array_unique(array_filter($ids)) as $id) {
            $note = $this->findAccessibleNote($userId, (int) $id);
            if (! $note) {
                continue;
            }
            $note->archived = $archived;
            if ($archived) {
                $note->pinned = false;
            }
            $note->save();
            $count++;
        }

        return $count;
    }

    /** @param list<int> $ids */
    public function bulkDelete(int $userId, array $ids): int
    {
        $count = 0;
        foreach (array_unique(array_filter($ids)) as $id) {
            if ($this->deleteNote($userId, (int) $id)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<int> $ids */
    public function bulkAppend(int $userId, array $ids, string $appendText): int
    {
        $text = trim($appendText);
        if ($text === '') {
            return 0;
        }
        $count = 0;
        foreach (array_unique(array_filter($ids)) as $id) {
            $note = $this->findAccessibleNote($userId, (int) $id);
            if (! $note || ! $this->appendToNoteModel($note, $text)) {
                continue;
            }
            $note->save();
            $count++;
        }

        return $count;
    }

    /** @param array<string, mixed> $note */
    public function getRegisteredDate(array $note): string
    {
        return $this->normalizeRegisteredDate($note['registeredDate'] ?? null)
            ?? $this->registeredDateFromCreatedAt($note['createdAt'] ?? null);
    }

    /** @param array<string, mixed> $note */
    public function getDisplayTitle(array $note): string
    {
        $title = trim((string) ($note['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        $preview = $this->getPreviewText($note);
        if ($preview !== '') {
            return trim(explode("\n", $preview)[0]);
        }

        return '（無題）';
    }

    /** @param array<string, mixed> $note */
    public function formatNoteTooltip(array $note): string
    {
        $title = $this->getDisplayTitle($note);
        $date = $this->getRegisteredDate($note);
        $preview = $this->getPreviewText($note);
        $body = $preview && $preview !== $title ? trim(explode("\n", $preview)[0]) : '—';

        return "{$title}\n登録日: {$date}\n{$body}";
    }

    /** @param mixed $raw @return list<int> */
    public function parseIdList(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $list = is_array($raw) ? $raw : [$raw];

        return array_values(array_unique(array_filter(array_map('intval', $list), fn ($id) => $id > 0)));
    }

    public function normalizeColor(?string $value): string
    {
        return in_array($value, self::COLOR_KEYS, true) ? $value : 'default';
    }

    public function normalizeType(?string $value): string
    {
        return $value === 'checklist' ? 'checklist' : 'text';
    }

    public function normalizeCategory(?string $value): string
    {
        return array_key_exists((string) $value, self::NOTE_CATEGORIES)
            ? (string) $value
            : self::DEFAULT_CATEGORY;
    }

    /** フィルタ用。空文字は「すべて」。 */
    public function normalizeCategoryFilter(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        $key = trim($value);

        return array_key_exists($key, self::NOTE_CATEGORIES) ? $key : '';
    }

    public function categoryLabel(?string $value): string
    {
        $key = $this->normalizeCategory($value);

        return self::NOTE_CATEGORIES[$key];
    }

    /** @param mixed $raw @return list<array{id: int, text: string, checked: bool}> */
    public function parseChecklistItems(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            if (! is_array($item)) {
                return null;
            }
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                return null;
            }

            return [
                'id' => (int) ($item['id'] ?? 0),
                'text' => $text,
                'checked' => ($item['checked'] ?? false) === true || ($item['checked'] ?? '') === '1' || ($item['checked'] ?? '') === 'true',
            ];
        }, $raw)));
    }

    public function todayIso(): string
    {
        return Carbon::now(config('app.timezone', 'Asia/Tokyo'))->format('Y-m-d');
    }

    public function toArray(Note $note): array
    {
        return [
            'id' => $note->id,
            'userId' => $note->user_id,
            'groupId' => $note->group_id,
            'groupName' => $note->relationLoaded('group') ? $note->group?->name : null,
            'shareLabel' => $note->group_id
                ? ($note->relationLoaded('group') ? ($note->group?->name ?? __('グループ')) : __('グループ'))
                : __('個人（自分のみ）'),
            'title' => $note->title,
            'body' => $note->body,
            'color' => $note->color,
            'pinned' => $note->pinned,
            'sortOrder' => (int) $note->sort_order,
            'archived' => $note->archived,
            'type' => $note->type,
            'category' => $this->normalizeCategory($note->category ?? null),
            'items' => $note->items ?? [],
            'registeredDate' => $note->registered_date?->format('Y-m-d'),
            'createdAt' => $note->created_at?->toIso8601String(),
            'updatedAt' => $note->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $note */
    private function getPreviewText(array $note): string
    {
        if (($note['type'] ?? '') === 'checklist' && ! empty($note['items'])) {
            foreach ($note['items'] as $item) {
                if (empty($item['checked'])) {
                    return (string) ($item['text'] ?? '');
                }
            }

            return (string) ($note['items'][0]['text'] ?? '');
        }

        return (string) ($note['body'] ?? '');
    }

    private function appendToNoteModel(Note $note, string $text): bool
    {
        if ($note->type === 'checklist') {
            $items = $note->items ?? [];
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $line = preg_replace('/^[-*・•]\s*/u', '', $line) ?? $line;
                $line = preg_replace('/^\[[ xX]?\]\s*/u', '', $line) ?? $line;
                $items[] = ['id' => time() + count($items), 'text' => $line, 'checked' => false];
            }
            if (count($items) === count($note->items ?? [])) {
                return false;
            }
            $note->items = $items;

            return true;
        }

        $note->body = $note->body ? "{$note->body}\n{$text}" : $text;

        return true;
    }

    private function nextFrontSortOrder(int $userId, bool $pinned, bool $archived): int
    {
        $min = Note::query()
            ->where('user_id', $userId)
            ->where('pinned', $pinned)
            ->where('archived', $archived)
            ->min('sort_order');

        return $min === null ? 0 : ((int) $min - 10);
    }

    /** @param list<array<string, mixed>> $list */
    private function sortNotesForDisplay(array $list): array
    {
        usort($list, function ($a, $b) {
            if (($a['pinned'] ?? false) !== ($b['pinned'] ?? false)) {
                return ($a['pinned'] ?? false) ? -1 : 1;
            }
            $orderCmp = ((int) ($a['sortOrder'] ?? 0)) <=> ((int) ($b['sortOrder'] ?? 0));
            if ($orderCmp !== 0) {
                return $orderCmp;
            }
            $dateCmp = strcmp($this->getRegisteredDate($b), $this->getRegisteredDate($a));
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? ''));
        });

        return $list;
    }

    /** @param list<array<string, mixed>> $list @return list<array<string, mixed>> */
    private function filterNotesByMonth(array $list, int $year, int $month): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, Carbon::create($year, $month, 1)->daysInMonth);

        return array_values(array_filter($list, function ($note) use ($monthStart, $monthEnd) {
            $date = $this->getRegisteredDate($note);

            return $date >= $monthStart && $date <= $monthEnd;
        }));
    }

    /** @param list<array<string, mixed>> $list */
    private function paginateList(array $list, int $page, int $perPage = self::PAGE_SIZE): array
    {
        $total = count($list);
        $totalPages = max(1, (int) ceil($total / $perPage) ?: 1);
        $safePage = min(max(1, $page), $totalPages);
        $start = ($safePage - 1) * $perPage;

        return [
            'items' => array_slice($list, $start, $perPage),
            'page' => $safePage,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }

    /** @return list<array{id: int, text: string, checked: bool}> */
    private function parseChecklistFromBody(string $body): array
    {
        $items = [];
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $checked = (bool) preg_match('/^\[[xX]\]/', $line);
            $text = preg_replace('/^[-*・•]\s*/u', '', $line) ?? $line;
            $text = preg_replace('/^\[[ xX]?\]\s*/u', '', $text) ?? $text;
            $items[] = ['id' => time() + count($items), 'text' => trim($text), 'checked' => $checked];
        }

        return $items;
    }

    private function parsePeriod(mixed $value): array
    {
        $now = Carbon::now(config('app.timezone', 'Asia/Tokyo'));
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)) {
            [$y, $m] = array_map('intval', explode('-', $value));
            if ($y >= 1970 && $m >= 1 && $m <= 12) {
                return ['year' => $y, 'month' => $m];
            }
        }

        return ['year' => $year, 'month' => $month];
    }

    private function normalizeRegisteredDate(?string $value): ?string
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function registeredDateFromCreatedAt(?string $createdAt): string
    {
        if ($createdAt && preg_match('/^\d{4}-\d{2}-\d{2}/', $createdAt, $m)) {
            return $m[0];
        }

        return $this->todayIso();
    }
}
