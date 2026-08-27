<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe Webhook の受信ログ。stripe_event_id の unique が冪等性の要で、
 * Stripe が同じイベントを再送しても二重に反映されないようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('received');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
    }
};
