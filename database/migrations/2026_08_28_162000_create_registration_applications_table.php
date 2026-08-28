<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_applications', function (Blueprint $table) {
            $table->id();
            $table->string('plan', 20); // light | standard | tenant
            $table->string('email');
            $table->string('display_name', 100);
            $table->string('organization_name', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|completed|expired
            $table->string('approval_token_hash', 255)->nullable();
            $table->timestamp('approval_token_expires_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('admin_note', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_applications');
    }
};
