<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('region', 2);
            $table->string('kind', 20);
            $table->string('name');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('linked_bank_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->decimal('initial_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['region', 'kind']);
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_accounts');
    }
};
