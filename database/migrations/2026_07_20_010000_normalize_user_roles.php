<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'user')->update(['role' => 'standard']);
    }

    public function down(): void
    {
        DB::table('users')->whereIn('role', ['standard', 'light'])->update(['role' => 'user']);
    }
};
