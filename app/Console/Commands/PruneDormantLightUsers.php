<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Mail\LightDormantWarningMail;
use App\Models\User;
use App\Services\UserAccountDeletionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * ライト（お試し）の長期未ログインを警告し、猶予後に削除する。
 */
class PruneDormantLightUsers extends Command
{
    protected $signature = 'users:prune-dormant-light {--dry-run : メール送信・削除をせず件数だけ表示}';

    protected $description = 'Warn and delete Light trial accounts that have been inactive for months';

    public function handle(UserAccountDeletionService $deletion): int
    {
        $warnAfter = max(1, (int) config('registration.light_inactive_warn_days', 90));
        $grace = max(1, (int) config('registration.light_inactive_delete_grace_days', 14));
        $dry = (bool) $this->option('dry-run');
        $warnBefore = Carbon::now()->subDays($warnAfter);
        $deleteBefore = Carbon::now()->subDays($grace);

        $candidates = User::query()
            ->where('role', UserRole::Light->value)
            ->whereNull('tenant_id')
            ->orderBy('id')
            ->get();

        $warned = 0;
        $deleted = 0;

        foreach ($candidates as $user) {
            $lastActive = $user->last_seen_at ?? $user->created_at;
            if (! $lastActive || $lastActive->gt($warnBefore)) {
                continue;
            }

            // 警告済みかつ猶予超過 → 削除
            if ($user->dormant_warned_at && $user->dormant_warned_at->lte($deleteBefore)) {
                $this->line("delete #{$user->id} {$user->email}");
                if (! $dry) {
                    $deletion->delete($user);
                }
                $deleted++;

                continue;
            }

            // 未警告 → 警告メール
            if (! $user->dormant_warned_at) {
                $deleteAfter = Carbon::now()->addDays($grace)->format('Y-m-d');
                $this->line("warn #{$user->id} {$user->email} (delete after {$deleteAfter})");
                if (! $dry) {
                    Mail::to($user->email)->send(new LightDormantWarningMail(
                        displayName: (string) $user->display_name,
                        loginUrl: url('/login'),
                        deleteAfterDate: $deleteAfter,
                        inactiveDays: $warnAfter,
                    ));
                    $user->forceFill(['dormant_warned_at' => now()])->save();
                }
                $warned++;
            }
        }

        $this->info("warned={$warned} deleted={$deleted}".($dry ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
