<?php

namespace App\Services\Transit\Providers;

use App\Services\Transit\Contracts\RouteProvider;
use App\Services\Transit\GoogleRoutesRouteService;

class GoogleRoutesRouteProvider implements RouteProvider
{
    public function __construct(private GoogleRoutesRouteService $routes) {}

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Maps Routes';
    }

    public function isReady(): bool
    {
        return $this->routes->isReady();
    }

    public function search(array $query): array
    {
        return $this->routes->search($query);
    }
}
