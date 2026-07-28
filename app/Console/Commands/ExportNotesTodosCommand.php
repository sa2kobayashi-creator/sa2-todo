<?php

namespace App\Console\Commands;

use App\Models\Note;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Console\Command;

class ExportNotesTodosCommand extends Command
{
    protected $signature = 'data:export-notes-todos
                            {--path= : Output JSON path (default: database/seed-data/local-notes-todos.json)}';

    protected $description = 'Export local notes and todos to JSON for production seeding';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: database_path('seed-data/local-notes-todos.json'));
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $users = User::query()->get(['id', 'email'])->keyBy('id');

        $todos = Todo::query()->orderBy('id')->get()->map(function (Todo $todo) use ($users) {
            return [
                'source_id' => $todo->id,
                'owner_email' => $users[$todo->user_id]->email ?? null,
                'title' => $todo->title,
                'completed' => (bool) $todo->completed,
                'start_date' => $todo->start_date?->format('Y-m-d'),
                'end_date' => $todo->end_date?->format('Y-m-d'),
                'start_time' => $todo->start_time,
                'end_time' => $todo->end_time,
                'importance' => $todo->importance,
                'category' => $todo->category,
                'reminders' => $todo->reminders ?? [],
                'notify_via' => $todo->notify_via,
                'notified_at' => $todo->notified_at ?? [],
                'created_at' => $todo->created_at?->toIso8601String(),
                'updated_at' => $todo->updated_at?->toIso8601String(),
            ];
        })->all();

        $notes = Note::query()->orderBy('id')->get()->map(function (Note $note) use ($users) {
            return [
                'source_id' => $note->id,
                'owner_email' => $users[$note->user_id]->email ?? null,
                'title' => $note->title,
                'body' => $note->body,
                'color' => $note->color,
                'pinned' => (bool) $note->pinned,
                'sort_order' => (int) ($note->sort_order ?? 0),
                'archived' => (bool) $note->archived,
                'type' => $note->type,
                'category' => $note->category,
                'items' => $note->items,
                'registered_date' => $note->registered_date?->format('Y-m-d'),
                'created_at' => $note->created_at?->toIso8601String(),
                'updated_at' => $note->updated_at?->toIso8601String(),
            ];
        })->all();

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'source' => config('app.url'),
            'todos' => $todos,
            'notes' => $notes,
        ];

        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");

        $this->info("Wrote {$path}");
        $this->info('todos='.count($todos).' notes='.count($notes));

        return self::SUCCESS;
    }
}
