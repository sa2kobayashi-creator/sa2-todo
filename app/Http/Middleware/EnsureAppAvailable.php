<?php

namespace App\Http\Middleware;

use App\Services\MaintenanceModeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAppAvailable
{
    public function __construct(private MaintenanceModeService $maintenance) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->maintenance->allowsRequest($request)) {
            return $next($request);
        }

        $payload = [
            'ok' => false,
            'message' => $this->maintenance->message(),
        ];

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json($payload, 503);
        }

        return response()
            ->view('maintenance', [
                'message' => $this->maintenance->message(),
            ], 503);
    }
}
