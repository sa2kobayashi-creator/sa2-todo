<?php

namespace App\Providers;

use App\Models\User;
use App\Services\LegalConfigService;
use App\Services\MediaStorageConfigService;
use App\Services\StripeConfigService;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(MediaStorageConfigService::class);

        // Cashier 既定の /stripe/webhook は使わない。署名検証と冪等化を自前で持つ
        // StripeWebhookController（/webhooks/stripe）に一本化する
        Cashier::ignoreRoutes();
        Cashier::useCustomerModel(User::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        try {
            app(MediaStorageConfigService::class)->applyRuntimeDisks();
            app(LegalConfigService::class)->applyRuntime();
            app(StripeConfigService::class)->applyRuntime();
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

        // Stripe の画面へ飛ばすだけだが、連打で Checkout セッションを量産させない
        RateLimiter::for('billing', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(10)->by('billing|'.($request->user()?->id ?: $request->ip())));

        // 署名不一致でも受信コストは掛かるので、送信元 IP 単位で頭を押さえる
        RateLimiter::for('webhook', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perMinute(120)->by('webhook|'.$request->ip()));

        RateLimiter::for('contact-inquiry', fn (Request $request) => $unlimited
            ? Limit::none()
            : Limit::perHour(5)->by('contact-inquiry|'.($request->user()?->id ?: $request->ip())));
    }
}
