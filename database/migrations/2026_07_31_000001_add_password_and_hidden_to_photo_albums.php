<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_albums', function (Blueprint $table) {
            $table->string('password_hash', 255)->nullable()->after('description');
            $table->boolean('is_hidden')->default(false)->after('password_hash');
        });
    }

    public function down(): void
    {
        Schema::table('photo_albums', function (Blueprint $table) {
            $table->dropColumn(['password_hash', 'is_hidden']);
        });
    }
};
