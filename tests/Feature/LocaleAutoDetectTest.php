<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocaleAutoDetectTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_browser_gets_english_on_first_visit(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
            ->get('/login');

        $response->assertOk();
        $this->assertStringContainsString('<html lang="en"', $response->getContent());
        $response->assertCookie('locale', 'en');
        $this->assertSame('en', session('locale'));
    }

    public function test_japanese_browser_stays_japanese_on_first_visit(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'ja,en;q=0.8'])
            ->get('/login');

        $response->assertOk();
        $this->assertStringContainsString('<html lang="ja"', $response->getContent());
        $response->assertCookie('locale', 'ja');
    }

    public function test_unsupported_browser_language_falls_back_to_japanese(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'fr-FR,de;q=0.8'])
            ->get('/login');

        $response->assertOk();
        $this->assertStringContainsString('<html lang="ja"', $response->getContent());
    }

    public function test_cookie_choice_wins_over_browser_language(): void
    {
        $response = $this->withCookie('locale', 'ja')
            ->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
            ->get('/login');

        $response->assertOk();
        $this->assertStringContainsString('<html lang="ja"', $response->getContent());
    }

    public function test_session_choice_wins_over_browser_language(): void
    {
        $user = User::create([
            'email' => 'locale-session@example.com',
            'display_name' => 'Locale Session',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['locale' => 'en'])
            ->withHeaders(['Accept-Language' => 'ja-JP,ja;q=0.9'])
            ->get('/dashboard');

        $response->assertOk();
        $this->assertStringContainsString('<html lang="en"', $response->getContent());
    }

    public function test_detects_accept_language_order(): void
    {
        $englishFirst = Request::create('/', 'GET', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9,ja;q=0.8',
        ]);
        $this->assertSame('en', SetLocale::detectFromAcceptLanguage($englishFirst));

        $japaneseFirst = Request::create('/', 'GET', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'ja-JP,ja;q=0.9,en;q=0.8',
        ]);
        $this->assertSame('ja', SetLocale::detectFromAcceptLanguage($japaneseFirst));

        $otherOnly = Request::create('/', 'GET', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'ko-KR,zh;q=0.8',
        ]);
        $this->assertSame('ja', SetLocale::detectFromAcceptLanguage($otherOnly));
    }
}
