<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translation_api_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('deepl_character_count')->nullable()->after('notes');
            $table->unsignedBigInteger('deepl_character_limit')->nullable()->after('deepl_character_count');
            $table->timestamp('deepl_usage_fetched_at')->nullable()->after('deepl_character_limit');
        });
    }

    public function down(): void
    {
        Schema::table('translation_api_keys', function (Blueprint $table) {
            $table->dropColumn([
                'deepl_character_count',
                'deepl_character_limit',
                'deepl_usage_fetched_at',
            ]);
        });
    }
};
