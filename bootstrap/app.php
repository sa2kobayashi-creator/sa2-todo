<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 既定は共有ホスティング向けに全許可のまま。前段のIPが分かる環境では
        // TRUSTED_PROXIES に列挙して X-Forwarded-For の詐称を防ぐ。
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', '*'));
        $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : array_values(array_filter(
            array_map('trim', explode(',', $trustedProxies))
        )));
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\SetTenantContext::class,
            \App\Http\Middleware\EnsurePasswordChanged::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'webhooks/line',
            'webhooks/messenger',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 419 のまま素っ気ないエラー画面を出さず、元の画面へ理由付きで戻す。
        // TokenMismatchException は Laravel が先に 419 の HttpException へ変換するため、そちらを受ける。
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $status = $e->getStatusCode();
            if ($status !== 419 && $status !== 429) {
                return null;
            }

            $message = $status === 419
                ? __('セッションの有効期限が切れました。お手数ですが、もう一度お試しください。')
                : __('操作が短時間に集中しました。しばらく待ってからもう一度お試しください。');

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], $status);
            }

            $referer = (string) $request->headers->get('referer', '');
            $target = str_starts_with($referer, $request->getSchemeAndHttpHost())
                ? $referer
                : url('/');

            // メール接続テストの 429 は設定タブへ戻す
            if ($status === 429 && str_contains($request->path(), 'mail/accounts') && str_ends_with($request->path(), '/test')) {
                $accountId = (int) $request->route('id');
                $target = url('/mail?tab=accounts'.($accountId > 0 ? '&account='.$accountId : ''));
            }

            $separator = str_contains($target, '?') ? '&' : '?';

            return redirect($target.$separator.'error='.urlencode($message))
                ->withInput($request->except(['password', 'password_confirmation', '_token']));
        });
    })->create();
