<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('folder_path', 255);
            $table->string('color', 16)->default('#0f766e');
            $table->timestamps();

            $table->unique(['mail_account_id', 'name']);
            $table->unique(['mail_account_id', 'folder_path']);
        });

        Schema::create('mail_label_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_label_id')->constrained('mail_labels')->cascadeOnDelete();
            $table->string('match_field', 32); // from|subject|to
            $table->string('match_operator', 32)->default('contains'); // contains|equals
            $table->string('match_value', 255);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['mail_label_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_label_rules');
        Schema::dropIfExists('mail_labels');
    }
};
