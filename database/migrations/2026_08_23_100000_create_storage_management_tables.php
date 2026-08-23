<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_archives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('mail_account_id')->index();
            // 複合ユニーク索引に入れるため、InnoDB の索引長制限に収まる長さにしておく。
            $table->string('folder_path', 120)->default('INBOX');
            $table->unsignedBigInteger('imap_uid')->nullable();
            $table->string('message_id', 512)->nullable();
            $table->string('subject', 998)->nullable();
            $table->string('from_address', 512)->nullable();
            $table->string('to_address', 512)->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->boolean('has_attachments')->default(false);
            $table->string('storage_provider', 16)->default('b2');
            $table->string('storage_key', 512);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['mail_account_id', 'sent_at']);
            // 退避後に IMAP 側の削除が失敗しても、次回実行で二重に保存しない。
            $table->unique(['mail_account_id', 'folder_path', 'imap_uid'], 'mail_archives_source_unique');
        });

        Schema::create('data_archives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('title', 255)->nullable();
            $table->string('summary', 512)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('storage_provider', 16)->default('b2');
            $table->string('storage_key', 512);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['source_table', 'source_id']);
            $table->index(['user_id', 'source_table']);
        });

        Schema::create('storage_management_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('ran_at');
            $table->unsignedBigInteger('r2_bytes')->default(0);
            $table->unsignedBigInteger('mail_bytes')->default(0);
            $table->unsignedBigInteger('db_bytes')->default(0);
            $table->string('r2_status', 24)->default('ok');
            $table->string('mail_status', 24)->default('ok');
            $table->string('db_status', 24)->default('ok');
            $table->unsignedInteger('mail_archived')->default(0);
            $table->unsignedInteger('photos_archived')->default(0);
            $table->unsignedInteger('db_archived')->default(0);
            $table->string('backup_key', 512)->nullable();
            $table->json('notes')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('todos') && ! Schema::hasColumn('todos', 'keep_on_server')) {
            Schema::table('todos', function (Blueprint $table) {
                $table->boolean('keep_on_server')->default(false)->after('completed');
            });
        }

        if (Schema::hasTable('notes') && ! Schema::hasColumn('notes', 'keep_on_server')) {
            Schema::table('notes', function (Blueprint $table) {
                $table->boolean('keep_on_server')->default(false)->after('archived');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notes') && Schema::hasColumn('notes', 'keep_on_server')) {
            Schema::table('notes', function (Blueprint $table) {
                $table->dropColumn('keep_on_server');
            });
        }
        if (Schema::hasTable('todos') && Schema::hasColumn('todos', 'keep_on_server')) {
            Schema::table('todos', function (Blueprint $table) {
                $table->dropColumn('keep_on_server');
            });
        }
        Schema::dropIfExists('storage_management_logs');
        Schema::dropIfExists('data_archives');
        Schema::dropIfExists('mail_archives');
    }
};
