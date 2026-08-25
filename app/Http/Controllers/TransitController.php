<?php

namespace App\Http\Controllers;

use App\Services\MapService;
use App\Services\Transit\Raptor\ItineraryScorer;
use App\Services\Transit\RouteSearchService;
use App\Services\TransitService;
use App\Services\WorkersAiGuideService;
use Illuminate\Http\Request;

class TransitController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(
        private TransitService $transit,
        private MapService $maps,
        private WorkersAiGuideService $guide,
        private RouteSearchService $routes,
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
            'categoryLabels' => collect(TransitService::CATEGORY_LABELS)->map(fn (string $label) => __($label))->all(),
            'categoryIcons' => TransitService::CATEGORY_ICONS,
            'tabLabels' => collect(TransitService::TAB_LABELS)->map(fn (string $label) => __($label))->all(),
            'tabIcons' => TransitService::TAB_ICONS,
            'externalSearch' => collect(TransitService::EXTERNAL_SEARCH)->map(fn (array $item) => [
                'label' => __($item['label']),
                'url' => $item['url'],
            ])->all(),
            'preferenceLabels' => [
                ItineraryScorer::PREF_FASTEST => __('最速'),
                ItineraryScorer::PREF_CHEAPEST => __('最安'),
                ItineraryScorer::PREF_FEWEST_TRANSFERS => __('乗換少ない'),
            ],
            'returnTo' => $returnTo,
            'googleMapsApiKey' => $this->maps->getApiKey(),
            'hasGoogleMapsApiKey' => $this->maps->hasApiKey(),
            'buildTransitQuery' => fn (array $f) => $this->transit->buildTransitQuery($f),
            'datetimeUnitLabels' => app()->getLocale() === 'en'
                ? ['year' => '', 'month' => '', 'day' => '', 'hour' => '', 'minute' => '']
                : ['year' => '年', 'month' => '月', 'day' => '日', 'hour' => '時', 'minute' => '分'],
            'datetimeAriaLabels' => [
                'year' => __('年'),
                'month' => __('月'),
                'day' => __('日付'),
                'hour' => __('時'),
                'minute' => __('分'),
            ],
            'routeEngineLabel' => $this->routes->activeProvider()?->label(),
            'routeEngineIsExternal' => $this->routes->activeProvider()?->key() !== 'raptor',
            'aiTopic' => $this->guide->embeddedTopics()[WorkersAiGuideService::TOPIC_TRANSIT],
            'aiReady' => $this->guide->isReady(),
            ...$this->flashFromQuery($request),
        ]);
    }

    public function search(Request $request)
    {
        try {
            $result = $this->routes->search([
                'from' => (string) $request->input('from', ''),
                'to' => (string) $request->input('to', ''),
                'departureAt' => (string) $request->input('departureAt', ''),
                'timeType' => $request->input('timeType') === 'arrival' ? 'arrival' : 'departure',
                'preference' => (string) $request->input('preference', ItineraryScorer::PREF_FASTEST),
                'preferNishitetsuBus' => $request->boolean('preferNishitetsuBus', true),
                'minTransferMin' => (int) $request->input('minTransferMin', 2),
                'maxTransferWaitMin' => (int) $request->input('maxTransferWaitMin', 10),
                'hour' => $request->input('hour'),
                'minute' => $request->input('minute'),
                'limit' => 5,
                'engine' => (string) $request->input('engine', ''),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => __('経路検索に失敗しました: :message', ['message' => $e->getMessage()]),
                'itineraries' => [],
            ], 500);
        }

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
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

        return $this->redirectWithMessage($returnTo, __('よく使う路線を登録しました'));
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
            return $this->redirectWithMessage($returnTo, __('路線が見つかりません'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('路線を更新しました'));
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'), '/transit');
        if (! $this->transit->deleteFavorite((int) $request->user()->id, $id)) {
            return $this->redirectWithMessage($returnTo, __('路線が見つかりません'), 'error');
        }

        return $this->redirectWithMessage($returnTo, __('路線を削除しました'));
    }
}
