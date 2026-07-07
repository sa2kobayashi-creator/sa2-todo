<?php

namespace App\Services;

class DisplayService
{
    public function truncateTitle(string $title, int $max = 24): string
    {
        $oneLine = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if (mb_strlen($oneLine) <= $max) {
            return $oneLine;
        }

        return mb_substr($oneLine, 0, $max).'…';
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    public function compareTodosByDayTime(array $a, array $b): int
    {
        $aHasTime = ! empty($a['startTime']);
        $bHasTime = ! empty($b['startTime']);
        if ($aHasTime !== $bHasTime) {
            return $aHasTime ? 1 : -1;
        }
        if ($aHasTime && $bHasTime) {
            $aMin = $this->timeToMinutes($a['startTime']);
            $bMin = $this->timeToMinutes($b['startTime']);
            if ($aMin !== null && $bMin !== null && $aMin !== $bMin) {
                return $aMin <=> $bMin;
            }
            $timeCmp = strcmp((string) $a['startTime'], (string) $b['startTime']);
            if ($timeCmp !== 0) {
                return $timeCmp;
            }
        }

        return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    }

    /** @param list<array<string, mixed>> $todos */
    public function limitTodosForCell(array $todos, int $limit = 4): array
    {
        usort($todos, fn ($a, $b) => $this->compareTodosByDayTime($a, $b));

        return [
            'visible' => array_slice($todos, 0, $limit),
            'hiddenCount' => max(0, count($todos) - $limit),
        ];
    }

    /** @param list<array<string, mixed>> $todos @param list<array<string, mixed>> $notes */
    public function limitCellItems(array $todos, array $notes, int $limit = 4): array
    {
        usort($todos, fn ($a, $b) => $this->compareTodosByDayTime($a, $b));
        $items = [];
        foreach ($todos as $todo) {
            $items[] = ['kind' => 'todo', 'todo' => $todo];
        }
        foreach ($notes as $note) {
            $items[] = ['kind' => 'note', 'note' => $note];
        }

        return [
            'visible' => array_slice($items, 0, $limit),
            'hiddenCount' => max(0, count($items) - $limit),
        ];
    }

    private function timeToMinutes(?string $value): ?int
    {
        if (! $value || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
            return null;
        }
        $hours = (int) $m[1];
        $minutes = (int) $m[2];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }
}
