<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('app_context', 20)->default('personal')->after('menu_features');
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->string('context', 20)->default('personal')->after('group_id');
            $table->string('google_event_id', 256)->nullable()->after('notified_at');
            $table->string('google_calendar_id', 256)->nullable()->after('google_event_id');
            $table->timestamp('google_synced_at')->nullable()->after('google_calendar_id');
            $table->index(['user_id', 'context']);
            $table->unique(['user_id', 'google_event_id']);
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->string('context', 20)->default('personal')->after('group_id');
            $table->index(['user_id', 'context']);
        });

        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->json('selected_calendar_ids')->nullable()->after('scopes');
            $table->string('sync_calendar_id', 256)->nullable()->after('selected_calendar_ids');
        });

        // 既存データはすべてプライベート扱い
        DB::table('todos')->update(['context' => 'personal']);
        DB::table('notes')->update(['context' => 'personal']);
    }

    public function down(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->dropColumn(['selected_calendar_ids', 'sync_calendar_id']);
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'context']);
            $table->dropColumn('context');
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'google_event_id']);
            $table->dropIndex(['user_id', 'context']);
            $table->dropColumn(['context', 'google_event_id', 'google_calendar_id', 'google_synced_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('app_context');
        });
    }
};
