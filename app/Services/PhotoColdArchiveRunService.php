<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Photos「B2へアーカイブ」のバックグラウンド実行状態。
 * ブラウザが開いていなくても、cron / afterResponse の tick で進められる。
 */
class PhotoColdArchiveRunService
{
    public const STATUS_IDLE = 'idle';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    private const CACHE_KEY = 'photos.cold_archive.run';

    private const LOCK_KEY = 'photos.cold_archive.run.lock';

    public function __construct(
        private PhotoColdArchiveService $archive,
    ) {}

    /**
     * @return array{
     *   status: string,
     *   requested_by: ?int,
     *   archived: int,
     *   skipped: int,
     *   errors: int,
     *   bytes_moved: int,
     *   reason: string,
     *   message: string,
     *   last_error: string,
     *   last_error_photo_id: ?int,
     *   started_at: ?string,
     *   updated_at: ?string,
     *   finished_at: ?string,
     *   cancel_requested: bool,
     *   run_id: string
     * }
     */
    public function current(): array
    {
        $state = Cache::get(self::CACHE_KEY);

        return is_array($state) ? $this->normalize($state) : $this->idleState();
    }

    public function isRunning(): bool
    {
        return $this->current()['status'] === self::STATUS_RUNNING;
    }

    /**
     * 手動アーカイブを開始する。すでに実行中ならそのまま返す。
     *
     * @return array<string, mixed>
     */
    public function start(?int $userId = null): array
    {
        $current = $this->current();
        if ($current['status'] === self::STATUS_RUNNING) {
            return $current;
        }

        $now = now()->toIso8601String();
        $state = $this->normalize([
            'status' => self::STATUS_RUNNING,
            'requested_by' => $userId,
            'archived' => 0,
            'skipped' => 0,
            'errors' => 0,
            'bytes_moved' => 0,
            'reason' => '',
            'message' => __('アーカイブを開始しました。バックグラウンドで移動します。'),
            'last_error' => '',
            'last_error_photo_id' => null,
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
            'cancel_requested' => false,
            'run_id' => bin2hex(random_bytes(8)),
        ]);
        $this->save($state);

        // 最初の1バッチはここで進めて、押した直後に進捗が見えるようにする
        return $this->tick();
    }

    /**
     * 実行中なら中止を依頼する（次の tick で止まる）。
     *
     * @return array<string, mixed>
     */
    public function requestCancel(): array
    {
        $state = $this->current();
        if ($state['status'] !== self::STATUS_RUNNING) {
            return $state;
        }

        $state['cancel_requested'] = true;
        $state['message'] = __('アーカイブの中止を受け付けました。まもなく停止します…');
        $state['updated_at'] = now()->toIso8601String();
        $this->save($state);

        return $state;
    }

