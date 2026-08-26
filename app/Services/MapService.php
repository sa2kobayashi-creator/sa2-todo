<?php

namespace App\Services;

use App\Models\MapRoute;
use App\Services\Transit\TransitItinerary;

class MapService
{
    public const TRAVEL_MODE_LABELS = [
        'transit' => '公共交通',
        'driving' => '車',
        'walking' => '徒歩',
        'bicycling' => '自転車',
    ];

    private const GOOGLE_TRAVEL_MODES = [
        'transit' => 'TRANSIT',
        'driving' => 'DRIVE',
        'walking' => 'WALK',
        'bicycling' => 'BICYCLE',
    ];

    private const DIRECTIONS_FIELD_MASK = 'routes.duration,routes.distanceMeters,routes.polyline.encodedPolyline,routes.localizedValues,routes.travelAdvisory.transitFare,routes.legs.duration,routes.legs.distanceMeters,routes.legs.steps.travelMode,routes.legs.steps.staticDuration,routes.legs.steps.polyline.encodedPolyline,routes.legs.steps.navigationInstruction.instructions,routes.legs.steps.transitDetails';

    public const DEFAULT_CENTER = [
        'lat' => 33.5904,
        'lng' => 130.4017,
        'label' => '福岡（天神付近）',
    ];

    public function __construct(
        private GoogleMapsConfigService $googleMaps,
        private GoogleRoutesConfigService $googleRoutes,
        private IntegrationUsageService $usage,
    ) {}

    public function routesReady(): bool
    {
        return $this->googleRoutes->isReady();
    }

    public function normalizeTravelMode(?string $mode): string
    {
        return array_key_exists($mode, self::TRAVEL_MODE_LABELS) ? $mode : 'transit';
    }

    public function getApiKey(): ?string
    {
        $key = $this->googleMaps->apiKey();

        return $key !== '' ? $key : null;
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

    /**
     * Map のルート線。Directions API ではなく、設定済みの Routes API を使う。
     *
     * @return array{
     *     ok: bool,
     *     fallback: bool,
     *     message: string,
     *     summary: string,
     *     steps: list<array{text: string, mode: string}>,
     *     polylines: list<array{encoded: string, mode: string}>,
     *     polyline: string
     * }
     */
    public function computeDirections(
        string $originLabel,
        mixed $originLat,
        mixed $originLng,
        string $destinationLabel,
        mixed $destinationLat,
        mixed $destinationLng,
        ?string $travelMode,
    ): array {
        $empty = [
            'ok' => false,
            'fallback' => false,
            'message' => '',
            'summary' => '',
            'steps' => [],
            'polylines' => [],
            'polyline' => '',
            'routes' => [],
        ];

        if (! $this->googleRoutes->isReady()) {
            return [...$empty, 'fallback' => true];
        }

        $originLabel = trim($originLabel);
        $destinationLabel = trim($destinationLabel);
        if ($originLabel === '' || $destinationLabel === '') {
            return [...$empty, 'message' => __('出発地と目的地を入力してください')];
        }

        $mode = $this->normalizeTravelMode($travelMode);
        $googleMode = self::GOOGLE_TRAVEL_MODES[$mode];
        $body = [
            'origin' => $this->routePlace($originLabel, $originLat, $originLng),
            'destination' => $this->routePlace($destinationLabel, $destinationLat, $destinationLng),
            'travelMode' => $googleMode,
            'computeAlternativeRoutes' => $googleMode !== 'TRANSIT',
            'languageCode' => str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'ja',
            'regionCode' => 'JP',
        ];
        if ($googleMode === 'TRANSIT') {
            $body['departureTime'] = now()->timezone('Asia/Tokyo')->format('Y-m-d\TH:i:sP');
        }

        $result = $this->googleRoutes->computeRoutes($body, self::DIRECTIONS_FIELD_MASK);
        if (! $result['ok']) {
            return [...$empty, 'message' => $result['message']];
        }

        $routes = is_array($result['data']['routes'] ?? null) ? $result['data']['routes'] : [];
        $presented = [];
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            $presented[] = $this->presentRoute($route);
        }
        if ($presented === []) {
            return [...$empty, 'message' => __('条件に合う経路が見つかりませんでした。')];
        }

        $this->usage->increment('google_routes');
        $first = $presented[0];

        return [
            'ok' => true,
            'fallback' => false,
            'message' => '',
            'summary' => $first['summary'],
            'steps' => $first['steps'],
            'polylines' => $first['polylines'],
            'polyline' => $first['polyline'],
            'routes' => $presented,
        ];
    }

    /** @return array<string, mixed> */
    private function routePlace(string $label, mixed $lat, mixed $lng): array
    {
        $latitude = $this->nullableFloat($lat);
        $longitude = $this->nullableFloat($lng);
        if ($latitude !== null && $longitude !== null) {
            return [
                'location' => [
                    'latLng' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ],
                ],
            ];
        }

        return ['address' => $label];
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array{
     *     summary: string,
     *     durationText: string,
     *     distanceText: string,
     *     fareText: string,
     *     modeSummary: string,
     *     steps: list<array{text: string, mode: string}>,
     *     polylines: list<array{encoded: string, mode: string}>,
     *     polyline: string
     * }
     */
    private function presentRoute(array $route): array
    {
        $steps = $this->routeSteps($route);

        return [
            'summary' => $this->routeSummary($route),
            'durationText' => $this->routeDurationText($route),
            'distanceText' => $this->routeDistanceText($route),
            'fareText' => $this->routeFareText($route),
            'modeSummary' => $this->modeSummary($steps),
            'steps' => $steps,
            'polylines' => $this->routePolylines($route),
            'polyline' => (string) data_get($route, 'polyline.encodedPolyline', ''),
        ];
    }

