<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_profiles', function (Blueprint $table) {
            $table->boolean('alerts_enabled')->default(true)->after('notes');
            $table->unsignedSmallInteger('alert_days_rp')->default(90)->after('alerts_enabled');
            $table->unsignedSmallInteger('alert_days_ar')->default(60)->after('alert_days_rp');
        });

        Schema::create('travel_promos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('title', 160)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('applies_to', 16)->default('both'); // fuk_mnl | mnl_fuk | both | unknown
            $table->string('status', 16)->default('watching'); // watching | usable | used | invalid | expired
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'code']);
        });

        Schema::create('travel_fare_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('travel_trip_id')->constrained('travel_trips')->cascadeOnDelete();
            $table->unsignedInteger('rt_price_php')->nullable();
            $table->unsignedInteger('ow_out_price_php')->nullable();
            $table->unsignedInteger('ow_back_price_php')->nullable();
            $table->unsignedInteger('rt_price_jpy')->nullable();
            $table->unsignedInteger('ow_out_price_jpy')->nullable();
            $table->unsignedInteger('ow_back_price_jpy')->nullable();
            $table->string('winner_php', 8)->nullable();
            $table->string('winner_jpy', 8)->nullable();
            $table->boolean('under_budget_jpy')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['travel_trip_id', 'captured_at']);
            $table->index(['user_id', 'captured_at']);
        });

        Schema::create('travel_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // rp_deadline | ar_deadline | budget | promo
            $table->string('severity', 16)->default('info'); // info | warn | danger
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('dedupe_key', 120);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_alerts');
        Schema::dropIfExists('travel_fare_snapshots');
        Schema::dropIfExists('travel_promos');

        Schema::table('travel_profiles', function (Blueprint $table) {
            $table->dropColumn(['alerts_enabled', 'alert_days_rp', 'alert_days_ar']);
        });
    }
};
