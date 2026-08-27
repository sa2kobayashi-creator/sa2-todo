<?php

use App\Console\Commands\ArchivePhotosToBackblaze;
use App\Console\Commands\BackupDatabaseToB2Command;
use App\Console\Commands\ManageStorageCommand;
use App\Console\Commands\SyncMailMetadata;
use App\Console\Commands\TickPhotoColdArchive;
use App\Services\PhotoColdArchiveRunService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(ManageStorageCommand::class)
    ->hourly()
    ->withoutOverlapping(10);

Schedule::command(BackupDatabaseToB2Command::class)
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command(ArchivePhotosToBackblaze::class)
    ->dailyAt('03:30')
    ->withoutOverlapping();

// Photos の「B2へアーカイブ」をバックグラウンドで進める（実行中のときだけ）
Schedule::command(TickPhotoColdArchive::class)
    ->everyMinute()
    ->when(fn () => app(PhotoColdArchiveRunService::class)->isRunning())
    ->withoutOverlapping(5);

Schedule::command(\App\Console\Commands\SendTodoReminders::class)
    ->everyMinute()
    ->withoutOverlapping();

// 一覧はローカルDBから即時表示。1回のcronで1アカウントだけ同期（ロリポップ向け短時間実行）
Schedule::command(SyncMailMetadata::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(4);
