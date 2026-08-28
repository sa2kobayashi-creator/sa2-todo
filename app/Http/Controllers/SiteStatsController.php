<?php

namespace App\Http\Controllers;

use App\Support\SiteStatEvent;
use App\Services\SiteStatsService;
use Illuminate\Http\Request;

class SiteStatsController extends Controller
{
    public function __construct(private readonly SiteStatsService $stats) {}

    /** TOP の CTA クリック用（個人情報なし・許可イベントのみ）。 */
    public function hit(Request $request)
    {
        if ($this->stats->shouldSkipRequest($request)) {
            return response()->noContent();
        }

        $event = (string) $request->input('event', '');
        if (! in_array($event, SiteStatEvent::allowedClientEvents(), true)) {
            return response()->json(['ok' => false], 422);
        }

        $this->stats->increment($event);

        return response()->json(['ok' => true]);
    }
}
