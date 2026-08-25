<?php

namespace App\Http\Controllers;

use App\Services\Transit\RouteSearchService;
use Illuminate\Http\Request;

class RouteSearchSettingsController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private RouteSearchService $routes) {}

    public function update(Request $request)
    {
        $this->routes->saveSelectedKey((string) $request->input('engine', RouteSearchService::AUTO));

        return $this->redirectWithMessage(
            '/settings?section=enhance#route-search-settings',
            __('経路検索に使う API を保存しました')
        );
    }
}
