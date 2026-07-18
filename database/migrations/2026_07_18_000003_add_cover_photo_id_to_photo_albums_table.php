<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_albums', function (Blueprint $table) {
            $table->foreignId('cover_photo_id')
                ->nullable()
                ->after('description')
                ->constrained('photos')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('photo_albums', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_photo_id');
        });
    }
};
