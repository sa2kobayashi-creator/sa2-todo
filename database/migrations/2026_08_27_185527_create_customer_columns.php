<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier の顧客カラム。
 *
 * 標準の migration は trial_ends_at も作るが、本アプリは 2026-08-21 の課金受け皿で
 * 同名カラムを先に持っている。意味（お試し期限）は一致するのでそのまま共用し、
 * ここでは作らない。Cashier の onGenericTrial() と BillingEntitlementService::status()
 * が同じ列を見る。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four']);
        });
    }
};