    /**
     * 1ソフトバッチ分だけ進める。cron / afterResponse から呼ばれる。
     *
     * @return array<string, mixed>
     */
    public function tick(?int $limit = null): array
    {
        $lock = Cache::lock(self::LOCK_KEY, 120);
        if (! $lock->get()) {
            return $this->current();
        }

        try {
            $state = $this->current();
            if ($state['status'] !== self::STATUS_RUNNING) {
                return $state;
            }

            if ($state['cancel_requested']) {
                return $this->finish($state, self::STATUS_CANCELLED, __('アーカイブを中止しました。'));
            }

            $batchLimit = max(1, $limit ?? (int) config('photos.archive_cold_batch_size', 8));
            $stats = $this->archive->archiveDuePhotos($batchLimit);

            $state['archived'] += (int) ($stats['archived'] ?? 0);
            $state['skipped'] += (int) ($stats['skipped'] ?? 0);
            $state['errors'] += (int) ($stats['errors'] ?? 0);
            $state['bytes_moved'] += (int) ($stats['bytesMoved'] ?? 0);
            $state['reason'] = (string) ($stats['reason'] ?? '');
            $state['last_error'] = (string) ($stats['lastError'] ?? $state['last_error']);
            $state['last_error_photo_id'] = $stats['lastErrorPhotoId'] ?? $state['last_error_photo_id'];
            $state['updated_at'] = now()->toIso8601String();
            $state['message'] = $this->progressMessage($state);

            $hasMore = (bool) ($stats['hasMore'] ?? false);
            if (! $hasMore) {
                if ($state['archived'] === 0 && $state['errors'] > 0) {
                    return $this->finish(
                        $state,
                        self::STATUS_FAILED,
                        $this->failedMessage($state)
                    );
                }

                return $this->finish(
                    $state,
                    self::STATUS_COMPLETED,
                    $this->completedMessage($state)
                );
            }

            $this->save($state);

            return $state;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * レスポンス返却後に、時間予算内でできるだけ tick する。
     */
    public function continueAfterResponse(int $budgetSeconds = 40): void
    {
        $budgetSeconds = max(10, min(90, $budgetSeconds));
        $deadline = microtime(true) + $budgetSeconds;

        while (microtime(true) < $deadline) {
            $state = $this->tick();
            if (($state['status'] ?? '') !== self::STATUS_RUNNING) {
                break;
            }
            // ホストを休めつつ次バッチへ
            usleep(200_000);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function finish(array $state, string $status, string $message): array
    {
        $state['status'] = $status;
        $state['message'] = $message;
        $state['finished_at'] = now()->toIso8601String();
        $state['updated_at'] = $state['finished_at'];
        $state['cancel_requested'] = false;
        $this->save($state);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function progressMessage(array $state): string
    {
        $msg = __('アーカイブ中… 移動 :archived 件 · スキップ :skipped 件 · エラー :errors 件', [
            'archived' => (int) $state['archived'],
            'skipped' => (int) $state['skipped'],
            'errors' => (int) $state['errors'],
        ]);
        if ((int) $state['bytes_moved'] > 0) {
            $msg .= ' '.__('転送量 約 :size', [
                'size' => $this->formatBytes((int) $state['bytes_moved']),
            ]);
        }

        return $msg;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function completedMessage(array $state): string
    {
        $msg = __('アーカイブ完了: 移動 :archived 件 · スキップ :skipped 件 · エラー :errors 件', [
            'archived' => (int) $state['archived'],
            'skipped' => (int) $state['skipped'],
            'errors' => (int) $state['errors'],
        ]);
        if ((int) $state['bytes_moved'] > 0) {
            $msg .= ' '.__('転送量 約 :size', [
                'size' => $this->formatBytes((int) $state['bytes_moved']),
            ]);
        }
        if ($state['last_error'] !== '') {
            $msg .= ' '.__('最後のエラー: :err', ['err' => $state['last_error']]);
        }

        return $msg;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function failedMessage(array $state): string
    {
        $base = __('アーカイブに失敗しました');
        if ($state['last_error'] !== '') {
            return $base.': '.$state['last_error'];
        }
        if ($state['reason'] !== '') {
            return $base.' ('.$state['reason'].')';
        }

        return $base;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function save(array $state): void
    {
        // 完了後もしばらくポーリングできるよう 2 日残す
        Cache::put(self::CACHE_KEY, $this->normalize($state), now()->addDays(2));
    }

    /**
     * @return array<string, mixed>
     */
    private function idleState(): array
    {
        return $this->normalize([
            'status' => self::STATUS_IDLE,
            'requested_by' => null,
            'archived' => 0,
            'skipped' => 0,
            'errors' => 0,
            'bytes_moved' => 0,
            'reason' => '',
            'message' => '',
            'last_error' => '',
            'last_error_photo_id' => null,
            'started_at' => null,
            'updated_at' => null,
            'finished_at' => null,
            'cancel_requested' => false,
            'run_id' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalize(array $state): array
    {
        return [
            'status' => (string) ($state['status'] ?? self::STATUS_IDLE),
            'requested_by' => isset($state['requested_by']) ? (int) $state['requested_by'] : null,
            'archived' => (int) ($state['archived'] ?? 0),
            'skipped' => (int) ($state['skipped'] ?? 0),
            'errors' => (int) ($state['errors'] ?? 0),
            'bytes_moved' => (int) ($state['bytes_moved'] ?? 0),
            'reason' => (string) ($state['reason'] ?? ''),
            'message' => (string) ($state['message'] ?? ''),
            'last_error' => (string) ($state['last_error'] ?? ''),
            'last_error_photo_id' => isset($state['last_error_photo_id']) ? (int) $state['last_error_photo_id'] : null,
            'started_at' => $state['started_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'cancel_requested' => (bool) ($state['cancel_requested'] ?? false),
            'run_id' => (string) ($state['run_id'] ?? ''),
        ];
    }
}
