<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_daily_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->string('feature', 64);
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'usage_date', 'feature'], 'user_daily_usages_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_daily_usages');
    }
};
