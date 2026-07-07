<?php

namespace App\Http\Controllers;

use App\Services\MapService;
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

        return view('map.index', [
            'routes' => $routes,
            'selectedRoute' => collect($routes)->firstWhere('id', $selectedId > 0 ? $selectedId : null),
            'googleMapsApiKey' => $this->maps->getApiKey(),
            'hasGoogleMapsApiKey' => $this->maps->hasApiKey(),
            'defaultCenter' => MapService::DEFAULT_CENTER,
            'travelModeLabels' => MapService::TRAVEL_MODE_LABELS,
            'returnTo' => '/map'.($selectedId > 0 ? '?route='.$selectedId : ''),
            ...$this->flashFromQuery($request),
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

        return $this->redirectWithMessage('/map?route='.$route->id, 'ルートを保存しました');
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
            return $this->redirectWithMessage($returnTo, 'ルートが見つかりません', 'error');
        }

        return $this->redirectWithMessage('/map?route='.$id, 'ルートを更新しました');
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/map');
        if (! $this->maps->deleteRoute((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, 'ルートが見つかりません', 'error');
        }

        return $this->redirectWithMessage('/map', 'ルートを削除しました');
    }
}
