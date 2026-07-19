<?php

namespace App\Services;

use App\Models\MapRoute;

class MapService
{
    public const TRAVEL_MODE_LABELS = [
        'transit' => '公共交通',
        'driving' => '車',
        'walking' => '徒歩',
        'bicycling' => '自転車',
    ];

    public const DEFAULT_CENTER = [
        'lat' => 33.5904,
        'lng' => 130.4017,
        'label' => '福岡（天神付近）',
    ];

    public function normalizeTravelMode(?string $mode): string
    {
        return array_key_exists($mode, self::TRAVEL_MODE_LABELS) ? $mode : 'transit';
    }

    public function getApiKey(): ?string
    {
        $key = config('services.google_maps.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function hasApiKey(): bool
    {
        return $this->getApiKey() !== null;
    }

    /** @return list<array<string, mixed>> */
    public function listRoutes(int $userId): array
    {
        return MapRoute::query()
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MapRoute $route) => $this->toArray($route))
            ->all();
    }

    /** @return array<string, mixed> */
    public function toArray(MapRoute $route): array
    {
        return [
            'id' => $route->id,
            'name' => $route->name,
            'originLabel' => $route->origin_label,
            'originLat' => $route->origin_lat !== null ? (float) $route->origin_lat : null,
            'originLng' => $route->origin_lng !== null ? (float) $route->origin_lng : null,
            'destinationLabel' => $route->destination_label,
            'destinationLat' => $route->destination_lat !== null ? (float) $route->destination_lat : null,
            'destinationLng' => $route->destination_lng !== null ? (float) $route->destination_lng : null,
            'travelMode' => $route->travel_mode,
            'travelModeLabel' => __(self::TRAVEL_MODE_LABELS[$route->travel_mode] ?? $route->travel_mode),
            'sortOrder' => $route->sort_order,
            'googleMapsUrl' => $this->buildGoogleMapsUrl($route),
            'googleNavUrl' => $this->buildGoogleNavUrl($route),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function createRoute(int $userId, array $payload): MapRoute
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $origin = trim((string) ($payload['originLabel'] ?? ''));
        $destination = trim((string) ($payload['destinationLabel'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('ルート名を入力してください');
        }
        if ($origin === '' || $destination === '') {
            throw new \InvalidArgumentException('出発地と目的地を入力してください');
        }

        $maxOrder = (int) MapRoute::query()->where('user_id', $userId)->max('sort_order');

        return MapRoute::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'origin_label' => $origin,
            'origin_lat' => $this->nullableFloat($payload['originLat'] ?? null),
            'origin_lng' => $this->nullableFloat($payload['originLng'] ?? null),
            'destination_label' => $destination,
            'destination_lat' => $this->nullableFloat($payload['destinationLat'] ?? null),
            'destination_lng' => $this->nullableFloat($payload['destinationLng'] ?? null),
            'travel_mode' => $this->normalizeTravelMode($payload['travelMode'] ?? null),
            'sort_order' => $maxOrder + 10,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function updateRoute(int $userId, int $id, array $payload): bool
    {
        $route = MapRoute::query()->where('user_id', $userId)->whereKey($id)->first();
        if (! $route) {
            return false;
        }

        $name = trim((string) ($payload['name'] ?? $route->name));
        $origin = trim((string) ($payload['originLabel'] ?? $route->origin_label));
        $destination = trim((string) ($payload['destinationLabel'] ?? $route->destination_label));

        if ($name === '') {
            throw new \InvalidArgumentException('ルート名を入力してください');
        }
        if ($origin === '' || $destination === '') {
            throw new \InvalidArgumentException('出発地と目的地を入力してください');
        }

        return $route->update([
            'name' => $name,
            'origin_label' => $origin,
            'origin_lat' => $this->nullableFloat($payload['originLat'] ?? $route->origin_lat),
            'origin_lng' => $this->nullableFloat($payload['originLng'] ?? $route->origin_lng),
            'destination_label' => $destination,
            'destination_lat' => $this->nullableFloat($payload['destinationLat'] ?? $route->destination_lat),
            'destination_lng' => $this->nullableFloat($payload['destinationLng'] ?? $route->destination_lng),
            'travel_mode' => $this->normalizeTravelMode($payload['travelMode'] ?? $route->travel_mode),
        ]);
    }

    public function deleteRoute(int $userId, int $id): bool
    {
        return (bool) MapRoute::query()->where('user_id', $userId)->whereKey($id)->delete();
    }

    public function buildGoogleMapsUrl(MapRoute $route): string
    {
        $params = [
            'api' => '1',
            'travelmode' => $route->travel_mode,
        ];

        if ($route->origin_lat !== null && $route->origin_lng !== null) {
            $params['origin'] = $route->origin_lat.','.$route->origin_lng;
        } else {
            $params['origin'] = $route->origin_label;
        }

        if ($route->destination_lat !== null && $route->destination_lng !== null) {
            $params['destination'] = $route->destination_lat.','.$route->destination_lng;
        } else {
            $params['destination'] = $route->destination_label;
        }

        return 'https://www.google.com/maps/dir/?'.http_build_query($params);
    }

    public function buildGoogleNavUrl(MapRoute $route): string
    {
        $url = $this->buildGoogleMapsUrl($route);

        return $url.'&dir_action=navigate';
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 7);
    }
}
