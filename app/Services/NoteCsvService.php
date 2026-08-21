<?php

namespace App\Services;

use App\Models\Note;

class NoteCsvService
{
    /** @var list<string> */
    public const HEADERS = [
        'タイトル',
        '本文',
        '種別',
        'カテゴリー',
        '色',
        '登録日',
        'ピン留め',
        '完了',
        'アーカイブ',
        'チェックリスト',
    ];

    /** @var array<string, string> 別名ヘッダー => 正規ヘッダー */
    private const HEADER_ALIASES = [
        'title' => 'タイトル',
        'body' => '本文',
        'type' => '種別',
        'category' => 'カテゴリー',
        'カテゴリ' => 'カテゴリー',
        'color' => '色',
        'registered_date' => '登録日',
        'registereddate' => '登録日',
        '登録日付' => '登録日',
        'pinned' => 'ピン留め',
        'pin' => 'ピン留め',
        'completed' => '完了',
        'done' => '完了',
        'archived' => 'アーカイブ',
        'items' => 'チェックリスト',
        'checklist' => 'チェックリスト',
    ];

    public function __construct(private NoteService $notes) {}

    /**
     * @param array<string, mixed> $filters NoteService::listNotes と同じオプション
     */
    public function export(array $filters): string
    {
        $notes = $this->notes->listNotes($filters);
        $lines = [self::HEADERS];
        foreach ($notes as $note) {
            $type = (($note['type'] ?? 'text') === 'checklist') ? 'checklist' : 'text';
            $lines[] = [
                (string) ($note['title'] ?? ''),
                $type === 'text' ? (string) ($note['body'] ?? '') : '',
                $type,
                (string) ($note['category'] ?? NoteService::DEFAULT_CATEGORY),
                (string) ($note['color'] ?? 'default'),
                (string) ($note['registeredDate'] ?? ''),
                ! empty($note['pinned']) ? '1' : '0',
                ! empty($note['completed']) ? '1' : '0',
                ! empty($note['archived']) ? '1' : '0',
                $type === 'checklist' ? $this->itemsToCsvCell($note['items'] ?? []) : '',
            ];
        }

        return $this->toCsv($lines);
    }

    /**
     * @return array{created: int, skipped: int, messages: list<string>}
     */
    public function import(int $userId, string $content): array
    {
        $rows = $this->parseCsvRows($content);
        if ($rows === []) {
            throw new \InvalidArgumentException(__('CSVファイルが空です'));
        }

        $header = array_shift($rows);
        $indexes = $this->mapHeaderIndexes($header);
        if (! isset($indexes['タイトル']) && ! isset($indexes['本文']) && ! isset($indexes['チェックリスト'])) {
            throw new \InvalidArgumentException(__('CSVの見出しが不正です。タイトル・本文・チェックリストのいずれかを含めてください。'));
        }

        $created = 0;
        $skipped = 0;
        $messages = [];

        foreach ($rows as $rowIndex => $row) {
            $lineNo = $rowIndex + 2;
            $title = trim($this->cell($row, $indexes['タイトル'] ?? null));
            $body = $this->cell($row, $indexes['本文'] ?? null);
            $checklistRaw = $this->cell($row, $indexes['チェックリスト'] ?? null);
            $typeRaw = strtolower(trim($this->cell($row, $indexes['種別'] ?? null)));
            $type = in_array($typeRaw, ['checklist', 'チェックリスト', 'list'], true)
                ? 'checklist'
                : 'text';

            if ($type === 'text' && $checklistRaw !== '' && trim($body) === '' && $title === '') {
                $type = 'checklist';
            }
            if ($type === 'checklist' && $checklistRaw === '' && trim($body) !== '') {
                $checklistRaw = $body;
                $body = '';
            }

            if ($title === '' && trim($body) === '' && trim($checklistRaw) === '') {
                $skipped++;

                continue;
            }

            if ($type === 'checklist' && trim($checklistRaw) === '') {
                $skipped++;
                $messages[] = __('行:line: チェックリストが空のためスキップしました。', ['line' => $lineNo]);

                continue;
            }

            try {
                $createdNote = $this->notes->createNote([
                    'userId' => $userId,
                    'title' => $title,
                    'body' => $type === 'checklist' ? $checklistRaw : $body,
                    'type' => $type,
                    'category' => $this->cell($row, $indexes['カテゴリー'] ?? null) ?: null,
                    'color' => $this->cell($row, $indexes['色'] ?? null) ?: 'default',
                    'registeredDate' => $this->normalizeDate($this->cell($row, $indexes['登録日'] ?? null)),
                    'pinned' => $this->parseBool($this->cell($row, $indexes['ピン留め'] ?? null)),
                ]);

                $id = (int) ($createdNote['id'] ?? 0);
                $note = $id > 0 ? $this->notes->findAccessibleNote($userId, $id) : null;
                if ($note instanceof Note) {
                    $completed = $this->parseBool($this->cell($row, $indexes['完了'] ?? null));
                    $archived = $this->parseBool($this->cell($row, $indexes['アーカイブ'] ?? null));
                    if ($completed || $archived) {
                        $note->completed = $completed;
                        $note->archived = $archived;
                        $note->save();
                    }
                }
                $created++;
            } catch (\InvalidArgumentException $e) {
                $skipped++;
                $messages[] = __('行:line: :msg', ['line' => $lineNo, 'msg' => $e->getMessage()]);
            }
        }

        return compact('created', 'skipped', 'messages');
    }

