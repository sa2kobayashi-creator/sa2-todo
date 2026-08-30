<?php

namespace App\Http\Controllers;

use App\Exceptions\UsageLimitExceededException;
use App\Services\MapService;
use App\Services\UserUsageLimitService;
use Illuminate\Http\Request;

class MapController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private MapService $maps) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $routes = $this->maps->listRoutes($userId);
        $selectedId = (int) $request->query('route', 0);
        $flash = $this->flashFromQuery($request);
        $hasGoogleMapsApiKey = $this->maps->hasApiKey();
        $error = $flash['error'] ?? null;

        if ($hasGoogleMapsApiKey) {
            try {
                app(UserUsageLimitService::class)->consume(
                    $request->user(),
                    UserUsageLimitService::FEATURE_MAPS,
                    1
                );
            } catch (UsageLimitExceededException $e) {
                $hasGoogleMapsApiKey = false;
                $error = $e->getMessage();
            }
        }

        return view('map.index', [
            'routes' => $routes,
            'selectedRoute' => collect($routes)->firstWhere('id', $selectedId > 0 ? $selectedId : null),
            'googleMapsApiKey' => $this->maps->getApiKey(),
            'hasGoogleMapsApiKey' => $hasGoogleMapsApiKey,
            'defaultCenter' => MapService::DEFAULT_CENTER,
            'travelModeLabels' => collect(MapService::TRAVEL_MODE_LABELS)
                ->map(fn (string $label) => __($label))
                ->all(),
            'returnTo' => '/map'.($selectedId > 0 ? '?route='.$selectedId : ''),
            'hasGoogleRoutes' => $this->maps->routesReady(),
            'mapStrings' => [
                'needPlaces' => __('出発地と目的地を入力してください'),
                'routeFailed' => __('ルートを取得できませんでした。'),
                'loading' => __('経路を取得中...'),
                'openInGoogleMaps' => __('Google Maps で開く'),
                'directionsDenied' => __('Google のルート API がこのキーでは使えません。設定 → API設定を確認してください。'),
            ],
            ...$flash,
            'error' => $error,
        ]);
    }

    public function store(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/map');

        try {
            $route = $this->maps->createRoute((int) $request->user()->id, [
                'name' => $request->input('name'),
                'originLabel' => $request->input('originLabel'),
                'originLat' => $request->input('originLat'),
                'originLng' => $request->input('originLng'),
                'destinationLabel' => $request->input('destinationLabel'),
                'destinationLat' => $request->input('destinationLat'),
                'destinationLng' => $request->input('destinationLng'),
                'travelMode' => $request->input('travelMode'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage('/map?route='.$route->id, __('ルートを保存しました'));
    }

    public function update(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/map');

        try {
            $updated = $this->maps->updateRoute((int) $request->user()->id, $id, [
                'name' => $request->input('name'),
                'originLabel' => $request->input('originLabel'),
                'originLat' => $request->input('originLat'),
                'originLng' => $request->input('originLng'),
                'destinationLabel' => $request->input('destinationLabel'),
                'destinationLat' => $request->input('destinationLat'),
                'destinationLng' => $request->input('destinationLng'),
                'travelMode' => $request->input('travelMode'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, __('ルートが見つかりません'), 'error');
        }

        return $this->redirectWithMessage('/map?route='.$id, __('ルートを更新しました'));
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/map');
        if (! $this->maps->deleteRoute((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, __('ルートが見つかりません'), 'error');
        }

        return $this->redirectWithMessage('/map', __('ルートを削除しました'));
    }

    public function directions(Request $request)
    {
        $result = $this->maps->computeDirections(
            (string) $request->input('originLabel', ''),
            $request->input('originLat'),
            $request->input('originLng'),
            (string) $request->input('destinationLabel', ''),
            $request->input('destinationLat'),
            $request->input('destinationLng'),
            $request->input('travelMode'),
        );

        $status = 200;
        if (! ($result['ok'] ?? false) && empty($result['fallback'])) {
            $status = 422;
        }

        return response()->json($result, $status);
    }
}
