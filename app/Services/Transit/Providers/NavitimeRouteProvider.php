<?php

namespace App\Services\Transit\Providers;

use App\Services\Transit\Contracts\RouteProvider;
use App\Services\Transit\NavitimeRouteService;

class NavitimeRouteProvider implements RouteProvider
{
    public function __construct(private NavitimeRouteService $navitime) {}

    public function key(): string
    {
        return 'navitime';
    }

    public function label(): string
    {
        return 'NAVITIME';
    }

    public function isReady(): bool
    {
        return $this->navitime->isReady();
    }

    public function search(array $query): array
    {
        return $this->navitime->search([
            'from' => (string) ($query['from'] ?? ''),
            'to' => (string) ($query['to'] ?? ''),
            'departureAt' => (string) ($query['departureAt'] ?? ''),
            'timeType' => ($query['timeType'] ?? 'departure') === 'arrival' ? 'arrival' : 'departure',
            'preference' => (string) ($query['preference'] ?? ''),
            'limit' => (int) ($query['limit'] ?? 5),
        ]);
    }
}
