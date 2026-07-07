<?php

namespace App\Http\Controllers;

use App\Services\MapService;
use App\Services\TransitService;
use Illuminate\Http\Request;

class TransitController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private TransitService $transit,
        private MapService $maps,
    ) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $filters = $this->transit->parseFilters($request->query());
        $isAll = $filters['category'] === TransitService::ALL_CATEGORY;
        $favorites = $this->transit->listFavorites($userId, $isAll ? null : $filters['category']);
        $returnTo = $this->transit->buildTransitQuery($filters);

        return view('transit.index', [
            'filters' => $filters,
            'isAll' => $isAll,
            'favorites' => $favorites,
            'groupedFavorites' => $this->transit->groupFavoritesByCategory($userId),
            'categoryLabels' => TransitService::CATEGORY_LABELS,
            'categoryIcons' => TransitService::CATEGORY_ICONS,
            'tabLabels' => TransitService::TAB_LABELS,
            'tabIcons' => TransitService::TAB_ICONS,
            'externalSearch' => TransitService::EXTERNAL_SEARCH,
            'returnTo' => $returnTo,
            'googleMapsApiKey' => $this->maps->getApiKey(),
            'hasGoogleMapsApiKey' => $this->maps->hasApiKey(),
            'buildTransitQuery' => fn (array $f) => $this->transit->buildTransitQuery($f),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function store(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/transit');

        try {
            $this->transit->createFavorite((int) $request->user()->id, [
                'category' => $request->input('category'),
                'name' => $request->input('name'),
                'fromPlace' => $request->input('fromPlace'),
                'toPlace' => $request->input('toPlace'),
                'lineName' => $request->input('lineName'),
                'notes' => $request->input('notes'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        return $this->redirectWithMessage($returnTo, 'よく使う路線を登録しました');
    }

    public function update(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/transit');

        try {
            $updated = $this->transit->updateFavorite((int) $request->user()->id, $id, [
                'category' => $request->input('category'),
                'name' => $request->input('name'),
                'fromPlace' => $request->input('fromPlace'),
                'toPlace' => $request->input('toPlace'),
                'lineName' => $request->input('lineName'),
                'notes' => $request->input('notes'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithMessage($returnTo, $e->getMessage(), 'error');
        }

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, '路線が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, '路線を更新しました');
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/transit');
        if (! $this->transit->deleteFavorite((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, '路線が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, '路線を削除しました');
    }
}
