<?php

namespace App\Console\Commands;

use App\Models\StorageManagementLog;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupDatabaseToB2Command extends Command
{
    protected $signature = 'storage:backup-db';

    protected $description = 'Dump MySQL and store a compressed copy on B2';

    public function handle(DatabaseBackupService $backup): int
    {
        @set_time_limit((int) config('storage_management.backup_timeout', 900));
        $result = $backup->run();
        if (! $result['ok']) {
            $reason = (string) ($result['reason'] ?: 'backup skipped');
            $this->warn($reason);
            // b2_disabled は設定オフの正常スキップ。それ以外は気づけるよう error で残す
            if ($reason !== 'b2_disabled') {
                Log::error('db.backup.failed', [
                    'reason' => $reason,
                    'bytes' => $result['bytes'] ?? 0,
                ]);
            }

            return $reason === 'b2_disabled' ? self::SUCCESS : self::FAILURE;
        }

        StorageManagementLog::query()->create([
            'ran_at' => now(),
            'backup_key' => $result['key'],
            'notes' => ['kind' => 'db_backup', 'bytes' => $result['bytes']],
        ]);

        $this->info('saved '.$result['key'].' ('.$result['bytes'].' bytes)');

        return self::SUCCESS;
    }
}
