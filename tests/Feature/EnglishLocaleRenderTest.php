<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EnglishLocaleRenderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'english@example.com',
            'display_name' => 'English User',
            'password' => Hash::make('password123'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    /** @return list<string> */
    private function pages(): array
    {
        return [
            '/dashboard',
            '/todos',
            '/notes',
            '/finance',
            '/photos',
            '/transit',
            '/map',
            '/mypage',
            '/settings',
            '/settings?section=enhance',
            '/groups',
            '/video',
            '/travel',
            '/translate',
            '/guide',
            '/music',
            '/admin/users',
            '/help',
            '/help/overview',
            '/help/guide',
            '/contact',
        ];
    }

    public function test_every_page_renders_in_english(): void
    {
        $this->withSession(['locale' => 'en']);

        foreach ($this->pages() as $uri) {
            $response = $this->actingAs($this->user)->get($uri);

            $this->assertSame(200, $response->getStatusCode(), "{$uri} should render in English");
            $this->assertStringNotContainsString('ParseError', $response->getContent());
            $this->assertStringNotContainsString('syntax error', $response->getContent());
            $this->assertStringContainsString('<html lang="en"', $response->getContent(), "{$uri} should be marked as English");
        }
    }

    public function test_guest_pages_render_in_english(): void
    {
        foreach (['/login', '/register', '/password/forgot', '/password/reset'] as $uri) {
            $response = $this->withSession(['locale' => 'en'])->get($uri);

            $this->assertSame(200, $response->getStatusCode(), "{$uri} should render in English");
            $this->assertStringNotContainsString('ParseError', $response->getContent());
            $this->assertStringContainsString('<meta name="google" content="notranslate" />', $response->getContent());
        }
    }

    public function test_every_page_opts_out_of_browser_translation(): void
    {
        // 端末が英語だと Chrome が自動翻訳を始め、JS が触る DOM と衝突して画面が真っ白になる
        foreach ($this->pages() as $uri) {
            $this->actingAs($this->user)->get($uri)
                ->assertOk()
                ->assertSee('<meta name="google" content="notranslate" />', false);
        }

    }

    public function test_an_english_browser_is_not_forced_into_english(): void
    {
        // 端末の言語ではなく、アプリの言語切替だけで決まること
        $response = $this->actingAs($this->user)
            ->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
            ->get('/dashboard');

        $response->assertOk();
        $this->assertStringContainsString('<html lang="ja"', $response->getContent());
    }
}
