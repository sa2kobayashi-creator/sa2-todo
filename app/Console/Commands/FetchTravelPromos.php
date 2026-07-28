<?php

namespace App\Console\Commands;

use App\Services\TravelPromoWatchService;
use Illuminate\Console\Command;

class FetchTravelPromos extends Command
{
    protected $signature = 'travel:fetch-promos {--user= : Limit to a single user id}';

    protected $description = 'Fetch Cebu Pacific seat sales from public promo roundups into Travel promo ledger';

    public function handle(TravelPromoWatchService $watch): int
    {
        $userId = $this->option('user');
        if ($userId !== null && $userId !== '') {
            $result = $watch->fetchAndSyncForUser((int) $userId);
            $this->info(sprintf(
                'user=%d fetched=%d created=%d updated=%d alerts=%d',
                (int) $userId,
                $result['fetched'],
                $result['created'],
                $result['updated'],
                $result['alerts']
            ));
            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $stats = $watch->fetchAndSyncForAllUsers();
        $this->info(sprintf(
            'users=%d created=%d updated=%d alerts=%d errors=%d',
            $stats['users'],
            $stats['created'],
            $stats['updated'],
            $stats['alerts'],
            $stats['errors']
        ));

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
