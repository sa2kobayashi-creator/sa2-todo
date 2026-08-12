<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_albums', function (Blueprint $table) {
            $table->boolean('show_on_dashboard')->default(true)->after('is_hidden');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->boolean('show_on_dashboard')->default(true)->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('photo_albums', function (Blueprint $table) {
            $table->dropColumn('show_on_dashboard');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn('show_on_dashboard');
        });
    }
};