    /**
     * @param list<string|null> $header
     * @return array<string, int>
     */
    private function mapHeaderIndexes(array $header): array
    {
        $indexes = [];
        foreach ($header as $i => $raw) {
            $label = trim((string) $raw);
            if ($label === '') {
                continue;
            }
            $normalized = self::HEADER_ALIASES[mb_strtolower($label)] ?? (self::HEADER_ALIASES[$label] ?? $label);
            if (in_array($normalized, self::HEADERS, true) && ! isset($indexes[$normalized])) {
                $indexes[$normalized] = (int) $i;
            }
        }

        return $indexes;
    }

    /** @param list<array{text?: string, checked?: bool}|mixed> $items */
    private function itemsToCsvCell(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $mark = ! empty($item['checked']) ? '[x]' : '[-]';
            $lines[] = $mark.' '.$text;
        }

        return implode("\n", $lines);
    }

    private function parseBool(string $value): bool
    {
        $v = mb_strtolower(trim($value));
        if ($v === '') {
            return false;
        }

        return in_array($v, ['1', 'true', 'yes', 'y', 'on', 'はい', '有', 'ピン', '完了', 'アーカイブ'], true);
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^(\d{4})[\/.](\d{1,2})[\/.](\d{1,2})$/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return null;
    }

    /** @param list<string|null> $row */
    private function cell(array $row, ?int $index): string
    {
        if ($index === null || ! array_key_exists($index, $row)) {
            return '';
        }

        return (string) ($row[$index] ?? '');
    }

    /** @return list<list<string>> */
    private function parseCsvRows(string $content): array
    {
        $content = str_replace("\u{FEFF}", '', $this->normalizeCsvEncoding($content));
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (trim($content) === '') {
            return [];
        }

        // タブ区切りのみの簡易CSV
        $firstLine = explode("\n", $content, 2)[0] ?? '';
        if (! str_contains($firstLine, ',') && str_contains($firstLine, "\t")) {
            $rows = [];
            foreach (explode("\n", $content) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $rows[] = array_map(static fn ($value) => (string) $value, explode("\t", $line));
            }

            return $rows;
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException(__('CSVの解析に失敗しました'));
        }
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $cells = array_map(static fn ($value) => (string) ($value ?? ''), $row);
            if (trim(implode('', $cells)) === '') {
                continue;
            }
            $rows[] = $cells;
        }
        fclose($handle);

        return $rows;
    }

    private function normalizeCsvEncoding(string $content): string
    {
        $content = str_replace("\u{FEFF}", '', $content);
        if ($content === '') {
            return $content;
        }
        $firstLine = explode("\n", str_replace(["\r\n", "\r"], "\n", $content), 2)[0] ?? '';
        if (mb_check_encoding($content, 'UTF-8')
            && preg_match('/タイトル|本文|種別|カテゴリ|登録日|title|body/i', $firstLine) === 1) {
            return $content;
        }
        foreach (['SJIS-win', 'CP932', 'SJIS', 'EUC-JP'] as $encoding) {
            $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
            if (! is_string($converted) || $converted === '') {
                continue;
            }
            $first = explode("\n", str_replace(["\r\n", "\r"], "\n", $converted), 2)[0] ?? '';
            if (preg_match('/タイトル|本文|種別|カテゴリ|登録日|title|body/i', $first) === 1) {
                return $converted;
            }
        }

        return $content;
    }

    /** @param list<list<string|int>> $lines */
    private function toCsv(array $lines): string
    {
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new \RuntimeException(__('CSVの生成に失敗しました'));
        }
        foreach ($lines as $line) {
            fputcsv($output, $line);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        if ($csv === false || $csv === '') {
            return '';
        }

        return "\xEF\xBB\xBF".$csv;
    }
}
