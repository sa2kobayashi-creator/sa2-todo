<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_api_keys');
    }

    public function down(): void
    {
        // Intentionally empty: AI相談機能は削除済みのため復元しない
    }
};
