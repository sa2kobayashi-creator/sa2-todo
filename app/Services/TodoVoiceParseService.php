<?php

namespace App\Services;

class TodoVoiceParseService
{
    public function __construct(
        private LlmJsonClient $llm,
    ) {}

    public function isReady(): bool
    {
        return $this->llm->isReady();
    }

    public function activeProviderLabel(): string
    {
        return $this->llm->activeProviderLabel();
    }

    /**
     * @param  list<array{id: int, name: string}>  $groups
     * @return array<string, mixed>
     */
    public function parse(string $transcript, array $groups = [], ?string $today = null): array
    {
        $text = trim($transcript);
        if ($text === '') {
            throw new \InvalidArgumentException(__('音声テキストが空です。'));
        }

        $today = $today ?: now()->toDateString();
        $prompt = $this->buildPrompt($text, $groups, $today);
        $result = $this->llm->completeJson(
            $prompt,
            'You extract Japanese spoken todos into JSON. Output JSON only.'
        );

        $normalized = $this->normalizeParsed($result['decoded'], $groups, $today);
        $normalized['raw'] = $result['decoded'];
        $normalized['provider'] = $result['provider'];

        return $normalized;
    }

    /**
     * @param  list<array{id: int, name: string}>  $groups
     */
    private function buildPrompt(string $transcript, array $groups, string $today): string
    {
        $importance = TodoService::IMPORTANCE_LABELS;
        $categories = TodoService::CATEGORY_LABELS;
        $importanceLines = [];
        foreach ($importance as $slug => $label) {
            $importanceLines[] = "- {$slug} = {$label}";
        }
        $categoryLines = [];
        foreach ($categories as $slug => $label) {
            $categoryLines[] = "- {$slug} = {$label}";
        }
        $groupLines = [];
        foreach ($groups as $group) {
            $id = (int) ($group['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $groupLines[] = '- id='.$id.' name="'.($group['name'] ?? '').'"';
        }
        $groupsBlock = $groupLines ? implode("\n", $groupLines) : '(none)';

        $importanceBlock = implode("\n", $importanceLines);
        $categoryBlock = implode("\n", $categoryLines);

        return <<<PROMPT
Convert a Japanese spoken todo instruction into one JSON object.
Today is {$today} (YYYY-MM-DD). Return ONLY valid JSON.

Importance:
{$importanceBlock}

Categories (ステータス):
{$categoryBlock}

Groups (optional share target):
{$groupsBlock}

JSON schema:
{
  "titles": ["string"],
  "date_mode": "single|range",
  "start_date": "YYYY-MM-DD|null",
  "end_date": "YYYY-MM-DD|null",
  "start_time": "HH:MM|null",
  "end_time": "HH:MM|null",
  "importance": "high|medium|low",
  "category": "task|personal|memo|other",
  "group_id": number|null,
  "split_by_line": true,
  "confidence": "high|medium|low"
}

Rules:
- Split multiple tasks into titles[] when the user lists several items.
- If only one task, titles has one item.
- 明日 / あした → tomorrow from {$today}. 今日 → {$today}.
- If no date mentioned, start_date/end_date = {$today}, date_mode=single.
- date_mode=range only when a period is clearly requested.
- Default importance=medium, category=task.
- Never invent group_id values outside the list.
- start_time/end_time only when spoken.

User utterance:
{$transcript}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  list<array{id: int, name: string}>  $groups
     * @return array<string, mixed>
     */
    private function normalizeParsed(array $decoded, array $groups, string $today): array
    {
        $titlesRaw = $decoded['titles'] ?? $decoded['title'] ?? [];
        if (is_string($titlesRaw)) {
            $titlesRaw = preg_split('/\r\n|\r|\n/', $titlesRaw) ?: [$titlesRaw];
        }
        if (! is_array($titlesRaw)) {
            $titlesRaw = [];
        }
        $titles = [];
        foreach ($titlesRaw as $title) {
            $t = trim((string) $title);
            if ($t !== '') {
                $titles[] = mb_substr($t, 0, 500);
            }
        }
        if ($titles === []) {
            throw new \InvalidArgumentException(__('ToDo の内容を認識できませんでした。'));
        }

        $dateMode = strtolower(trim((string) ($decoded['date_mode'] ?? $decoded['dateMode'] ?? 'single')));
        if (! in_array($dateMode, ['single', 'range'], true)) {
            $dateMode = 'single';
        }

        $startDate = $this->llm->normalizeDate($decoded['start_date'] ?? $decoded['startDate'] ?? null, $today);
        $endDate = $this->llm->normalizeDate($decoded['end_date'] ?? $decoded['endDate'] ?? null, $startDate);
        if ($dateMode === 'single') {
            $endDate = $startDate;
        }
        if ($endDate < $startDate) {
            $endDate = $startDate;
        }

        $importance = strtolower(trim((string) ($decoded['importance'] ?? 'medium')));
        if (! array_key_exists($importance, TodoService::IMPORTANCE_LABELS)) {
            $importance = 'medium';
        }

        $category = strtolower(trim((string) ($decoded['category'] ?? 'task')));
        if (! array_key_exists($category, TodoService::CATEGORY_LABELS)) {
            $category = 'task';
        }

        $validGroupIds = [];
        foreach ($groups as $group) {
            $id = (int) ($group['id'] ?? 0);
            if ($id > 0) {
                $validGroupIds[$id] = true;
            }
        }
        $groupId = $decoded['group_id'] ?? $decoded['groupId'] ?? null;
        $groupId = is_numeric($groupId) ? (int) $groupId : null;
        if ($groupId !== null && ! isset($validGroupIds[$groupId])) {
            $groupId = null;
        }

        $splitByLine = array_key_exists('split_by_line', $decoded)
            ? (bool) $decoded['split_by_line']
            : (array_key_exists('splitByLine', $decoded) ? (bool) $decoded['splitByLine'] : count($titles) > 1);

        return [
            'titles' => $titles,
            'dateMode' => $dateMode,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'startTime' => $this->llm->normalizeTime($decoded['start_time'] ?? $decoded['startTime'] ?? null),
            'endTime' => $this->llm->normalizeTime($decoded['end_time'] ?? $decoded['endTime'] ?? null),
            'importance' => $importance,
            'category' => $category,
            'groupId' => $groupId,
            'splitByLine' => $splitByLine,
            'confidence' => $this->llm->normalizeConfidence($decoded['confidence'] ?? null),
        ];
    }
}
