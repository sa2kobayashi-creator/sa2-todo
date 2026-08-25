<?php

namespace App\Services\Transit\Providers;

use App\Services\Transit\Contracts\RouteProvider;
use App\Services\Transit\EkispertRouteService;

class EkispertRouteProvider implements RouteProvider
{
    public function __construct(private EkispertRouteService $ekispert) {}

    public function key(): string
    {
        return 'ekispert';
    }

    public function label(): string
    {
        return '駅すぱあと';
    }

    public function isReady(): bool
    {
        return $this->ekispert->isReady();
    }

    public function search(array $query): array
    {
        return $this->ekispert->search($query);
    }
}
