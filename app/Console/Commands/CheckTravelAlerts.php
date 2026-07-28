<?php

namespace App\Console\Commands;

use App\Services\TravelService;
use Illuminate\Console\Command;

class CheckTravelAlerts extends Command
{
    protected $signature = 'travel:check-alerts';

    protected $description = 'Create in-app Travel alerts for RP/AR deadlines and expiring promos';

    public function handle(TravelService $travel): int
    {
        $stats = $travel->checkAlertsForAllUsers();
        $this->info("users={$stats['users']} created={$stats['created']}");

        return self::SUCCESS;
    }
}
