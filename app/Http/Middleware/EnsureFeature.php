<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect('/login');
        }

        if (! $user->canAccess($feature)) {
            abort(403, __('このページへのアクセス権限がありません。'));
        }

        return $next($request);
    }
}
