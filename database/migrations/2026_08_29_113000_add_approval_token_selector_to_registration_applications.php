<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_applications', function (Blueprint $table) {
            $table->string('approval_token_selector', 16)->nullable()->after('status');
            $table->unique('approval_token_selector');
        });
    }

    public function down(): void
    {
        Schema::table('registration_applications', function (Blueprint $table) {
            $table->dropUnique(['approval_token_selector']);
            $table->dropColumn('approval_token_selector');
        });
    }
};
