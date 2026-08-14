<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_message_thread_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            // 0 = グループ全体チャット。DM は相手 user_id。
            $table->unsignedBigInteger('peer_user_id')->default(0);
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'group_id', 'peer_user_id'], 'gm_thread_reads_user_group_peer_unique');
            $table->index(['user_id', 'last_read_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_message_thread_reads');
    }
};
