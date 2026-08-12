<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `translation_api_keys` MODIFY `api_key` TEXT NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE translation_api_keys ALTER COLUMN api_key TYPE TEXT');
        }

        $rows = DB::table('translation_api_keys')->select('id', 'api_key')->get();
        foreach ($rows as $row) {
            $plain = (string) $row->api_key;
            if ($plain === '' || $this->looksEncrypted($plain)) {
                continue;
            }

            DB::table('translation_api_keys')->where('id', $row->id)->update([
                'api_key' => Crypt::encryptString($plain),
            ]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('translation_api_keys')->select('id', 'api_key')->get();
        foreach ($rows as $row) {
            $stored = (string) $row->api_key;
            try {
                $plain = Crypt::decryptString($stored);
            } catch (\Throwable) {
                continue;
            }

            DB::table('translation_api_keys')->where('id', $row->id)->update([
                'api_key' => $plain,
            ]);
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `translation_api_keys` MODIFY `api_key` VARCHAR(255) NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE translation_api_keys ALTER COLUMN api_key TYPE VARCHAR(255)');
        }
    }

    private function looksEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
};
