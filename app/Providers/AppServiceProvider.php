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
        $this->app->singleton(\App\Support\TenantContext::class);
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

        // IP だけで数えると、プロキシ経由の詐称や IP ローテーションで1アカウントに
        // 総当たりできてしまうため、宛先アカウント単位の上限も併用する。
        RateLimiter::for('auth-login', function (Request $request) use ($unlimited) {
            if ($unlimited) {
                return Limit::none();
            }

            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by((string) $request->ip()),
                Limit::perMinutes(60, 30)->by('auth-login-account|'.$email),
            ];
        });

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

        RateLimiter::for('ai-guide', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(10)->by((string) ($request->user()?->id ?: $request->ip())));

        RateLimiter::for('ai-enhance', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(5)->by((string) ($request->user()?->id ?: $request->ip())));

        RateLimiter::for('media-upload', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(60)->by((string) ($request->user()?->id ?: $request->ip())));

        // mail 系はユーザー共通キーを共有しない（mailbox 読み込みで接続テストが 429 になるのを防ぐ）
        RateLimiter::for('mail-test', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(30)->by('mail-test|'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('mail-mailbox', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(120)->by('mail-mailbox|'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('mail-send', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(30)->by('mail-send|'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('mail-account-write', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(30)->by('mail-account-write|'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('contact-inquiry', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perHour(5)->by('contact-inquiry|'.($request->user()?->id ?: $request->ip())));
    }
}
