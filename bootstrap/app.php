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
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 419 のまま素っ気ないエラー画面を出さず、元の画面へ理由付きで戻す。
        // TokenMismatchException は Laravel が先に 419 の HttpException へ変換するため、そちらを受ける。
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            $message = __('セッションの有効期限が切れました。お手数ですが、もう一度お試しください。');

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 419);
            }

            $referer = (string) $request->headers->get('referer', '');
            $target = str_starts_with($referer, $request->getSchemeAndHttpHost())
                ? $referer
                : url('/');

            $separator = str_contains($target, '?') ? '&' : '?';

            return redirect($target.$separator.'error='.urlencode($message))
                ->withInput($request->except(['password', 'password_confirmation', '_token']));
        });
    })->create();
