<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_promos', function (Blueprint $table) {
            $table->string('external_key', 120)->nullable()->after('user_id');
            $table->boolean('auto_fetched')->default(false)->after('notes');
            $table->date('travel_from')->nullable()->after('valid_until');
            $table->date('travel_until')->nullable()->after('travel_from');
            $table->timestamp('last_seen_at')->nullable()->after('auto_fetched');

            $table->unique(['user_id', 'external_key']);
        });
    }

    public function down(): void
    {
        Schema::table('travel_promos', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'external_key']);
            $table->dropColumn([
                'external_key',
                'auto_fetched',
                'travel_from',
                'travel_until',
                'last_seen_at',
            ]);
        });
    }
};
