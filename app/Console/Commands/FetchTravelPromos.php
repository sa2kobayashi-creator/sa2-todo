<?php

namespace App\Console\Commands;

use App\Models\TravelProfile;
use App\Services\TravelPromoWatchService;
use Illuminate\Console\Command;

class FetchTravelPromos extends Command
{
    protected $signature = 'travel:fetch-promos {--user= : Limit to a single user id}';

    protected $description = 'Fetch Cebu Pacific seat sales for users who opted into promo watch';

    public function handle(TravelPromoWatchService $watch): int
    {
        $userId = $this->option('user');
        if ($userId !== null && $userId !== '') {
            $profile = TravelProfile::query()->where('user_id', (int) $userId)->first();
            if (! ($profile?->promo_watch_enabled ?? false)) {
                $this->warn('user='.(int) $userId.' skipped (promo watch is off)');

                return self::SUCCESS;
            }

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
