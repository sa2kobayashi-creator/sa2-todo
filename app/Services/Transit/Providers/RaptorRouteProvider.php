<?php

namespace App\Services\Transit\Providers;

use App\Services\Transit\Contracts\RouteProvider;
use App\Services\Transit\Raptor\ItineraryScorer;
use App\Services\Transit\Raptor\RaptorRouter;
use App\Services\Transit\Raptor\TransitTimetable;

/**
 * アプリ内蔵の経路探索（福岡都心の簡易ダイヤ）。外部契約なしで必ず使えるので、
 * ほかのプロバイダが落ちたときの最後の受け皿にもなる。
 */
class RaptorRouteProvider implements RouteProvider
{
    public function key(): string
    {
        return 'raptor';
    }

    public function label(): string
    {
        return 'RAPTOR';
    }

    public function isReady(): bool
    {
        return true;
    }

    public function search(array $query): array
    {
        $router = new RaptorRouter(TransitTimetable::loadDefault());

        return $router->search([
            'from' => (string) ($query['from'] ?? ''),
            'to' => (string) ($query['to'] ?? ''),
            'departureSec' => $this->departureSec($query),
            'preference' => (string) ($query['preference'] ?? ItineraryScorer::PREF_FASTEST),
            'minTransferMin' => (int) ($query['minTransferMin'] ?? 2),
            'maxTransferWaitMin' => (int) ($query['maxTransferWaitMin'] ?? 10),
            'preferNishitetsuBus' => (bool) ($query['preferNishitetsuBus'] ?? true),
            'limit' => (int) ($query['limit'] ?? 5),
        ]);
    }

    /** @param array<string, mixed> $query */
    private function departureSec(array $query): int
    {
        $raw = trim((string) ($query['departureAt'] ?? ''));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $raw, $m)) {
            return ((int) $m[4]) * 3600 + ((int) $m[5]) * 60;
        }

        $hour = (int) ($query['hour'] ?? date('G'));
        $minute = (int) ($query['minute'] ?? (int) date('i'));

        return max(0, min(24 * 3600 - 60, $hour * 3600 + $minute * 60));
    }
}
