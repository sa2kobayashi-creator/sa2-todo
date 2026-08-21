<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Standard 既定から Travel を外す前に、ロール既定のまま（menu_features が null）の
 * 既存 Standard ユーザーへ「変更前の既定メニュー」を明示保存する。
 * これをしないと、既定変更と同時に家族・招待ユーザーから Travel が消える。
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = UserRole::legacyStandardMenuFeatures();
        $encoded = json_encode(array_values($legacy), JSON_UNESCAPED_UNICODE);

        DB::table('users')
            ->where('role', UserRole::Standard->value)
            ->whereNull('menu_features')
            ->update(['menu_features' => $encoded]);
    }

    public function down(): void
    {
        // 明示保存した内容を消すと Travel 以外のカスタム意図も失うため、down では触らない
    }
};
