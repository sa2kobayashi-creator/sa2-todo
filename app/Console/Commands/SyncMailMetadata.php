<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Services\MailMetadataSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncMailMetadata extends Command
{
    protected $signature = 'mail:sync-metadata
                            {--account= : Sync only this mail account ID}
                            {--all : Sync every account including external providers}
                            {--limit=1 : Max accounts to sync in this run (0 = all matching)}';

    protected $description = 'Sync recent mail headers (sa2-plus by default; one account per run for shared hosts)';

    public function handle(MailMetadataSyncService $sync): int
    {
        // 共有ホストの短時間 cron 向け（SSH 長時間実行は想定しない）
        @set_time_limit(90);
        @ini_set('max_execution_time', '90');

        $query = MailAccount::query()->orderBy('id');
        if ($this->option('account')) {
            $query->whereKey((int) $this->option('account'));
        } elseif (! $this->option('all')) {
            // 外部IMAP（Gmail等）は選択時のみ。定期同期は @sa2-plus.com 優先
            $query->where('is_sa2_plus_mailbox', true);
        }

        $accounts = $query->get();
        if ($accounts->isEmpty()) {
            $this->info('no accounts to sync');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        if ($limit < 0) {
            $limit = 1;
        }

        if ($limit === 0 || $this->option('account')) {
            $batch = $accounts;
        } else {
            $batch = $this->nextBatch($accounts, $limit);
        }

        $this->info('syncing '.$batch->count().' of '.$accounts->count().' account(s)');
        $this->flushOutput();

        $ok = 0;
        $failed = 0;
        foreach ($batch as $account) {
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

    /**
     * @param  \Illuminate\Support\Collection<int, MailAccount>  $accounts
     * @return \Illuminate\Support\Collection<int, MailAccount>
     */
    private function nextBatch($accounts, int $limit)
    {
        $ids = $accounts->pluck('id')->values()->all();
        $cursor = (int) Cache::get('mail:sync:cursor', 0);
        $start = 0;
        foreach ($ids as $i => $id) {
            if ($id > $cursor) {
                $start = $i;
                break;
            }
            if ($i === count($ids) - 1) {
                $start = 0;
            }
        }

        $batch = $accounts->slice($start, $limit)->values();
        if ($batch->isEmpty()) {
            $batch = $accounts->take($limit)->values();
        }

        $lastId = (int) $batch->last()->id;
        Cache::put('mail:sync:cursor', $lastId, now()->addDays(7));

        return $batch;
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
