<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 初期パスワードのままのユーザーを、パスワード設定画面から出さない */
class EnsurePasswordChanged
{
    private const ALLOWED_PATHS = [
        'password/setup',
        'logout',
        'locale',
        'csrf-token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->is(self::ALLOWED_PATHS)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('パスワードの設定が必要です。'),
                'redirect' => '/password/setup',
            ], 403);
        }

        return redirect('/password/setup');
    }
}
