<?php

namespace App\Providers;

use App\Services\MediaStorageConfigService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        try {
            app(MediaStorageConfigService::class)->applyRuntimeDisks();
        } catch (\Throwable) {
            // マイグレーション前などテーブル未作成時は無視
        }
    }
}
