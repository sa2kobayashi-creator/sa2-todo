<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('provider', 32); // openai | gemini
            $table->string('plan', 16)->default('paid'); // free | paid
            $table->text('api_key');
            $table->string('default_model', 80)->nullable();
            $table->unsignedInteger('daily_limit')->nullable();
            $table->unsignedInteger('monthly_limit')->nullable();
            $table->unsignedInteger('current_daily_usage')->default(0);
            $table->unsignedInteger('current_monthly_usage')->default(0);
            $table->date('last_reset_date')->nullable();
            $table->date('last_monthly_reset_date')->nullable();
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamp('last_error_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['provider', 'is_active', 'priority']);
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200)->default('新しい相談');
            $table->string('provider', 32); // openai | gemini
            $table->string('model', 80);
            $table->foreignId('ai_api_key_id')->nullable()->constrained('ai_api_keys')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 16); // user | assistant | system
            $table->longText('content');
            $table->unsignedInteger('token_estimate')->default(0);
            $table->timestamps();

            $table->index(['ai_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_api_keys');
    }
};
