<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Services\MailMetadataSyncService;
use Illuminate\Console\Command;

class SyncMailMetadata extends Command
{
    protected $signature = 'mail:sync-metadata
                            {--account= : Sync only this mail account ID}
                            {--all : Sync every account including external providers}';

    protected $description = 'Sync recent mail headers to the local database (sa2-plus by default)';

    public function handle(MailMetadataSyncService $sync): int
    {
        // SSH切断対策: 実行時間制限を外し、アカウントごとに進捗を即出力する
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $query = MailAccount::query()->orderBy('id');
        if ($this->option('account')) {
            $query->whereKey((int) $this->option('account'));
        } elseif (! $this->option('all')) {
            // 外部IMAP（Gmail等）は選択時のみ読み込む。定期同期は @sa2-plus.com 優先
            $query->where('is_sa2_plus_mailbox', true);
        }

        $accounts = $query->get();
        if ($accounts->isEmpty()) {
            $this->info('no accounts to sync');

            return self::SUCCESS;
        }

        $this->info('syncing '.$accounts->count().' account(s)');
        $this->flushOutput();

        $ok = 0;
        $failed = 0;
        foreach ($accounts as $account) {
            $label = $account->email ?: ('#'.$account->id);
            $this->line('start account='.$account->id.' '.$label);
            $this->flushOutput();
            try {
                $result = $sync->syncInbox($account);
                $this->line("account={$account->id} synced={$result['synced']}");
                $ok++;
            } catch (\Throwable $e) {
                $this->warn("account={$account->id} failed: {$e->getMessage()}");
                $failed++;
            }
            $this->flushOutput();
        }

        $this->info("ok={$ok} failed={$failed}");
        $this->flushOutput();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function flushOutput(): void
    {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_flush();
                @ob_end_flush();
            }
        }
        @flush();
    }
}
