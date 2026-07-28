<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 新規写真は sort_order = min - 10（先頭表示）のため負数が必要。
        // unsignedInteger だと 0 未満で Out of range になる。
        if (! Schema::hasTable('photos')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `photos` MODIFY `sort_order` INT NOT NULL DEFAULT 0');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE photos ALTER COLUMN sort_order TYPE INTEGER USING sort_order::integer');
            DB::statement('ALTER TABLE photos ALTER COLUMN sort_order SET DEFAULT 0');
            DB::statement('ALTER TABLE photos ALTER COLUMN sort_order SET NOT NULL');

            return;
        }

        // sqlite 等: 型変更は実質不要（親和性が緩い）
    }

    public function down(): void
    {
        if (! Schema::hasTable('photos')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            // 負数があると失敗するため、先に 0 未満を 0 へ寄せる
            DB::table('photos')->where('sort_order', '<', 0)->update(['sort_order' => 0]);
            DB::statement('ALTER TABLE `photos` MODIFY `sort_order` INT UNSIGNED NOT NULL DEFAULT 0');
        }
    }
};
