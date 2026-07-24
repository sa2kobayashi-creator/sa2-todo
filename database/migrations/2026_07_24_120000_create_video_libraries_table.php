<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'sort_order']);
            $table->index(['user_id', 'is_default']);
        });

        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->foreignId('video_library_id')
                ->nullable()
                ->after('user_id')
                ->constrained('video_libraries')
                ->nullOnDelete();
            $table->index(['video_library_id', 'sort_order']);
        });

        // 既存ユーザーにマイリストを作成し、既存 YouTube 動画を紐付け
        $userIds = DB::table('youtube_videos')->distinct()->pluck('user_id')
            ->merge(DB::table('users')->pluck('id'))
            ->unique()
            ->values();

        foreach ($userIds as $userId) {
            $libraryId = DB::table('video_libraries')->insertGetId([
                'user_id' => $userId,
                'name' => 'マイリスト',
                'is_default' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('youtube_videos')
                ->where('user_id', $userId)
                ->whereNull('video_library_id')
                ->update(['video_library_id' => $libraryId]);
        }
    }

    public function down(): void
    {
        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_library_id');
        });
        Schema::dropIfExists('video_libraries');
    }
};
