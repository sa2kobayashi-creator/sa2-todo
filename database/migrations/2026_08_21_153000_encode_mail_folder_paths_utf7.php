<?php

use App\Services\MailClientService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /** @var MailClientService $client */
        $client = app(MailClientService::class);

        if (Schema::hasTable('mail_folders')) {
            $rows = DB::table('mail_folders')->get(['id', 'folder_path']);
            foreach ($rows as $row) {
                $encoded = $client->encodeImapMailboxPath((string) $row->folder_path);
                if ($encoded !== '' && $encoded !== $row->folder_path) {
                    DB::table('mail_folders')->where('id', $row->id)->update(['folder_path' => $encoded]);
                }
            }
        }

        if (Schema::hasTable('mail_labels')) {
            $rows = DB::table('mail_labels')->get(['id', 'folder_path']);
            foreach ($rows as $row) {
                $encoded = $client->encodeImapMailboxPath((string) $row->folder_path);
                if ($encoded !== '' && $encoded !== $row->folder_path) {
                    DB::table('mail_labels')->where('id', $row->id)->update(['folder_path' => $encoded]);
                }
            }
        }
    }

    public function down(): void
    {
        // 不可逆（UTF-7 → 元表記へ戻すと IMAP 実体とずれる可能性があるため no-op）
    }
};
