<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_stats_daily', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date');
            $table->string('event_key', 80);
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['stat_date', 'event_key']);
            $table->index('event_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_stats_daily');
    }
};
