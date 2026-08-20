<?php

namespace App\Services;

use App\Models\TodoShortcutCategory;
use App\Models\TodoShortcutTitle;
use Illuminate\Support\Facades\DB;

class TodoShortcutService
{
    /** @var list<string> */
    public const ICON_CHOICES = [
        '🚗', '🏥', '🛒', '🏠', '💼', '🏫', '✈️', '🏋️', '🍽️', '💊',
        '📅', '💡', '📌', '⭐', '❤️', '📞', '✉️', '🎵', '📷', '🔧',
    ];

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        return TodoShortcutCategory::query()
            ->where('user_id', $userId)
            ->with('titles')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (TodoShortcutCategory $cat) => $this->categoryToArray($cat))
            ->all();
    }

    /** @return array<string, mixed> */
    public function createCategory(int $userId, string $name, string $icon): array
    {
        $name = trim($name);
        $icon = trim($icon);
        if ($name === '') {
            throw new \InvalidArgumentException(__('カテゴリ名を入力してください。'));
        }
        if ($icon === '' || ! in_array($icon, self::ICON_CHOICES, true)) {
            throw new \InvalidArgumentException(__('アイコンを選んでください。'));
        }

        $max = (int) TodoShortcutCategory::query()->where('user_id', $userId)->max('sort_order');
        $cat = TodoShortcutCategory::create([
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 40),
            'icon' => $icon,
            'sort_order' => $max + 1,
        ]);

        return $this->categoryToArray($cat->load('titles'));
    }

    public function updateCategory(int $userId, int $categoryId, string $name, string $icon): array
    {
        $cat = $this->findCategoryOrFail($userId, $categoryId);
        $name = trim($name);
        $icon = trim($icon);
        if ($name === '') {
            throw new \InvalidArgumentException(__('カテゴリ名を入力してください。'));
        }
        if ($icon === '' || ! in_array($icon, self::ICON_CHOICES, true)) {
            throw new \InvalidArgumentException(__('アイコンを選んでください。'));
        }

        $cat->name = mb_substr($name, 0, 40);
        $cat->icon = $icon;
        $cat->save();

        return $this->categoryToArray($cat->load('titles'));
    }

    public function deleteCategory(int $userId, int $categoryId): void
    {
        $this->findCategoryOrFail($userId, $categoryId)->delete();
    }

    public function createTitle(
        int $userId,
        int $categoryId,
        string $title,
        ?string $startTime = null,
        ?string $endTime = null,
        mixed $reminders = null,
        mixed $reminderTime = null,
        mixed $notifyVia = null,
    ): array {
        $cat = $this->findCategoryOrFail($userId, $categoryId);
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException(__('タイトルを入力してください。'));
        }

        $startTime = $this->normalizeTime($startTime);
        $endTime = $this->normalizeTime($endTime);
        if ($endTime !== null && $startTime === null) {
            throw new \InvalidArgumentException(__('終了時刻を入れる場合は開始時刻も指定してください。'));
        }
        if ($endTime !== null && $startTime !== null && $endTime === $startTime) {
            $endTime = null;
        }

        [$remindersNorm, $notifyViaNorm] = $this->normalizeNotify($startTime, $reminders, $reminderTime, $notifyVia);

        $max = (int) $cat->titles()->max('sort_order');
        $row = TodoShortcutTitle::create([
            'todo_shortcut_category_id' => $cat->id,
            'title' => mb_substr($title, 0, 255),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'reminders' => $remindersNorm,
            'notify_via' => $notifyViaNorm,
            'sort_order' => $max + 1,
        ]);

        return $this->titleToArray($row);
    }

    public function updateTitle(
        int $userId,
        int $titleId,
        string $title,
        ?string $startTime = null,
        ?string $endTime = null,
        mixed $reminders = null,
        mixed $reminderTime = null,
        mixed $notifyVia = null,
    ): array {
        $row = $this->findTitleOrFail($userId, $titleId);
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException(__('タイトルを入力してください。'));
        }

        $startTime = $this->normalizeTime($startTime);
        $endTime = $this->normalizeTime($endTime);
        if ($endTime !== null && $startTime === null) {
            throw new \InvalidArgumentException(__('終了時刻を入れる場合は開始時刻も指定してください。'));
        }
        if ($endTime !== null && $startTime !== null && $endTime === $startTime) {
            $endTime = null;
        }

        [$remindersNorm, $notifyViaNorm] = $this->normalizeNotify($startTime, $reminders, $reminderTime, $notifyVia);

        $row->title = mb_substr($title, 0, 255);
        $row->start_time = $startTime;
        $row->end_time = $endTime;
        $row->reminders = $remindersNorm;
        $row->notify_via = $notifyViaNorm;
        $row->save();

        return $this->titleToArray($row);
    }

    public function deleteTitle(int $userId, int $titleId): void
    {
        $this->findTitleOrFail($userId, $titleId)->delete();
    }

    public function reorderCategories(int $userId, array $orderedIds): void
    {
        DB::transaction(function () use ($userId, $orderedIds) {
            $order = 1;
            foreach ($orderedIds as $id) {
                TodoShortcutCategory::query()
                    ->where('user_id', $userId)
                    ->where('id', (int) $id)
                    ->update(['sort_order' => $order++]);
            }
        });
    }

    private function findCategoryOrFail(int $userId, int $categoryId): TodoShortcutCategory
    {
        $cat = TodoShortcutCategory::query()
            ->where('user_id', $userId)
            ->where('id', $categoryId)
            ->first();
        if (! $cat) {
            throw new \InvalidArgumentException(__('カテゴリが見つかりません。'));
        }

        return $cat;
    }

    private function findTitleOrFail(int $userId, int $titleId): TodoShortcutTitle
    {
        $row = TodoShortcutTitle::query()
            ->whereKey($titleId)
            ->whereHas('category', fn ($q) => $q->where('user_id', $userId))
            ->first();
        if (! $row) {
            throw new \InvalidArgumentException(__('タイトルが見つかりません。'));
        }

        return $row;
    }

    private function normalizeTime(?string $time): ?string
    {
        $time = trim((string) $time);
        if ($time === '' || $time === '--:--' || ! preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
            return null;
        }
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            // H:MM or HH:MM:SS → HH:MM
            $parts = explode(':', $time);
            $time = sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
        }
        [$h, $m] = array_map('intval', explode(':', $time));
        if ($h > 23 || $m > 59) {
            throw new \InvalidArgumentException(__('時刻の形式が正しくありません。'));
        }

        return sprintf('%02d:%02d', $h, $m);
    }

    /** @return array<string, mixed> */
    private function categoryToArray(TodoShortcutCategory $cat): array
    {
        return [
            'id' => (int) $cat->id,
            'name' => $cat->name,
            'icon' => $cat->icon,
            'sortOrder' => (int) $cat->sort_order,
            'titles' => $cat->titles
                ->map(fn (TodoShortcutTitle $t) => $this->titleToArray($t))
                ->values()
                ->all(),
        ];
    }

    /** @return array{0: list<string>|null, 1: ?string} */
    private function normalizeNotify(?string $startTime, mixed $reminders, mixed $reminderTime, mixed $notifyVia): array
    {
        if ($startTime === null) {
            return [null, null];
        }

        $todos = app(TodoService::class);
        $remindersNorm = $todos->normalizeReminders($reminders, $reminderTime);
        $notifyViaNorm = $todos->normalizeNotifyVia(is_string($notifyVia) ? $notifyVia : null);
        if ($remindersNorm === []) {
            $notifyViaNorm = null;
        }

        return [$remindersNorm === [] ? null : $remindersNorm, $notifyViaNorm];
    }

    /** @return array<string, mixed> */
    private function titleToArray(TodoShortcutTitle $row): array
    {
        $reminders = is_array($row->reminders) ? array_values($row->reminders) : [];

        return [
            'id' => (int) $row->id,
            'categoryId' => (int) $row->todo_shortcut_category_id,
            'title' => $row->title,
            'startTime' => $row->start_time,
            'endTime' => $row->end_time,
            'reminders' => $reminders,
            'reminderTime' => TodoService::reminderTimeFromList($reminders),
            'notifyVia' => $row->notify_via,
            'sortOrder' => (int) $row->sort_order,
        ];
    }
}
