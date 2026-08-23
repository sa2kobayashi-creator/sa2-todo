<?php

namespace App\Console\Commands;

use App\Models\StorageManagementLog;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class BackupDatabaseToB2Command extends Command
{
    protected $signature = 'storage:backup-db';

    protected $description = 'Dump MySQL and store a compressed copy on B2';

    public function handle(DatabaseBackupService $backup): int
    {
        @set_time_limit(180);
        $result = $backup->run();
        if (! $result['ok']) {
            $this->warn($result['reason'] ?: 'backup skipped');

            return $result['reason'] === 'b2_disabled' ? self::SUCCESS : self::FAILURE;
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
