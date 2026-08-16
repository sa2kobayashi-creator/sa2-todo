<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->string('email', 255);
            $table->string('provider', 32)->default('custom'); // gmail|lolipop|custom
            $table->string('imap_host', 255);
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 16)->default('ssl'); // ssl|tls|none
            $table->string('smtp_host', 255);
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->string('smtp_encryption', 16)->default('ssl');
            $table->string('username', 255);
            $table->text('password'); // encrypted via cast
            $table->boolean('is_sa2_plus_mailbox')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 16)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'email']);
            $table->index(['user_id', 'provider']);
        });

        Schema::create('mail_domain_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('local_part', 64);
            $table->string('domain', 255)->default('sa2-plus.com');
            $table->string('status', 32)->default('pending'); // pending|approved|rejected|provisioned|suspended
            $table->text('user_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('provisioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('provisioned_at')->nullable();
            $table->string('provisioning_mode', 32)->default('manual'); // manual|api (api = future)
            $table->timestamps();

            $table->unique(['domain', 'local_part']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_domain_requests');
        Schema::dropIfExists('mail_accounts');
    }
};
