<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'sort_order']);
            $table->index(['user_id', 'is_default']);
        });

        Schema::table('music_tracks', function (Blueprint $table) {
            $table->foreignId('music_library_id')
                ->nullable()
                ->after('user_id')
                ->constrained('music_libraries')
                ->nullOnDelete();
            $table->index(['music_library_id', 'sort_order']);
        });

        // 既存の曲を持つユーザーにマイリストを作り、既存曲を紐付ける
        $userIds = DB::table('music_tracks')->distinct()->pluck('user_id');

        foreach ($userIds as $userId) {
            $libraryId = DB::table('music_libraries')->insertGetId([
                'user_id' => $userId,
                'name' => 'マイリスト',
                'is_default' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('music_tracks')
                ->where('user_id', $userId)
                ->whereNull('music_library_id')
                ->update(['music_library_id' => $libraryId]);
        }
    }

    public function down(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('music_library_id');
        });
        Schema::dropIfExists('music_libraries');
    }
};
