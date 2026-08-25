<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || Schema::hasColumn('tenants', 'trial_ends_at')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->date('trial_ends_at')->nullable()->after('allow_own_keys');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'trial_ends_at')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }
};
