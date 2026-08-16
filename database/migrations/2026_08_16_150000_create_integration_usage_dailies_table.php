<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_usage_dailies', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('metric', 48);
            $table->date('usage_date');
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();
            $table->unique(['provider', 'metric', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_usage_dailies');
    }
};
