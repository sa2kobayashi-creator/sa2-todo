<?php

namespace App\Services;

use App\Models\DataArchive;
use App\Models\Note;
use App\Models\NoteAttachment;
use App\Models\Todo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DatabaseRecordArchiveService
{
    public function __construct(private MediaStorageConfigService $media) {}

    /**
     * @return array{archived: int, skipped: int, errors: int, reason: string}
     */
    public function archiveDueRecords(bool $force = false): array
    {
        $dbBytes = app(StorageUsageService::class)->databaseBytes();
        $startAt = (int) config('storage_management.db_archive_bytes');
        if (! $force && $dbBytes < $startAt) {
            return ['archived' => 0, 'skipped' => 0, 'errors' => 0, 'reason' => 'within_quota'];
        }
        if (! $this->media->backblazeEnabled() && ! $force) {
            return ['archived' => 0, 'skipped' => 0, 'errors' => 0, 'reason' => 'b2_disabled'];
        }

        $this->media->applyRuntimeDisks();
        $limit = max(1, (int) config('storage_management.db_batch'));
        $archived = 0;
        $errors = 0;
        $errorsInRow = 0;

        $todos = $this->dueTodos($limit);
        foreach ($todos as $todo) {
            try {
                $this->archiveModel('todos', $todo);
                $todo->delete();
                $archived++;
                $errorsInRow = 0;
            } catch (\Throwable $e) {
                $errors++;
                $errorsInRow++;
                Log::warning('db.archive.todo_failed', ['id' => $todo->id, 'error' => $e->getMessage()]);
                if ($errorsInRow >= (int) config('storage_management.max_consecutive_errors')) {
                    break;
                }
            }
        }

        $notes = collect();
        if ($archived < $limit) {
            $notes = $this->dueNotes($limit - $archived);
            foreach ($notes as $note) {
                try {
                    $this->archiveModel('notes', $note);
                    $note->delete();
                    $archived++;
                    $errorsInRow = 0;
                } catch (\Throwable $e) {
                    $errors++;
                    $errorsInRow++;
                    Log::warning('db.archive.note_failed', ['id' => $note->id, 'error' => $e->getMessage()]);
                    if ($errorsInRow >= (int) config('storage_management.max_consecutive_errors')) {
                        break;
                    }
                }
            }
        }

        $reason = '';
        if ($archived === 0 && $errors === 0 && $todos->isEmpty() && $notes->isEmpty()) {
            $reason = 'no_due_records';
        }

        return ['archived' => $archived, 'skipped' => 0, 'errors' => $errors, 'reason' => $reason];
    }

    /**
     * 退避候補の Todo。共有グループのものは他メンバーの画面からも消えるため対象外。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Todo>
     */
    public function dueTodos(int $limit, ?int $userId = null): \Illuminate\Database\Eloquent\Collection
    {
        return Todo::query()
            ->where('completed', true)
            ->where('keep_on_server', false)
            ->whereNull('group_id')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->where('updated_at', '<', $this->minAge())
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Note>
     */
    public function dueNotes(int $limit, ?int $userId = null): \Illuminate\Database\Eloquent\Collection
    {
        return Note::query()
            ->with('attachments')
            ->where('keep_on_server', false)
            ->whereNull('group_id')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->where(function ($q) {
                $q->where('completed', true)->orWhere('archived', true);
            })
            ->where('pinned', false)
            ->where('updated_at', '<', $this->minAge())
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 退避が近い順の候補一覧。ユーザーが「サーバーに残す」を選べるようにするため。
     *
     * @return list<array{type: string, id: int, title: string, updated_at: ?\Illuminate\Support\Carbon}>
     */
    public function upcomingCandidates(int $userId, int $limit = 20): array
    {
        $rows = [];
        foreach ($this->dueTodos($limit, $userId) as $todo) {
            $rows[] = ['type' => 'todos', 'id' => (int) $todo->id, 'title' => (string) $todo->title, 'updated_at' => $todo->updated_at];
        }
        foreach ($this->dueNotes($limit, $userId) as $note) {
            $rows[] = ['type' => 'notes', 'id' => (int) $note->id, 'title' => (string) $note->title, 'updated_at' => $note->updated_at];
        }
        usort($rows, fn ($a, $b) => ($a['updated_at']?->getTimestamp() ?? 0) <=> ($b['updated_at']?->getTimestamp() ?? 0));

        return array_slice($rows, 0, $limit);
    }

    public function setKeepOnServer(int $userId, string $type, int $id, bool $keep): bool
    {
        $model = match ($type) {
            'todos' => Todo::query()->where('user_id', $userId)->find($id),
            'notes' => Note::query()->where('user_id', $userId)->find($id),
            default => null,
        };
        if (! $model) {
            return false;
        }
        $model->forceFill(['keep_on_server' => $keep])->save();

        return true;
    }

    public function archiveModel(string $table, Todo|Note $model): DataArchive
    {
        $this->media->applyRuntimeDisks();
        $disk = (string) config('storage_management.disk', 'backblaze');
        $payload = [
            'schema_version' => 2,
            'user_id' => (int) $model->user_id,
            'table' => $table,
            'record_id' => (int) $model->id,
            'created_at' => $model->created_at?->toIso8601String(),
            'updated_at' => $model->updated_at?->toIso8601String(),
            'archived_at' => now()->toIso8601String(),
            'payload' => $model->getAttributes(),
            'attachments' => $model instanceof Note ? $this->attachmentPayload($model) : [],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $title = (string) ($model->title ?? '');
        $summary = $table === 'notes'
            ? mb_substr(strip_tags((string) ($model->body ?? '')), 0, 200)
            : mb_substr((string) ($model->memo ?? ''), 0, 200);

        // 先に B2 へ書き、成功を確かめてからメタ行を作る。
        // 逆順にすると put 失敗時に復元できない孤児行が残る。
        $key = sprintf(
            'archives/db/%d/%s/%s/%s/%d-%s.json',
            $model->user_id,
            $table,
            now()->format('Y'),
            now()->format('m'),
            $model->id,
            Str::lower(Str::random(8))
        );
        Storage::disk($disk)->put($key, $json);
        if (! Storage::disk($disk)->exists($key)) {
            throw new \RuntimeException('b2 put failed');
        }

        $previousKey = DataArchive::query()
            ->where('source_table', $table)
            ->where('source_id', $model->id)
            ->value('storage_key');

        $archive = DataArchive::query()->updateOrCreate(
            ['source_table' => $table, 'source_id' => $model->id],
            [
                'user_id' => $model->user_id,
                'title' => mb_substr($title, 0, 255),
                'summary' => $summary,
                'source_created_at' => $model->created_at,
                'source_updated_at' => $model->updated_at,
                'storage_provider' => 'b2',
                'storage_key' => $key,
                'archived_at' => now(),
            ]
        );

        if ($previousKey && $previousKey !== $key) {
            try {
                Storage::disk($disk)->delete($previousKey);
            } catch (\Throwable $e) {
                Log::warning('db.archive.stale_object', ['key' => $previousKey, 'error' => $e->getMessage()]);
            }
        }

        return $archive->fresh();
    }

    /**
     * @return list<DataArchive>
     */
    public function search(int $userId, ?string $q = null, int $limit = 50): array
    {
        $query = DataArchive::query()->where('user_id', $userId)->orderByDesc('archived_at');
        if (filled($q)) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('title', 'like', $like)->orWhere('summary', 'like', $like);
            });
        }

        return $query->limit($limit)->get()->all();
    }

    public function restore(int $userId, int $archiveId): Todo|Note
    {
        $this->media->applyRuntimeDisks();
        $archive = DataArchive::query()
            ->where('user_id', $userId)
            ->whereKey($archiveId)
            ->firstOrFail();

        $disk = (string) config('storage_management.disk', 'backblaze');
        $raw = Storage::disk($disk)->get($archive->storage_key);
        $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $attachments = is_array($data['attachments'] ?? null) ? $data['attachments'] : [];
        unset($payload['id']);

        $model = DB::transaction(function () use ($archive, $payload, $attachments) {
            if ($archive->source_table === 'todos') {
                $model = Todo::query()->create($payload);
            } elseif ($archive->source_table === 'notes') {
                $model = Note::query()->create($payload);
            } else {
                throw new \InvalidArgumentException('unknown table');
            }

            $this->restoreTimestamps($model, $payload);
            if ($model instanceof Note) {
                $this->restoreAttachments($model, $attachments);
            }

            return $model;
        });

        $archive->delete();
        try {
            Storage::disk($disk)->delete($archive->storage_key);
        } catch (\Throwable $e) {
            Log::warning('db.archive.restore_cleanup_failed', ['key' => $archive->storage_key, 'error' => $e->getMessage()]);
        }

        return $model;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachmentPayload(Note $note): array
    {
        return $note->attachments()->get()
            ->map(fn (NoteAttachment $a) => [
                'disk' => (string) $a->disk,
                'path' => (string) $a->path,
                'original_name' => (string) $a->original_name,
                'mime' => $a->mime,
                'size_bytes' => (int) $a->size_bytes,
                'user_id' => (int) $a->user_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $attachments
     */
    private function restoreAttachments(Note $note, array $attachments): void
    {
        foreach ($attachments as $row) {
            if (! is_array($row) || ! filled($row['path'] ?? null)) {
                continue;
            }
            NoteAttachment::query()->create([
                'note_id' => $note->id,
                'user_id' => (int) ($row['user_id'] ?? $note->user_id),
                'disk' => (string) ($row['disk'] ?? 'public'),
                'path' => (string) $row['path'],
                'original_name' => (string) ($row['original_name'] ?? basename((string) $row['path'])),
                'mime' => $row['mime'] ?? null,
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            ]);
        }
    }

    /**
     * created_at / updated_at は $fillable に無いため create() では戻らない。
     *
     * @param  array<string, mixed>  $payload
     */
    private function restoreTimestamps(Todo|Note $model, array $payload): void
    {
        $values = [];
        foreach (['created_at', 'updated_at'] as $column) {
            if (filled($payload[$column] ?? null)) {
                $values[$column] = $payload[$column];
            }
        }
        if ($values === []) {
            return;
        }
        $model->newQuery()->whereKey($model->getKey())->update($values);
        $model->refresh();
    }

    private function minAge(): \Illuminate\Support\Carbon
    {
        return now()->subDays(max(1, (int) config('storage_management.db_min_age_days')));
    }
}
