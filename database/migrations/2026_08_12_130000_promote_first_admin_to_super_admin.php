<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 既存の先頭管理者をスーパー管理者へ（招待コード設定を失わない）
        $firstAdminId = DB::table('users')
            ->where('role', UserRole::Admin->value)
            ->orderBy('id')
            ->value('id');

        if ($firstAdminId) {
            DB::table('users')->where('id', $firstAdminId)->update([
                'role' => UserRole::SuperAdmin->value,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', UserRole::SuperAdmin->value)
            ->update(['role' => UserRole::Admin->value]);
    }
};
