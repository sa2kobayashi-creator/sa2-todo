<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('inviter_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['group_id', 'invitee_user_id']);
            $table->index(['invitee_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_invitations');
    }
};
