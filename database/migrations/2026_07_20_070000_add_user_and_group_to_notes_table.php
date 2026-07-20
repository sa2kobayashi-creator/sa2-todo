<?php

use App\Enums\UserRole;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->after('user_id')->constrained('groups')->nullOnDelete();
            $table->index(['user_id', 'group_id']);
        });

        $adminId = User::query()->where('email', 'admin@example.com')->value('id')
            ?? User::query()->where('role', UserRole::Admin)->orderBy('id')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if ($adminId) {
            Note::query()->whereNull('user_id')->update([
                'user_id' => $adminId,
                'group_id' => null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
