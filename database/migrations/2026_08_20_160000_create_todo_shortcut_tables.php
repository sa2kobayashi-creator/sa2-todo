<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_shortcut_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('icon', 16);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'sort_order']);
        });

        Schema::create('todo_shortcut_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('todo_shortcut_category_id')
                ->constrained('todo_shortcut_categories')
                ->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['todo_shortcut_category_id', 'sort_order'], 'todo_shortcut_titles_cat_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_shortcut_titles');
        Schema::dropIfExists('todo_shortcut_categories');
    }
};
