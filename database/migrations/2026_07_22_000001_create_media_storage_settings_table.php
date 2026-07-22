<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_storage_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->unique(); // r2 | cloudinary | backblaze | pipeline
            $table->boolean('enabled')->default(false);
            $table->json('settings')->nullable();
            $table->text('secrets')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 16)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->string('cloudinary_public_id', 255)->nullable()->after('thumb_path');
            $table->string('storage_tier', 16)->default('hot')->after('cloudinary_public_id');
            $table->string('cold_disk', 32)->nullable()->after('storage_tier');
            $table->string('cold_path', 500)->nullable()->after('cold_disk');
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn(['cloudinary_public_id', 'storage_tier', 'cold_disk', 'cold_path']);
        });
        Schema::dropIfExists('media_storage_settings');
    }
};
