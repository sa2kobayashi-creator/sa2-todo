<?php

use App\Console\Commands\ArchivePhotosToBackblaze;
use App\Console\Commands\CheckTravelAlerts;
use App\Console\Commands\FetchTravelPromos;
use App\Console\Commands\TickPhotoColdArchive;
use App\Services\PhotoColdArchiveRunService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(ArchivePhotosToBackblaze::class)
    ->dailyAt('03:30')
    ->withoutOverlapping();

// Photos の「B2へアーカイブ」をバックグラウンドで進める（実行中のときだけ）
Schedule::command(TickPhotoColdArchive::class)
    ->everyMinute()
    ->when(fn () => app(PhotoColdArchiveRunService::class)->isRunning())
    ->withoutOverlapping(5);

Schedule::command(CheckTravelAlerts::class)
    ->dailyAt('08:00')
    ->withoutOverlapping();

// Seat Sale は深夜〜早朝（JST）に出ることが多いため、夜間は30分ごと・昼間は2時間ごと
Schedule::command(FetchTravelPromos::class)
    ->cron('*/30 0-7,22-23 * * *')
    ->withoutOverlapping();

Schedule::command(FetchTravelPromos::class)
    ->cron('0 8,10,12,14,16,18,20 * * *')
    ->withoutOverlapping();
