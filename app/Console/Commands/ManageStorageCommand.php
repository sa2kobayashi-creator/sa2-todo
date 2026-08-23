<?php

namespace App\Console\Commands;

use App\Services\StorageManagementService;
use Illuminate\Console\Command;

class ManageStorageCommand extends Command
{
    protected $signature = 'storage:manage';

    protected $description = 'Measure R2 / mailbox / DB usage and archive overflow to B2';

    public function handle(StorageManagementService $manager): int
    {
        @set_time_limit(90);
        @ini_set('max_execution_time', '90');

        $result = $manager->run();
        $this->info('r2='.($result['usage']['r2_status'] ?? ''));
        $this->info('mail='.($result['usage']['mail_status'] ?? '').' archived='.($result['mail']['archived'] ?? 0));
        $this->info('photos archived='.($result['photos']['archived'] ?? 0));
        $this->info('db='.($result['usage']['db_status'] ?? '').' archived='.($result['db']['archived'] ?? 0));

        return self::SUCCESS;
    }
}
