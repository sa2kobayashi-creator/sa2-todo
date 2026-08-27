<?php

namespace App\Services;

use App\Models\MailAccount;
use App\Models\Photo;
use Illuminate\Support\Facades\DB;

class StorageUsageService
{
    public function __construct(private MailClientService $mail) {}

    /**
     * @return array{
     *   r2_bytes: int,
     *   mail_bytes: int,
     *   mail_boxes: list<array{account_id: int, email: string, bytes: ?int}>,
     *   db_bytes: int,
     *   r2_status: string,
     *   mail_status: string,
     *   db_status: string
     * }
     */
    public function snapshot(): array
    {
        $r2 = $this->r2HotBytes();
        $boxes = $this->mailboxUsages();
        $mailBytes = 0;
        foreach ($boxes as $box) {
            $mailBytes += (int) ($box['bytes'] ?? 0);
        }
        $db = $this->databaseBytes();

        return [
            'r2_bytes' => $r2,
            'mail_bytes' => $mailBytes,
            'mail_boxes' => $boxes,
            'db_bytes' => $db,
            'r2_status' => $this->level($r2, (int) config('storage_management.r2_warn_bytes'), (int) config('storage_management.r2_archive_bytes')),
            'mail_status' => $this->worstBoxStatus($boxes),
            'db_status' => $this->level($db, (int) config('storage_management.db_warn_bytes'), (int) config('storage_management.db_archive_bytes')),
        ];
    }

    public function r2HotBytes(): int
    {
        return (int) Photo::query()
            ->where('storage_tier', 'hot')
            ->sum('size_bytes');
    }

    public function databaseBytes(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $path = (string) config('database.connections.sqlite.database');
            if ($path !== '' && $path !== ':memory:' && is_file($path)) {
                return (int) filesize($path);
            }

            return 0;
        }

        // data_length / index_length は MySQL 系にしかない列
        if ($driver === 'pgsql') {
            $row = DB::selectOne('SELECT pg_database_size(?) AS bytes', [$database]);

            return (int) ($row->bytes ?? 0);
        }

        $row = DB::selectOne(
            'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes
             FROM information_schema.tables
             WHERE table_schema = ?',
            [$database]
        );

        return (int) ($row->bytes ?? 0);
    }

    /**
     * @return list<array{account_id: int, email: string, bytes: ?int}>
     */
    public function mailboxUsages(): array
    {
        $rows = [];
        $accounts = MailAccount::query()
            ->where('is_sa2_plus_mailbox', true)
            ->orderBy('id')
            ->get();

        foreach ($accounts as $account) {
            $bytes = null;
            try {
                $bytes = $this->mail->mailboxUsedBytes($account);
            } catch (\Throwable) {
                $bytes = null;
            }
            $rows[] = [
                'account_id' => (int) $account->id,
                'email' => (string) $account->email,
                'bytes' => $bytes,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{bytes: ?int}>  $boxes
     */
    private function worstBoxStatus(array $boxes): string
    {
        $warn = (int) config('storage_management.mail_warn_bytes');
        $start = (int) config('storage_management.mail_archive_bytes');
        $worst = 'ok';
        foreach ($boxes as $box) {
            if ($box['bytes'] === null) {
                continue;
            }
            $level = $this->level((int) $box['bytes'], $warn, $start);
            if ($level === 'archive') {
                return 'archive';
            }
            if ($level === 'warn') {
                $worst = 'warn';
            }
        }

        return $worst;
    }

    private function level(int $used, int $warn, int $start): string
    {
        if ($used >= $start) {
            return 'archive';
        }
        if ($used >= $warn) {
            return 'warn';
        }

        return 'ok';
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024).' KB';
        }

        return $bytes.' B';
    }
}
