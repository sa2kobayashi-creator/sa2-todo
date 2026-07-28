<?php

namespace Database\Seeders;

use App\Models\Note;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * ローカルから書き出したメモ・ToDo を本番へ取り込む Seeder。
 *
 * 事前: ローカルで `php artisan data:export-notes-todos`
 * 本番: `php artisan db:seed --class=LocalNotesTodosSeeder`
 *
 * オプション環境変数:
 * - SEED_OWNER_EMAIL … owner_email が解決できない行のフォールバック所有者
 * - SEED_NOTES_TODOS_PATH … JSON パス（省略時 database/seed-data/local-notes-todos.json）
 * - SEED_NOTES_TODOS_FORCE=1 … 重複チェックをせず常に追加
 */
class LocalNotesTodosSeeder extends Seeder
{
    public function run(): void
    {
        $path = (string) (env('SEED_NOTES_TODOS_PATH') ?: database_path('seed-data/local-notes-todos.json'));
        if (! is_file($path)) {
            $this->command?->error("JSON がありません: {$path}");
            $this->command?->warn('先にローカルで php artisan data:export-notes-todos を実行してください。');

            return;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            $this->command?->error('JSON の形式が不正です。');

            return;
        }

        $fallbackEmail = (string) (env('SEED_OWNER_EMAIL') ?: 'admin@example.com');
        $fallbackUserId = $this->resolveUserId($fallbackEmail)
            ?? User::query()->where('role', 'admin')->orderBy('id')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if (! $fallbackUserId) {
            $this->command?->error('取り込み先ユーザーが見つかりません。先にユーザーを作成してください。');

            return;
        }

        $force = filter_var(env('SEED_NOTES_TODOS_FORCE', false), FILTER_VALIDATE_BOOLEAN);
        $emailToId = User::query()->pluck('id', 'email')->all();

        $todoCreated = 0;
        $todoSkipped = 0;
        foreach ($raw['todos'] ?? [] as $row) {
            if (! is_array($row) || trim((string) ($row['title'] ?? '')) === '') {
                continue;
            }

            $userId = $this->ownerIdForRow($row, $emailToId, (int) $fallbackUserId);
            $title = (string) $row['title'];
            $startDate = $row['start_date'] ?? null;

            if (! $force && $this->todoExists($userId, $title, $startDate)) {
                $todoSkipped++;
                continue;
            }

            Todo::query()->create([
                'user_id' => $userId,
                'group_id' => null,
                'title' => $title,
                'completed' => (bool) ($row['completed'] ?? false),
                'start_date' => $startDate,
                'end_date' => $row['end_date'] ?? null,
                'start_time' => $row['start_time'] ?? null,
                'end_time' => $row['end_time'] ?? null,
                'importance' => $this->normalizeImportance((string) ($row['importance'] ?? 'medium')),
                'category' => $row['category'] ?? 'task',
                'reminders' => $row['reminders'] ?? [],
                'notify_via' => $row['notify_via'] ?? null,
                'notified_at' => $row['notified_at'] ?? [],
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]);
            $todoCreated++;
        }

        $noteCreated = 0;
        $noteSkipped = 0;
        foreach ($raw['notes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $userId = $this->ownerIdForRow($row, $emailToId, (int) $fallbackUserId);
            $title = (string) ($row['title'] ?? '');
            $body = $row['body'] ?? null;

            if (! $force && $this->noteExists($userId, $title, $body)) {
                $noteSkipped++;
                continue;
            }

            Note::query()->create([
                'user_id' => $userId,
                'group_id' => null,
                'title' => $title,
                'body' => $body,
                'color' => $row['color'] ?? 'yellow',
                'pinned' => (bool) ($row['pinned'] ?? false),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'archived' => (bool) ($row['archived'] ?? false),
                'type' => $row['type'] ?? 'text',
                'category' => $row['category'] ?? 'personal',
                'items' => $row['items'] ?? null,
                'registered_date' => $row['registered_date'] ?? null,
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]);
            $noteCreated++;
        }

        $this->command?->info("todos: created={$todoCreated} skipped={$todoSkipped}");
        $this->command?->info("notes: created={$noteCreated} skipped={$noteSkipped}");
        $this->command?->warn('メモ添付ファイル（note_attachments）は含まれません。必要なら別途移行してください。');
    }

    /** @param array<string, int|string> $emailToId */
    private function ownerIdForRow(array $row, array $emailToId, int $fallbackUserId): int
    {
        $email = strtolower(trim((string) ($row['owner_email'] ?? '')));
        if ($email !== '' && isset($emailToId[$email])) {
            return (int) $emailToId[$email];
        }

        // 大文字小文字ゆれ
        foreach ($emailToId as $known => $id) {
            if (strtolower((string) $known) === $email) {
                return (int) $id;
            }
        }

        return $fallbackUserId;
    }

    private function resolveUserId(string $email): ?int
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        return User::query()->where('email', $email)->value('id');
    }

    private function todoExists(int $userId, string $title, mixed $startDate): bool
    {
        $q = Todo::query()
            ->where('user_id', $userId)
            ->where('title', $title);

        if ($startDate) {
            $q->whereDate('start_date', $startDate);
        } else {
            $q->whereNull('start_date');
        }

        return $q->exists();
    }

    private function noteExists(int $userId, string $title, mixed $body): bool
    {
        return Note::query()
            ->where('user_id', $userId)
            ->where('title', $title)
            ->where('body', $body)
            ->exists();
    }

    private function normalizeImportance(string $value): string
    {
        return match ($value) {
            'important', 'high' => 'high',
            'memo', 'low' => 'low',
            default => 'medium',
        };
    }
}
