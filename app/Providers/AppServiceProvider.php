<?php

namespace App\Providers;

use App\Services\MediaStorageConfigService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        try {
            app(MediaStorageConfigService::class)->applyRuntimeDisks();
        } catch (\Throwable) {
            // マイグレーション前などテーブル未作成時は無視
        }
    }
}
