<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->boolean('completed')->default(false);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->string('importance', 10)->default('medium');
            $table->string('category', 20)->default('task');
            $table->json('reminders')->nullable();
            $table->string('notify_via', 20)->nullable();
            $table->json('notified_at')->nullable();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
            $table->index('completed');
            $table->index('category');
            $table->index('importance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
