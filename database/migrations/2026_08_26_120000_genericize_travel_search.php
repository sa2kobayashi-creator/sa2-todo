<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_profiles', function (Blueprint $table) {
            $table->boolean('procedures_enabled')->default(false)->after('alert_days_ar');
            $table->boolean('promo_watch_enabled')->default(false)->after('procedures_enabled');
        });

        // 既存の 13A / RP / AR 利用者は手続きパックを維持する
        DB::table('travel_profiles')
            ->where(function ($query) {
                $query->whereNotNull('rp_expires_on')
                    ->orWhereNotNull('annual_report_done_year')
                    ->orWhere('visa_type', '13A');
            })
            ->update(['procedures_enabled' => true]);

        $promoUserIds = DB::table('travel_promos')->select('user_id');
        DB::table('travel_profiles')
            ->where(function ($query) use ($promoUserIds) {
                $query->where('airline_code', '5J')
                    ->orWhereIn('user_id', $promoUserIds);
            })
            ->update(['promo_watch_enabled' => true]);

        Schema::create('travel_fare_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 8);
            $table->string('destination', 8);
            $table->string('airline_code', 8)->nullable();
            $table->string('mode', 8)->default('ow'); // ow | rt
            $table->string('currency', 3)->default('JPY');
            $table->date('depart_from');
            $table->date('depart_to');
            $table->date('return_from')->nullable();
            $table->date('return_to')->nullable();
            $table->unsignedInteger('max_price')->nullable();
            $table->unsignedInteger('last_best_price')->nullable();
            $table->string('last_best_on', 32)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'depart_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_fare_watches');
        Schema::table('travel_profiles', function (Blueprint $table) {
            $table->dropColumn(['procedures_enabled', 'promo_watch_enabled']);
        });
    }
};
