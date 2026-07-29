<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // アーカイブ復元などで sort_order = min - 10 となり負数が必要。
        if (! Schema::hasTable('notes')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `notes` MODIFY `sort_order` INT NOT NULL DEFAULT 0');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notes ALTER COLUMN sort_order TYPE INTEGER USING sort_order::integer');
            DB::statement('ALTER TABLE notes ALTER COLUMN sort_order SET DEFAULT 0');
            DB::statement('ALTER TABLE notes ALTER COLUMN sort_order SET NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notes')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::table('notes')->where('sort_order', '<', 0)->update(['sort_order' => 0]);
            DB::statement('ALTER TABLE `notes` MODIFY `sort_order` INT UNSIGNED NOT NULL DEFAULT 0');
        }
    }
};
