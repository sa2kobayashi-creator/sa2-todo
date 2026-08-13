<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use NumberFormatter;

/**
 * 表示用の日付・金額フォーマット（UIロケール連動）。
 * 将来の英語圏公開に備え、呼び出し側はここ経由に寄せる。
 */
class LocaleFormat
{
    /** @var list<string> */
    public const TIMEZONES = [
        'Asia/Tokyo',
        'Asia/Manila',
        'Asia/Singapore',
        'Asia/Shanghai',
        'Asia/Seoul',
        'UTC',
        'America/Los_Angeles',
        'America/Denver',
        'America/Chicago',
        'America/New_York',
        'America/Sao_Paulo',
        'Europe/London',
        'Europe/Paris',
        'Europe/Berlin',
        'Australia/Sydney',
        'Pacific/Auckland',
    ];

    public static function locale(): string
    {
        return app()->getLocale() === 'en' ? 'en' : 'ja';
    }

    public static function isEnglish(): bool
    {
        return self::locale() === 'en';
    }

    public static function timezone(?User $user = null): string
    {
        $user ??= Auth::user();
        if ($user instanceof User) {
            $tz = trim((string) ($user->timezone ?? ''));
            if ($tz !== '' && in_array($tz, self::TIMEZONES, true)) {
                return $tz;
            }
        }

        $fallback = (string) config('app.timezone', 'Asia/Tokyo');

        return in_array($fallback, self::TIMEZONES, true) ? $fallback : 'Asia/Tokyo';
    }

    public static function carbon(CarbonInterface|string|null $value, ?User $user = null): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $tz = self::timezone($user);

        try {
            if ($value instanceof CarbonInterface) {
                return Carbon::instance($value)->timezone($tz);
            }

            return Carbon::parse((string) $value, $tz)->timezone($tz);
        } catch (\Throwable) {
            return null;
        }
    }

    /** 例: ja `2026年8月13日` / en `Aug 13, 2026` */
    public static function date(CarbonInterface|string|null $value, ?User $user = null): string
    {
        $carbon = self::carbon($value, $user);
        if (! $carbon) {
            return is_string($value) ? $value : '';
        }

        return self::isEnglish()
            ? $carbon->locale('en')->isoFormat('MMM D, YYYY')
            : $carbon->format('Y年n月j日');
    }

    /** 例: ja `8月13日` / en `Aug 13` */
    public static function dateShort(CarbonInterface|string|null $value, ?User $user = null): string
    {
        $carbon = self::carbon($value, $user);
        if (! $carbon) {
            return is_string($value) ? $value : '';
        }

        return self::isEnglish()
            ? $carbon->locale('en')->isoFormat('MMM D')
            : $carbon->format('n月j日');
    }

    /** 例: ja `2026年8月13日 13:04` / en `Aug 13, 2026 1:04 PM` */
    public static function dateTime(CarbonInterface|string|null $value, ?User $user = null): string
    {
        $carbon = self::carbon($value, $user);
        if (! $carbon) {
            return is_string($value) ? $value : '';
        }

        return self::isEnglish()
            ? $carbon->locale('en')->isoFormat('MMM D, YYYY h:mm A')
            : $carbon->format('Y年n月j日 H:i');
    }

    /** 機械可読・ソート用。表示には使わない。 */
    public static function isoDate(CarbonInterface|string|null $value, ?User $user = null): string
    {
        $carbon = self::carbon($value, $user);

        return $carbon?->format('Y-m-d') ?? '';
    }

    /** 機械可読。表示には dateTime() を使う。 */
    public static function isoDateTime(CarbonInterface|string|null $value, ?User $user = null): string
    {
        $carbon = self::carbon($value, $user);

        return $carbon?->format('Y-m-d H:i') ?? '';
    }

    /**
     * 通貨コード基準 + UIロケール。
     * JPY は小数なし、PHP/USD などは小数第2位。
     */
    public static function money(float|int|string|null $amount, string $currency = 'JPY'): string
    {
        $currency = strtoupper(trim($currency)) ?: 'JPY';
        $value = round((float) $amount, self::moneyDecimals($currency));

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter(
                self::isEnglish() ? 'en_US' : 'ja_JP',
                NumberFormatter::CURRENCY
            );
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, self::moneyDecimals($currency));
            $formatted = $formatter->formatCurrency($value, $currency);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return self::moneyFallback($value, $currency);
    }

    public static function moneyDecimals(string $currency): int
    {
        return match (strtoupper($currency)) {
            'JPY', 'KRW' => 0,
            default => 2,
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function timezoneOptions(): array
    {
        $out = [];
        foreach (self::TIMEZONES as $tz) {
            $out[] = [
                'value' => $tz,
                'label' => str_replace('_', ' ', $tz),
            ];
        }

        return $out;
    }

    private static function moneyFallback(float $amount, string $currency): string
    {
        $decimals = self::moneyDecimals($currency);
        $number = number_format($amount, $decimals, '.', ',');

        return match ($currency) {
            'JPY' => '¥'.$number,
            'PHP' => '₱'.$number,
            'USD' => '$'.$number,
            'EUR' => '€'.$number,
            'GBP' => '£'.$number,
            default => $currency.' '.$number,
        };
    }
}
