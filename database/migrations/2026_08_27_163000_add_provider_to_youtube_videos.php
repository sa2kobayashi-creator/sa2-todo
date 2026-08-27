<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->string('provider', 16)->default('youtube')->after('user_id');
        });

        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'youtube_id']);
            $table->unique(['user_id', 'provider', 'youtube_id']);
        });
    }

    public function down(): void
    {
        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'provider', 'youtube_id']);
            $table->unique(['user_id', 'youtube_id']);
        });

        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
