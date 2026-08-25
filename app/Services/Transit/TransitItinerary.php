<?php

namespace App\Services\Transit;

/**
 * 経路検索プロバイダが共通で返す itinerary 1件を組み立てる。
 */
final class TransitItinerary
{
    /**
     * @param  list<array<string, mixed>>  $legs
     * @return array<string, mixed>|null
     */
    public static function assemble(array $legs, int $transfers, int $fare, int $waitSec = 0): ?array
    {
        if ($legs === []) {
            return null;
        }

        $firstRide = null;
        $lastRide = null;
        $names = [];
        $durationSec = 0;
        $walkSec = 0;
        $usesNishitetsuBus = false;

        foreach ($legs as $leg) {
            $durationSec += (int) ($leg['durationSec'] ?? 0);
            if (($leg['type'] ?? '') === 'walk') {
                $walkSec += (int) ($leg['durationSec'] ?? 0);

                continue;
            }
            if (($leg['type'] ?? '') !== 'ride') {
                continue;
            }
            $firstRide ??= $leg;
            $lastRide = $leg;
            if (($leg['routeName'] ?? '') !== '') {
                $names[] = (string) $leg['routeName'];
            }
            $blob = (string) ($leg['routeName'] ?? '').(string) ($leg['label'] ?? '');
            if (str_contains($blob, '西鉄')) {
                $usesNishitetsuBus = true;
            }
        }

        $names = array_values(array_unique($names));
        $departure = (string) ($firstRide['boardTime'] ?? '');
        $arrival = (string) ($lastRide['alightTime'] ?? '');
        $summary = $names !== [] ? implode(' → ', $names) : '経路';
        if ($transfers > 0) {
            $summary .= '（乗換'.$transfers.'回）';
        }

        return [
            'departureTime' => $departure,
            'arrivalTime' => $arrival,
            'durationSec' => $durationSec,
            'durationLabel' => self::durationLabel($durationSec),
            'waitSec' => $waitSec,
            'waitLabel' => self::durationLabel($waitSec),
            'walkSec' => $walkSec,
            'transfers' => max(0, $transfers),
            'fare' => $fare,
            'fareLabel' => $fare > 0 ? '¥'.number_format($fare) : __('運賃情報なし'),
            'usesNishitetsuBus' => $usesNishitetsuBus,
            'legs' => $legs,
            'summary' => $summary,
            'signature' => md5($departure.$arrival.implode('|', $names)),
        ];
    }

    public static function durationLabel(int $sec): string
    {
        $sec = max(0, $sec);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);

        return $h > 0 ? $h.'時間'.$m.'分' : $m.'分';
    }

    public static function clockFrom(string $value): string
    {
        $time = self::timestamp($value);

        return $time === null ? '' : date('H:i', $time);
    }

    public static function timestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})/', $value, $m)) {
            $time = strtotime($m[1].'-'.$m[2].'-'.$m[3].' '.$m[4].':'.$m[5].':00');

            return $time === false ? null : $time;
        }
        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    /** Google Routes の duration（"1800s"） */
    public static function secondsFromGoogle(mixed $duration): int
    {
        if (is_int($duration) || is_float($duration)) {
            return max(0, (int) $duration);
        }
        if (is_string($duration) && preg_match('/^(\d+)s$/', trim($duration), $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /** @return list<array<int|string, mixed>> */
    public static function asList(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }
}
