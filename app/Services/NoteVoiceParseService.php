<?php

namespace App\Services;

class NoteVoiceParseService
{
    public function __construct(private LlmJsonClient $llm) {}

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
            'You extract Japanese spoken notes into JSON. Output JSON only.'
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
        $categoryLines = [];
        foreach (NoteService::NOTE_CATEGORIES as $slug => $label) {
            $categoryLines[] = "- {$slug} = {$label}";
        }
        $colorLines = [];
        foreach (NoteService::COLOR_KEYS as $key) {
            $label = NoteService::NOTE_COLORS[$key]['label'] ?? $key;
            $colorLines[] = "- {$key} = {$label}";
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
        $categoryBlock = implode("\n", $categoryLines);
        $colorBlock = implode("\n", $colorLines);

        return <<<PROMPT
Convert a Japanese spoken memo/note into one JSON object.
Today is {$today} (YYYY-MM-DD). Return ONLY valid JSON.

Categories:
{$categoryBlock}

Colors:
{$colorBlock}

Groups:
{$groupsBlock}

JSON schema:
{
  "type": "text|checklist",
  "title": "string|null",
  "body": "string|null",
  "items": [{ "text": "string", "checked": false }],
  "category": "personal|work|money|idea|word|cooking|hobby|other",
  "color": "default|coral|peach|sand|mint|sage|fog|storm|dusk|blossom|clay|chalk",
  "registered_date": "YYYY-MM-DD",
  "group_id": number|null,
  "confidence": "high|medium|low"
}

Rules:
- Use type=checklist when the user lists shopping/todo-like items (買い物リスト, やること, etc.).
- For checklist, put entries in items[]; title may be short summary; body null.
- For text notes, put main content in body; optional short title.
- Default category=personal, color=default, registered_date={$today}.
- Never invent group_id values outside the list.

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
        $type = strtolower(trim((string) ($decoded['type'] ?? 'text')));
        if (! in_array($type, ['text', 'checklist'], true)) {
            $type = 'text';
        }

        $title = trim((string) ($decoded['title'] ?? ''));
        $title = $title !== '' ? mb_substr($title, 0, 200) : null;
        $body = trim((string) ($decoded['body'] ?? ''));
        $body = $body !== '' ? mb_substr($body, 0, 10000) : null;

        $items = [];
        $rawItems = $decoded['items'] ?? [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                if (is_string($item)) {
                    $text = trim($item);
                    if ($text !== '') {
                        $items[] = ['text' => mb_substr($text, 0, 500), 'checked' => false];
                    }
                    continue;
                }
                if (! is_array($item)) {
                    continue;
                }
                $text = trim((string) ($item['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $items[] = [
                    'text' => mb_substr($text, 0, 500),
                    'checked' => (bool) ($item['checked'] ?? false),
                ];
            }
        }

        if ($type === 'checklist' && $items === []) {
            // Fallback: body lines as checklist
            if ($body) {
                foreach (preg_split('/\r\n|\r|\n|、|,/', $body) ?: [] as $line) {
                    $line = trim((string) $line);
                    if ($line !== '') {
                        $items[] = ['text' => mb_substr($line, 0, 500), 'checked' => false];
                    }
                }
            }
            if ($items === []) {
                $type = 'text';
            } else {
                $body = null;
            }
        }

        if ($type === 'text' && $title === null && $body === null) {
            throw new \InvalidArgumentException(__('メモの内容を認識できませんでした。'));
        }

        $category = strtolower(trim((string) ($decoded['category'] ?? NoteService::DEFAULT_CATEGORY)));
        if (! array_key_exists($category, NoteService::NOTE_CATEGORIES)) {
            $category = NoteService::DEFAULT_CATEGORY;
        }

        $color = strtolower(trim((string) ($decoded['color'] ?? 'default')));
        if (! in_array($color, NoteService::COLOR_KEYS, true)) {
            $color = 'default';
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

        return [
            'type' => $type,
            'title' => $title,
            'body' => $type === 'checklist' ? null : $body,
            'items' => $type === 'checklist' ? $items : [],
            'category' => $category,
            'color' => $color,
            'registeredDate' => $this->llm->normalizeDate(
                $decoded['registered_date'] ?? $decoded['registeredDate'] ?? null,
                $today
            ),
            'groupId' => $groupId,
            'confidence' => $this->llm->normalizeConfidence($decoded['confidence'] ?? null),
        ];
    }
}
