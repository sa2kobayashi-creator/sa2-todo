<?php

namespace App\Console\Commands;

use App\Services\PhotoColdArchiveRunService;
use Illuminate\Console\Command;

class TickPhotoColdArchive extends Command
{
    protected $signature = 'photos:archive-cold-tick
        {--budget=45 : Seconds to keep processing in this invocation}';

    protected $description = 'Advance a running background cold-archive job (soft time budget)';

    public function handle(PhotoColdArchiveRunService $runs): int
    {
        if (! $runs->isRunning()) {
            return self::SUCCESS;
        }

        $budget = max(10, min(90, (int) $this->option('budget')));
        $this->info("Cold archive tick (budget {$budget}s)…");
        $runs->continueAfterResponse($budget);
        $state = $runs->current();
        $this->line("status={$state['status']} archived={$state['archived']} skipped={$state['skipped']} errors={$state['errors']}");

        return $state['status'] === PhotoColdArchiveRunService::STATUS_FAILED
            ? self::FAILURE
            : self::SUCCESS;
    }
}
