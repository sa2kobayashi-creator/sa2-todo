<?php

namespace Tests\Unit;

use App\Support\LocaleFormat;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_and_datetime_follow_locale(): void
    {
        $at = Carbon::parse('2026-08-13 15:04:00', 'Asia/Tokyo');

        app()->setLocale('ja');
        $this->assertSame('2026年8月13日', LocaleFormat::date($at));
        $this->assertSame('2026年8月13日 15:04', LocaleFormat::dateTime($at));

        app()->setLocale('en');
        $this->assertSame('Aug 13, 2026', LocaleFormat::date($at));
        $this->assertSame('Aug 13, 2026 3:04 PM', LocaleFormat::dateTime($at));
    }

    public function test_money_formats_by_currency_and_locale(): void
    {
        app()->setLocale('ja');
        $jpy = LocaleFormat::money(1234, 'JPY');
        $php = LocaleFormat::money(1234.5, 'PHP');
        $this->assertTrue(str_contains($jpy, '1,234') || str_contains($jpy, '1234'));
        $this->assertTrue(str_contains($jpy, '¥') || str_contains($jpy, '￥') || str_contains($jpy, 'JPY'));
        $this->assertTrue(str_contains($php, '1,234.50') || str_contains($php, '1234.5'));

        app()->setLocale('en');
        $usd = LocaleFormat::money(12.5, 'USD');
        $this->assertTrue(str_contains($usd, '12.50') || str_contains($usd, '12.5'));
        $this->assertTrue(str_contains($usd, '$') || str_contains($usd, 'USD'));
    }

    public function test_timezone_falls_back_to_app_config(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $this->assertSame('Asia/Tokyo', LocaleFormat::timezone());
    }
}
