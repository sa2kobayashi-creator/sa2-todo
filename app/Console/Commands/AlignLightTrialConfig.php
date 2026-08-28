<?php

namespace App\Console\Commands;

use App\Models\UsageLimitPolicy;
use App\Services\UsageLimitPolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Light お試し枠（20GB）・制限テンプレ・休眠スケジュールを製品仕様に揃える。
 */
class AlignLightTrialConfig extends Command
{
    protected $signature = 'registration:align-light-trial
                            {--force : 制限テンプレの Light ストレージを製品既定GBへ強制上書き}';

    protected $description = 'Align Light trial quota (config/DB) and verify dormant prune schedule';

    public function handle(UsageLimitPolicyService $limits): int
    {
        $bytes = max(1, (int) config('photos.user_free_quota_bytes', 20 * 1024 * 1024 * 1024));
        $gb = max(1, (int) round($bytes / (1024 * 1024 * 1024)));
        $envRaw = env('PHOTO_USER_FREE_QUOTA_BYTES');

        $this->info("製品 Light 枠: {$gb}GB ({$bytes} bytes)");
        if ($envRaw === null || $envRaw === '') {
            $this->line('PHOTO_USER_FREE_QUOTA_BYTES: 未設定（config 既定を使用）');
        } else {
            $this->line('PHOTO_USER_FREE_QUOTA_BYTES: '.$envRaw);
            if ((int) $envRaw !== $bytes) {
                $this->warn('注意: env と config の解釈が食い違っている可能性があります。config:clear 後に再確認してください。');
            }
            $envGb = max(1, (int) round(((int) $envRaw) / (1024 * 1024 * 1024)));
            if ($envGb !== $gb) {
                $this->warn("注意: env 換算は約 {$envGb}GB です。20GB にするなら PHOTO_USER_FREE_QUOTA_BYTES=21474836480");
            }
        }

        if ($gb !== 20) {
            $this->warn('製品意図は Light 約20GB です。本番 .env を 21474836480 に合わせてください。');
        }

        if (Schema::hasTable('usage_limit_policies')) {
            $row = UsageLimitPolicy::query()->where('plan', UsageLimitPolicy::PLAN_LIGHT)->first();
            if ($row === null) {
                $this->line('制限テンプレ Light: 未保存（未保存時は製品既定を使用）');
            } else {
                $current = (int) (($row->limits['storage_quota_gb'] ?? 0));
                $this->line("制限テンプレ Light ストレージ: {$current}GB");
                $shouldSync = $this->option('force') || $current === 50 || $current <= 0;
                if ($shouldSync && $current !== $gb) {
                    $limitsArr = is_array($row->limits) ? $row->limits : [];
                    $limitsArr['storage_quota_gb'] = $gb;
                    $row->limits = $limitsArr;
                    $row->save();
                    $this->info("制限テンプレ Light を {$current}GB → {$gb}GB に更新しました");
                } elseif ($current !== $gb) {
                    $this->warn("制限テンプレが製品既定（{$gb}GB）と異なります。揃える場合は --force を付けて再実行してください。");
                } else {
                    $this->line('制限テンプレ Light は製品既定と一致しています');
                }
            }
        } else {
            $this->line('usage_limit_policies テーブルなし（スキップ）');
        }

        $this->line('本番では cron で毎分 `php artisan schedule:run` が必要です（休眠警告・削除は毎日 04:15）。');

        Artisan::call('schedule:list');
        $out = Artisan::output();
        if (str_contains($out, 'users:prune-dormant-light')) {
            $this->info('スケジュール: users:prune-dormant-light 登録済み');
        } else {
            $this->error('スケジュールに users:prune-dormant-light が見つかりません');
        }

        // suggestedTemplates の整合確認用（副作用なし）
        $suggested = $limits->suggestedTemplates()[UsageLimitPolicy::PLAN_LIGHT]['storage_quota_gb'] ?? null;
        $this->line('suggested Light GB: '.(string) $suggested);

        return self::SUCCESS;
    }
}
