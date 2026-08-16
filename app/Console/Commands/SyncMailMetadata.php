<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Services\MailMetadataSyncService;
use Illuminate\Console\Command;

class SyncMailMetadata extends Command
{
    protected $signature = 'mail:sync-metadata {--account= : Sync only this mail account ID}';

    protected $description = 'Sync recent mail headers to the local database';

    public function handle(MailMetadataSyncService $sync): int
    {
        $query = MailAccount::query()->orderBy('id');
        if ($this->option('account')) {
            $query->whereKey((int) $this->option('account'));
        }

        $ok = 0;
        $failed = 0;
        $query->each(function (MailAccount $account) use ($sync, &$ok, &$failed) {
            try {
                $result = $sync->syncInbox($account);
                $this->line("account={$account->id} synced={$result['synced']}");
                $ok++;
            } catch (\Throwable $e) {
                $this->warn("account={$account->id} failed: {$e->getMessage()}");
                $failed++;
            }
        });

        $this->info("ok={$ok} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
