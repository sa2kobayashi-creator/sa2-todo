<?php

use App\Models\UsageLimitPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 旧既定（Light 50GB）で保存された制限テンプレを製品既定（config の Light GB、通常 20）へ揃える。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('usage_limit_policies')) {
            return;
        }

        $productGb = max(1, (int) round(
            ((int) config('photos.user_free_quota_bytes', 20 * 1024 * 1024 * 1024)) / (1024 * 1024 * 1024)
        ));

        $row = UsageLimitPolicy::query()->where('plan', UsageLimitPolicy::PLAN_LIGHT)->first();
        if ($row === null) {
            return;
        }

        $limits = is_array($row->limits) ? $row->limits : [];
        $current = (int) ($limits['storage_quota_gb'] ?? 0);

        // 旧製品既定 50GB、または未設定のみ自動修正（意図的な他数値は触らない）
        if ($current === 50 || $current <= 0) {
            $limits['storage_quota_gb'] = $productGb;
            $row->limits = $limits;
            $row->save();
        }
    }

    public function down(): void
    {
        // 不可逆（意図的な運用値の復元はしない）
    }
};
