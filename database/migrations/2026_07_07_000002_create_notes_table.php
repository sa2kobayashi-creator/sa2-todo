<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('');
            $table->text('body')->nullable();
            $table->string('color', 20)->default('yellow');
            $table->boolean('pinned')->default(false);
            $table->boolean('archived')->default(false);
            $table->string('type', 20)->default('text');
            $table->json('items')->nullable();
            $table->date('registered_date')->nullable();
            $table->timestamps();

            $table->index('pinned');
            $table->index('archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
