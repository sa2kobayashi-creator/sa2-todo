<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('menu_features')->nullable()->after('role');
        });

        Schema::create('group_menu_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->string('feature', 40);
            $table->timestamps();

            $table->unique(['group_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_menu_features');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('menu_features');
        });
    }
};
