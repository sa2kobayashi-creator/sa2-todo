<?php

namespace App\Support;

use Illuminate\Support\Str;

/** メールで送る6桁の確認コード */
final class VerificationCode
{
    public const LENGTH = 6;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);
    }

    /** 全角数字や空白混じりで入力されても受け取れるようにする */
    public static function normalize(string $code): string
    {
        return preg_replace('/\D/', '', Str::ascii(trim($code))) ?? '';
    }
}
