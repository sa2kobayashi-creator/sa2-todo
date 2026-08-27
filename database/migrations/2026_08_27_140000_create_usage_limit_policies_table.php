<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_limit_policies', function (Blueprint $table) {
            $table->id();
            $table->string('plan', 32);
            $table->json('limits');
            $table->timestamps();

            $table->unique('plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_limit_policies');
    }
};
