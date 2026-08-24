<?php

namespace App\Http\Middleware;

use App\Services\TenantContractService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function __construct(private TenantContractService $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenants->applyRuntimeForUser($request->user());

        return $next($request);
    }
}
