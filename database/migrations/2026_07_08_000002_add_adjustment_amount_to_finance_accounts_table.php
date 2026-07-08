<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->decimal('adjustment_amount', 15, 2)->default(0)->after('initial_balance')
                ->comment('手入力誤差などを補正する調整金額（残高計算に加算）');
        });
    }

    public function down(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropColumn('adjustment_amount');
        });
    }
};
