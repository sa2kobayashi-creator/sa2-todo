<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_message_headers', function (Blueprint $table) {
            $table->boolean('is_flagged')->default(false)->after('is_seen');
        });

        Schema::create('mail_sender_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('match_value', 255);
            $table->string('action', 32)->default('junk');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['mail_account_id', 'match_value']);
        });

        Schema::create('mail_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('folder_path', 255);
            $table->timestamps();

            $table->unique(['mail_account_id', 'name']);
            $table->unique(['mail_account_id', 'folder_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_folders');
        Schema::dropIfExists('mail_sender_rules');

        Schema::table('mail_message_headers', function (Blueprint $table) {
            $table->dropColumn('is_flagged');
        });
    }
};
