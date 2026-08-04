<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 20)->default('text'); // text|image|document|website
            $table->string('source_lang', 8);
            $table->string('target_lang', 8);
            $table->text('source_text');
            $table->text('translated_text');
            $table->string('title', 255)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->boolean('is_saved')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'is_saved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_histories');
    }
};
