<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('APIキーの識別名（例: DeepL Key 1）');
            $table->string('api_key');
            $table->string('provider')->default('deepl')->comment('プロバイダー: deepl');
            $table->string('api_url')->nullable()->comment('APIエンドポイントURL');
            $table->integer('daily_limit')->nullable()->comment('1日の使用制限（文字数）');
            $table->integer('monthly_limit')->nullable()->comment('1ヶ月の使用制限（文字数）');
            $table->integer('current_daily_usage')->default(0);
            $table->integer('current_monthly_usage')->default(0);
            $table->date('last_reset_date')->nullable();
            $table->date('last_monthly_reset_date')->nullable();
            $table->integer('error_count')->default(0);
            $table->timestamp('last_error_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0)->comment('優先順位（数値が大きいほど優先）');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'priority'], 'idx_active_priority');
            $table->index('provider', 'idx_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_api_keys');
    }
};
