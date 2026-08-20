<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todo_shortcut_titles', function (Blueprint $table) {
            $table->json('reminders')->nullable()->after('end_time');
            $table->string('notify_via', 20)->nullable()->after('reminders');
        });
    }

    public function down(): void
    {
        Schema::table('todo_shortcut_titles', function (Blueprint $table) {
            $table->dropColumn(['reminders', 'notify_via']);
        });
    }
};
