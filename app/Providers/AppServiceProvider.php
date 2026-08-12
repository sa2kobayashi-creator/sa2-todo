<?php

namespace App\Providers;

use App\Services\MediaStorageConfigService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaStorageConfigService::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        try {
            app(MediaStorageConfigService::class)->applyRuntimeDisks();
        } catch (\Throwable) {
            // マイグレーション前などテーブル未作成時は無視
        }
    }

    private function configureRateLimiting(): void
    {
        $unlimited = $this->app->environment('testing');

        RateLimiter::for('auth-login', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('auth-register', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(3)->by($request->ip()));

        RateLimiter::for('auth-password', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('ai-translate', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(10)->by((string) ($request->user()?->id ?: $request->ip())));

        RateLimiter::for('ai-voice', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(10)->by((string) ($request->user()?->id ?: $request->ip())));

        RateLimiter::for('ai-enhance', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(5)->by((string) ($request->user()?->id ?: $request->ip())));

        RateLimiter::for('media-upload', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(60)->by((string) ($request->user()?->id ?: $request->ip())));
    }
}
