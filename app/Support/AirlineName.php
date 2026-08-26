<?php

namespace App\Support;

class AirlineName
{
    /** @var array<string, string> */
    private const NAMES = [
        'NH' => 'ANA',
        'NQ' => 'ANA',
        'JL' => 'JAL',
        'GK' => 'Jetstar',
        'MM' => 'Peach',
        'BC' => 'Skymark',
        '7G' => 'Starflyer',
        '6J' => 'Solaseed',
        'NU' => 'JTA',
        'HD' => 'Air Do',
        'FW' => 'Ibex',
        '5J' => 'Cebu Pacific',
        'PR' => 'Philippine Airlines',
        'Z2' => 'AirAsia Philippines',
        'TW' => 'T\'way',
        'LJ' => 'Jin Air',
        'KE' => 'Korean Air',
        'OZ' => 'Asiana',
        'VJ' => 'Vietjet',
        'VN' => 'Vietnam Airlines',
        'AK' => 'AirAsia',
        'D7' => 'AirAsia X',
        'SQ' => 'Singapore Airlines',
        'TR' => 'Scoot',
        'CX' => 'Cathay Pacific',
        'CI' => 'China Airlines',
        'BR' => 'EVA Air',
        'TG' => 'Thai Airways',
        'FD' => 'Thai AirAsia',
        'HO' => 'Juneyao',
        'MU' => 'China Eastern',
        'CA' => 'Air China',
        'CZ' => 'China Southern',
        'UA' => 'United',
        'DL' => 'Delta',
        'AA' => 'American',
        'BA' => 'British Airways',
        'AF' => 'Air France',
        'KL' => 'KLM',
        'LH' => 'Lufthansa',
        'EK' => 'Emirates',
        'QR' => 'Qatar Airways',
        'QF' => 'Qantas',
    ];

    public static function label(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }
        $name = self::NAMES[$code] ?? '';

        return $name !== '' ? $name.' ('.$code.')' : $code;
    }

    public static function name(string $code): string
    {
        $code = strtoupper(trim($code));

        return self::NAMES[$code] ?? $code;
    }
}
