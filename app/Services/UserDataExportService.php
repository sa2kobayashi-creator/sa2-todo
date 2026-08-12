<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Models\MusicTrack;
use App\Models\Note;
use App\Models\Photo;
use App\Models\Todo;
use App\Models\TranslationHistory;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class UserDataExportService
{
    /**
     * ユーザーデータの ZIP（JSON）を一時ディスクに書き、相対パスを返す。
     */
    public function createZip(User $user): string
    {
        $disk = Storage::disk('local');
        $dir = 'exports/'.$user->id;
        $disk->makeDirectory($dir);

        $stamp = now()->format('Ymd_His');
        $relative = $dir.'/sa2-export-'.$stamp.'.zip';
        $absolute = $disk->path($relative);

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'profile' => [
                'email' => $user->email,
                'display_name' => $user->display_name,
                'role' => $user->role,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'todos' => Todo::query()->where('user_id', $user->id)->orderBy('id')->get()->toArray(),
            'notes' => Note::query()->where('user_id', $user->id)->orderBy('id')->get()->toArray(),
            'photos' => Photo::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get(['id', 'path', 'original_name', 'mime', 'size_bytes', 'caption', 'taken_at', 'created_at'])
                ->toArray(),
            'music' => MusicTrack::query()->where('user_id', $user->id)->orderBy('id')->get()->toArray(),
            'finance_transactions' => FinanceTransaction::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->limit(5000)
                ->get()
                ->toArray(),
            'translation_history' => TranslationHistory::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(500)
                ->get()
                ->toArray(),
            'notes_about_export' => [
                'photos_and_music_files_are_not_included' => true,
                'hint' => 'Binary media files are omitted; metadata lists paths and names only.',
            ],
        ];

        $zip = new ZipArchive;
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create export zip.');
        }

        $zip->addFromString(
            'export.json',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n"
        );
        $zip->close();

        return $relative;
    }
}