    /** @param array<string, mixed> $route */
    private function routeDurationText(array $route): string
    {
        $duration = trim((string) data_get($route, 'localizedValues.duration.text', ''));
        if ($duration !== '') {
            return $duration;
        }
        $seconds = $this->durationSeconds($route['duration'] ?? 0);
        $minutes = max(1, (int) round(max(0, $seconds) / 60));

        return $minutes.__('分');
    }

    /** @param array<string, mixed> $route */
    private function routeDistanceText(array $route): string
    {
        $distance = trim((string) data_get($route, 'localizedValues.distance.text', ''));
        if ($distance !== '') {
            return $distance;
        }
        $meters = (int) ($route['distanceMeters'] ?? 0);
        if ($meters <= 0) {
            return '';
        }

        return $meters >= 1000
            ? number_format($meters / 1000, 1).' km'
            : $meters.' m';
    }

    /** @param array<string, mixed> $route */
    private function routeFareText(array $route): string
    {
        $fare = data_get($route, 'travelAdvisory.transitFare');
        if (! is_array($fare)) {
            return '';
        }
        if (isset($fare['units']) && is_numeric($fare['units'])) {
            return '¥'.number_format((int) $fare['units']);
        }

        return trim((string) ($fare['text'] ?? ''));
    }

    /**
     * @param  list<array{text: string, mode: string}>  $steps
     */
    private function modeSummary(array $steps): string
    {
        $parts = [];
        foreach ($steps as $step) {
            $mode = strtoupper((string) ($step['mode'] ?? ''));
            $text = trim((string) ($step['text'] ?? ''));
            if ($mode === 'WALK' || $mode === 'WALKING') {
                $parts[] = __('徒歩');
                continue;
            }
            if ($text !== '') {
                $parts[] = explode(' ', $text)[0];
                continue;
            }
            $parts[] = match ($mode) {
                'BICYCLE', 'BICYCLING' => __('自転車'),
                'DRIVE', 'DRIVING' => __('車'),
                default => __('公共交通'),
            };
        }

        return implode(' → ', $parts);
    }

    /** @param array<string, mixed> $route */
    private function routeSummary(array $route): string
    {
        $distance = trim((string) data_get($route, 'localizedValues.distance.text', ''));
        $duration = trim((string) data_get($route, 'localizedValues.duration.text', ''));
        if ($distance !== '' && $duration !== '') {
            return $distance.' / '.$duration;
        }

        $meters = (int) ($route['distanceMeters'] ?? 0);
        $seconds = $this->durationSeconds($route['duration'] ?? 0);
        $distanceText = $meters >= 1000
            ? number_format($meters / 1000, 1).' km'
            : $meters.' m';
        $minutes = max(1, (int) round(max(0, $seconds) / 60));

        return $distanceText.' / '.$minutes.__('分');
    }

    /**
     * @param  array<string, mixed>  $route
     * @return list<array{text: string, mode: string}>
     */
    private function routeSteps(array $route): array
    {
        $steps = [];
        foreach (TransitItinerary::asList($route['legs'] ?? []) as $leg) {
            if (! is_array($leg)) {
                continue;
            }
            foreach (TransitItinerary::asList($leg['steps'] ?? []) as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $text = $this->stepText($step);
                if ($text === '') {
                    continue;
                }
                $steps[] = [
                    'text' => $text,
                    'mode' => strtoupper((string) ($step['travelMode'] ?? '')),
                ];
            }
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $route
     * @return list<array{encoded: string, mode: string}>
     */
    private function routePolylines(array $route): array
    {
        $lines = [];
        foreach (TransitItinerary::asList($route['legs'] ?? []) as $leg) {
            if (! is_array($leg)) {
                continue;
            }
            foreach (TransitItinerary::asList($leg['steps'] ?? []) as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $encoded = trim((string) data_get($step, 'polyline.encodedPolyline', ''));
                if ($encoded === '') {
                    continue;
                }
                $lines[] = [
                    'encoded' => $encoded,
                    'mode' => strtoupper((string) ($step['travelMode'] ?? '')),
                ];
            }
        }

        return $lines;
    }

    /** @param array<string, mixed> $step */
    private function stepText(array $step): string
    {
        $mode = strtoupper((string) ($step['travelMode'] ?? ''));
        $nav = trim((string) data_get($step, 'navigationInstruction.instructions', ''));
        if ($mode === 'TRANSIT') {
            $from = trim((string) data_get($step, 'transitDetails.stopDetails.departureStop.name', ''));
            $to = trim((string) data_get($step, 'transitDetails.stopDetails.arrivalStop.name', ''));
            $line = trim((string) data_get($step, 'transitDetails.transitLine.nameShort', ''));
            if ($line === '') {
                $line = trim((string) data_get($step, 'transitDetails.transitLine.name', ''));
            }
            if ($line !== '' && $from !== '' && $to !== '') {
                return $line.' '.$from.' → '.$to;
            }
            if ($line !== '') {
                return $line;
            }
        }

        if ($nav !== '') {
            return $nav;
        }

        return match ($mode) {
            'WALK', 'WALKING' => __('徒歩'),
            'BICYCLE', 'BICYCLING' => __('自転車'),
            'DRIVE', 'DRIVING' => __('車'),
            default => '',
        };
    }

    private function durationSeconds(mixed $duration): int
    {
        $seconds = TransitItinerary::secondsFromGoogle($duration);
        if ($seconds > 0) {
            return $seconds;
        }
        if (is_array($duration) && isset($duration['seconds']) && is_numeric($duration['seconds'])) {
            return max(0, (int) $duration['seconds']);
        }

        return 0;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 7);
    }
}
