<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_folders')) {
            $rows = DB::table('mail_folders')
                ->where('folder_path', 'like', 'Folders.%')
                ->where('folder_path', 'not like', 'INBOX.%')
                ->get(['id', 'folder_path']);
            foreach ($rows as $row) {
                DB::table('mail_folders')
                    ->where('id', $row->id)
                    ->update(['folder_path' => 'INBOX.'.$row->folder_path]);
            }
        }

        if (Schema::hasTable('mail_labels')) {
            $rows = DB::table('mail_labels')
                ->where('folder_path', 'like', 'Labels.%')
                ->where('folder_path', 'not like', 'INBOX.%')
                ->get(['id', 'folder_path']);
            foreach ($rows as $row) {
                DB::table('mail_labels')
                    ->where('id', $row->id)
                    ->update(['folder_path' => 'INBOX.'.$row->folder_path]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mail_folders')) {
            $rows = DB::table('mail_folders')
                ->where('folder_path', 'like', 'INBOX.Folders.%')
                ->get(['id', 'folder_path']);
            foreach ($rows as $row) {
                DB::table('mail_folders')
                    ->where('id', $row->id)
                    ->update(['folder_path' => substr($row->folder_path, strlen('INBOX.'))]);
            }
        }

        if (Schema::hasTable('mail_labels')) {
            $rows = DB::table('mail_labels')
                ->where('folder_path', 'like', 'INBOX.Labels.%')
                ->get(['id', 'folder_path']);
            foreach ($rows as $row) {
                DB::table('mail_labels')
                    ->where('id', $row->id)
                    ->update(['folder_path' => substr($row->folder_path, strlen('INBOX.'))]);
            }
        }
    }
};
