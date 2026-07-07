<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            $table->string('type', 20);
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('to_amount', 15, 2)->nullable();
            $table->string('currency', 3);
            $table->string('to_currency', 3)->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->index('transaction_date');
            $table->index(['type', 'transaction_date']);
            $table->index('account_id');
            $table->index('to_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
    }
};
