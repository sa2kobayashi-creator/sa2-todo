<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('travel_profiles')->update([
            'procedures_enabled' => false,
            'promo_watch_enabled' => false,
        ]);

        DB::table('travel_alerts')
            ->whereIn('type', ['rp_deadline', 'ar_deadline', 'promo'])
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function down(): void
    {
        // 画面から外したパックを自動では戻さない
    }
};
