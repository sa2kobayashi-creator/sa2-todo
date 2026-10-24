<?php

namespace App\Console\Commands;

use App\Services\MaintenanceModeService;
use Illuminate\Console\Command;

class MaintenanceModeCommand extends Command
{
    protected $signature = 'app:maintenance
        {action=status : on / off / status}
        {--message= : 停止画面に出す文言（on のとき）}';

    protected $description = 'Toggle sa2-plus maintenance mode (SuperAdmin can still use the app)';

    public function handle(MaintenanceModeService $maintenance): int
    {
        $action = strtolower((string) $this->argument('action'));

        if ($action === 'on') {
            $maintenance->enable((string) $this->option('message'));
            $this->warn('Maintenance is ON. Only SuperAdmin can use the app.');

            return self::SUCCESS;
        }

        if ($action === 'off') {
            $maintenance->disable();
            $this->info('Maintenance is OFF.');

            return self::SUCCESS;
        }

        if ($maintenance->isEnabled()) {
            $this->warn('status: ON');
            $this->line($maintenance->message());
        } else {
            $this->info('status: OFF');
        }

        return self::SUCCESS;
    }
}
