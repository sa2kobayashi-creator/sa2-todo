<?php

use App\Enums\UserRole;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'owner_user_id']);
        });

        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->after('user_id')->constrained('groups')->nullOnDelete();
            $table->index(['user_id', 'group_id']);
        });

        $adminId = User::query()
            ->where('role', UserRole::Admin->value)
            ->orderBy('id')
            ->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if ($adminId) {
            Todo::query()->whereNull('user_id')->update([
                'user_id' => $adminId,
                'group_id' => null,
            ]);
        }

        Schema::table('photo_albums', function (Blueprint $table) {
            $table->string('visibility', 20)->default('private')->after('description');
            $table->foreignId('group_id')->nullable()->after('visibility')->constrained('groups')->nullOnDelete();
            $table->index(['visibility', 'group_id']);
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->foreignId('parent_photo_id')->nullable()->after('album_id')->constrained('photos')->nullOnDelete();
            $table->string('edit_label', 120)->nullable()->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_photo_id');
            $table->dropColumn('edit_label');
        });

        Schema::table('photo_albums', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn('visibility');
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
    }
};
