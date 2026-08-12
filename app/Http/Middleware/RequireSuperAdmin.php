<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect('/login');
        }
        if (! $user->isSuperAdmin()) {
            abort(403, __('この機能はスーパー管理者のみ利用できます。'));
        }

        return $next($request);
    }
}
