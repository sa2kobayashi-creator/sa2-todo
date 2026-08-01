<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
            $table->string('pending_email_token', 64)->nullable()->after('pending_email');
            $table->timestamp('pending_email_expires_at')->nullable()->after('pending_email_token');
            $table->unsignedTinyInteger('pending_email_attempts')->default(0)->after('pending_email_expires_at');
            $table->timestamp('pending_email_sent_at')->nullable()->after('pending_email_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'pending_email',
                'pending_email_token',
                'pending_email_expires_at',
                'pending_email_attempts',
                'pending_email_sent_at',
            ]);
        });
    }
};
