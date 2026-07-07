<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect('/login');
        }
        if (! $user->isAdmin()) {
            abort(403, 'このページは管理者のみアクセスできます。');
        }

        return $next($request);
    }
}
