<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_domain_requests', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_domain_requests', function (Blueprint $table) {
            $table->dropColumn('cancel_requested_at');
        });
    }
};
