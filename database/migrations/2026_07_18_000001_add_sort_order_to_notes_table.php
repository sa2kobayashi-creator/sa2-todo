<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('pinned');
            $table->index('sort_order');
        });

        $groups = DB::table('notes')
            ->orderByDesc('pinned')
            ->orderBy('archived')
            ->orderByDesc('registered_date')
            ->orderByDesc('updated_at')
            ->get(['id', 'pinned', 'archived']);

        $counters = [];
        foreach ($groups as $row) {
            $key = ((int) $row->pinned).'-'.((int) $row->archived);
            $counters[$key] = ($counters[$key] ?? 0) + 1;
            DB::table('notes')->where('id', $row->id)->update([
                'sort_order' => $counters[$key] * 10,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
