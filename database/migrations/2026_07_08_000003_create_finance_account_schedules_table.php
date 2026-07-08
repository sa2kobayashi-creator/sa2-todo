<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_account_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->string('schedule_type', 20)->comment('payment=支払予定, deposit=入金予定');
            $table->date('scheduled_date');
            $table->decimal('amount', 15, 2);
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'scheduled_date']);
            $table->index('schedule_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_account_schedules');
    }
};
