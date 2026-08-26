<?php

namespace App\Services\Transit\Raptor;

class ItineraryScorer
{
    public const PREF_FASTEST = 'fastest';

    public const PREF_CHEAPEST = 'cheapest';

    public const PREF_FEWEST_TRANSFERS = 'fewest_transfers';

    /** 乗換1回あたりの時間換算ペナルティ（秒） */
    public const TRANSFER_PENALTY_SECONDS = 8 * 60;

    /** 選んだ交通機関を使う経路へのスコアボーナス（秒換算で減点＝優先） */
    public const PREFERRED_OPERATOR_BONUS_SECONDS = 6 * 60;

    /** @deprecated 互換のため残す */
    public const NISHITETSU_BUS_BONUS_SECONDS = self::PREFERRED_OPERATOR_BONUS_SECONDS;

    /**
     * @param list<array<string, mixed>> $itineraries
     * @return list<array<string, mixed>>
     */
    public function rank(array $itineraries, string $preference, bool $preferPreferredOperator = false): array
    {
        $preference = $this->normalizePreference($preference);
        foreach ($itineraries as &$it) {
            $it['score'] = $this->score($it, $preference, $preferPreferredOperator);
            $it['preference'] = $preference;
        }
        unset($it);

        usort($itineraries, function (array $a, array $b) {
            return ($a['score'] <=> $b['score'])
                ?: ($a['durationSec'] <=> $b['durationSec'])
                ?: ($a['transfers'] <=> $b['transfers']);
        });

        foreach ($itineraries as $i => &$it) {
            $it['rank'] = $i + 1;
        }
        unset($it);

        return $itineraries;
    }

    /** @param array<string, mixed> $itinerary */
    public function score(array $itinerary, string $preference, bool $preferPreferredOperator = false): float
    {
        $preference = $this->normalizePreference($preference);
        $duration = (float) ($itinerary['durationSec'] ?? 0);
        $wait = (float) ($itinerary['waitSec'] ?? 0);
        $transfers = (int) ($itinerary['transfers'] ?? 0);
        $fare = (float) ($itinerary['fare'] ?? 0);
        $transferPenalty = $transfers * self::TRANSFER_PENALTY_SECONDS;

        $bonus = 0.0;
        if ($preferPreferredOperator && ! empty($itinerary['usesPreferredOperator'])) {
            $bonus = self::PREFERRED_OPERATOR_BONUS_SECONDS;
        }

        return match ($preference) {
            self::PREF_CHEAPEST => ($fare * 20) + ($duration * 0.15) + ($transferPenalty * 0.5) - $bonus,
            self::PREF_FEWEST_TRANSFERS => ($transfers * 30 * 60) + $duration + ($wait * 0.5) - $bonus,
            default => $duration + ($wait * 0.35) + $transferPenalty - $bonus,
        };
    }

    public function normalizePreference(?string $preference): string
    {
        return in_array($preference, [
            self::PREF_FASTEST,
            self::PREF_CHEAPEST,
            self::PREF_FEWEST_TRANSFERS,
        ], true) ? $preference : self::PREF_FASTEST;
    }
}
