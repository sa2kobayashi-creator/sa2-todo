<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('chat_bg_type', 16)->nullable()->after('review_note');
            $table->string('chat_bg_theme', 32)->nullable()->after('chat_bg_type');
            $table->string('chat_bg_disk', 64)->nullable()->after('chat_bg_theme');
            $table->string('chat_bg_path', 512)->nullable()->after('chat_bg_disk');
            $table->timestamp('chat_bg_updated_at')->nullable()->after('chat_bg_path');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn([
                'chat_bg_type',
                'chat_bg_theme',
                'chat_bg_disk',
                'chat_bg_path',
                'chat_bg_updated_at',
            ]);
        });
    }
};
