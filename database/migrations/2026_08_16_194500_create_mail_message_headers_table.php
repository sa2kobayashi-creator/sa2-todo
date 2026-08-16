<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->timestamp('last_synced_at')->nullable()->after('last_test_message');
            $table->string('last_sync_status', 16)->nullable()->after('last_synced_at');
            $table->string('last_sync_message', 500)->nullable()->after('last_sync_status');
        });

        Schema::create('mail_message_headers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('folder_path', 255)->default('INBOX');
            $table->unsignedBigInteger('imap_uid');
            $table->string('subject', 998)->nullable();
            $table->string('from_address', 512)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->boolean('is_seen')->default(false);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['mail_account_id', 'folder_path', 'imap_uid'], 'mail_headers_account_folder_uid_unique');
            $table->index(['mail_account_id', 'folder_path', 'received_at'], 'mail_headers_account_folder_received_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_message_headers');

        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn(['last_synced_at', 'last_sync_status', 'last_sync_message']);
        });
    }
};
