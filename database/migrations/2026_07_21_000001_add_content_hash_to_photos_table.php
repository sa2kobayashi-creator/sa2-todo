<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('size_bytes');
            $table->unique(['user_id', 'content_hash'], 'photos_user_content_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropUnique('photos_user_content_hash_unique');
            $table->dropColumn('content_hash');
        });
    }
};
