<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
        });

        Schema::table('finance_expense_categories', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
        });

        $adminId = User::query()
            ->where('role', UserRole::Admin->value)
            ->orderBy('id')
            ->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if ($adminId) {
            DB::table('finance_accounts')->whereNull('user_id')->update(['user_id' => $adminId]);
            DB::table('finance_transactions')->whereNull('user_id')->update(['user_id' => $adminId]);
            DB::table('finance_expense_categories')->whereNull('user_id')->update(['user_id' => $adminId]);
        }

        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->unique(['user_id', 'slug']);
            $table->index('user_id');
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('finance_expense_categories', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->unique(['user_id', 'slug']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('finance_expense_categories', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'slug']);
            $table->dropIndex(['user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique('slug');
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'slug']);
            $table->dropIndex(['user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique('slug');
        });
    }
};
