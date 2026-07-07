<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_entries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name');
            $table->string('source', 30);
            $table->timestamps();

            $table->index('date');
        });

        Schema::create('weekday_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->json('weekdays');
            $table->json('exceptions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekday_rules');
        Schema::dropIfExists('holiday_entries');
    }
};
