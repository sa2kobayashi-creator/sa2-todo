<?php

namespace App\Console\Commands;

use App\Services\TravelFareWatchService;
use App\Services\TravelService;
use Illuminate\Console\Command;

class CheckTravelAlerts extends Command
{
    protected $signature = 'travel:check-alerts';

    protected $description = 'Create in-app Travel alerts for deadlines, watches, and expiring promos';

    public function handle(TravelService $travel, TravelFareWatchService $watches): int
    {
        $stats = $travel->checkAlertsForAllUsers();
        $watch = $watches->checkAllWatches();
        $this->info("users={$stats['users']} created={$stats['created']} watches={$watch['checked']} watch_alerts={$watch['alerts']}");

        return self::SUCCESS;
    }
}
