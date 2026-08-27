<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'special_quota')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('special_quota')->default(false)->after('mailbox_addon_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'special_quota')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('special_quota');
        });
    }
};
