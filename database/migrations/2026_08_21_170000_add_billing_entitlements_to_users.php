<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 課金の受け皿。Stripe 前でも管理者が手動で立てられる。
 * role（Light/Standard）は権限・メニュー、こちらは契約状態。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('subscription_status', 32)->default('none')->after('timezone');
            $table->timestamp('trial_ends_at')->nullable()->after('subscription_status');
            $table->boolean('storage_overage_active')->default(false)->after('trial_ends_at');
            $table->boolean('mailbox_addon_active')->default(false)->after('storage_overage_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_status',
                'trial_ends_at',
                'storage_overage_active',
                'mailbox_addon_active',
            ]);
        });
    }
};
