<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_id')
                ->nullable()
                ->after('recipient_user_id')
                ->constrained('group_messages')
                ->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('body');
            $table->softDeletes();
        });

        Schema::create('group_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_message_id')->constrained('group_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            $table->unique(['group_message_id', 'user_id', 'emoji'], 'gmr_message_user_emoji_unique');
            $table->index(['group_message_id', 'emoji']);
        });

        Schema::create('group_message_hides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_message_id')->constrained('group_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group_message_id', 'user_id'], 'gmh_message_user_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
        Schema::dropIfExists('group_message_hides');
        Schema::dropIfExists('group_message_reactions');
        Schema::table('group_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn('edited_at');
            $table->dropSoftDeletes();
        });
    }
};
