<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\YoutubeVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VideoPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'email' => 'video@example.com',
            'display_name' => 'Viewer',
            'password' => Hash::make('password123'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    public function test_video_page_renders_search_without_disabling_the_box(): void
    {
        $user = $this->makeUser();

        $html = $this->actingAs($user)->get('/video')->assertOk()->getContent();

        $this->assertStringContainsString('id="youtube-search-q"', $html);
        $this->assertStringContainsString('id="youtube-search-form"', $html);
        $this->assertStringContainsString('method="get"', $html);
        $this->assertDoesNotMatchRegularExpression('/id="youtube-search-q"[^>]*\sdisabled/', $html);
        $this->assertStringNotContainsString('about:blank', $html);
        $this->assertStringNotContainsString('enablejsapi', $html);
    }

    public function test_searching_a_youtube_url_works_without_data_api(): void
    {
        config(['youtube.data_api_key' => '']);
        Http::fake([
            'https://www.youtube.com/oembed*' => Http::response([
                'title' => 'Demo clip',
                'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            ]),
        ]);

        $user = $this->makeUser();
        $this->actingAs($user)
            ->getJson('/video/youtube/search?q='.urlencode('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('direct', true)
            ->assertJsonPath('items.0.youtubeId', 'dQw4w9WgXcQ')
            ->assertJsonPath('items.0.source', 'youtube');
    }

    public function test_keyword_search_without_api_explains_how_to_watch(): void
    {
        config(['youtube.data_api_key' => '']);
        $user = $this->makeUser();

        $response = $this->actingAs($user)
            ->getJson('/video/youtube/search?q='.urlencode('ジャズ ライブ'));

        $response->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('YouTube Data API', (string) $response->json('message'));
    }

    public function test_keyword_search_uses_embeddable_youtube_results(): void
    {
        config(['youtube.data_api_key' => 'test-key']);
        Http::fake([
            'https://www.googleapis.com/youtube/v3/search*' => Http::response([
                'items' => [[
                    'id' => ['videoId' => 'dQw4w9WgXcQ'],
                    'snippet' => [
                        'title' => 'Live jazz',
                        'channelTitle' => 'Demo',
                        'publishedAt' => '2024-01-01T00:00:00Z',
                        'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg']],
                    ],
                ]],
                'pageInfo' => ['totalResults' => 1],
            ]),
        ]);

        $user = $this->makeUser();
        $this->actingAs($user)
            ->getJson('/video/youtube/search?q='.urlencode('ジャズ'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items.0.youtubeId', 'dQw4w9WgXcQ');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'youtube/v3/search')
                && ($request['videoEmbeddable'] ?? null) === 'true'
                && ($request['key'] ?? null) === 'test-key';
        });
    }

    public function test_popular_videos_endpoint(): void
    {
        config(['youtube.data_api_key' => 'test-key']);
        Http::fake([
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'dQw4w9WgXcQ',
                    'status' => ['embeddable' => true],
                    'snippet' => [
                        'title' => 'Popular',
                        'channelTitle' => 'Demo',
                        'publishedAt' => '2024-01-01T00:00:00Z',
                        'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg']],
                    ],
                ]],
            ]),
        ]);

        $user = $this->makeUser();
        $this->actingAs($user)
            ->getJson('/video/youtube/popular')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items.0.youtubeId', 'dQw4w9WgXcQ');
    }

    public function test_can_save_tiktok_url_to_library(): void
    {
        Http::fake([
            'https://www.tiktok.com/oembed*' => Http::response([
                'title' => 'Saved clip',
                'thumbnail_url' => 'https://example.com/t.jpg',
            ]),
        ]);

        $user = $this->makeUser();
        $this->actingAs($user)->post('/video/youtube', [
            'youtube_url' => 'https://www.tiktok.com/@demo/video/7123456789012345678',
            'returnTo' => '/video',
        ])->assertRedirect();

        $this->assertDatabaseHas('youtube_videos', [
            'user_id' => $user->id,
            'provider' => 'tiktok',
            'youtube_id' => '7123456789012345678',
        ]);
    }

    public function test_html_search_shows_an_error_when_keyword_search_has_no_api(): void
    {
        config(['youtube.data_api_key' => '']);
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get('/video?q='.urlencode('ジャズ ライブ'))
            ->assertOk()
            ->assertSee('YouTube Data API', false);
    }

    public function test_html_search_plays_a_youtube_url_without_data_api(): void
    {
        config(['youtube.data_api_key' => '']);
        Http::fake([
            'https://www.youtube.com/oembed*' => Http::response([
                'title' => 'Demo clip',
                'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            ]),
        ]);

        $html = $this->actingAs($this->makeUser())
            ->get('/video?q='.urlencode('https://youtu.be/dQw4w9WgXcQ'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $html);
        $this->assertStringContainsString('Demo clip', $html);
        $this->assertStringContainsString('id="youtube-player"', $html);
    }

    public function test_selecting_a_library_video_renders_the_embed(): void
    {
        $html = $this->actingAs($this->makeUser())
            ->get('/video?play=dQw4w9WgXcQ&src=youtube')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $html);
        $this->assertStringContainsString('id="youtube-player"', $html);
        $this->assertStringNotContainsString('enablejsapi', $html);
        $this->assertStringNotContainsString('origin=', $html);
    }

    public function test_blocked_search_method_explains_cloud_console_fix(): void
    {
        config(['youtube.data_api_key' => 'test-key']);
        Http::fake([
            'https://www.googleapis.com/youtube/v3/search*' => Http::response([
                'error' => [
                    'message' => 'Requests to this API youtube method youtube.api.v3.V3DataSearchService.List are blocked.',
                ],
            ], 403),
        ]);

        $this->actingAs($this->makeUser())
            ->get('/video?q='.urlencode('ジャズ'))
            ->assertOk()
            ->assertSee('search.list', false);
    }

    public function test_keyword_html_search_renders_result_cards(): void
    {
        config(['youtube.data_api_key' => 'test-key']);
        Http::fake([
            'https://www.googleapis.com/youtube/v3/search*' => Http::response([
                'items' => [[
                    'id' => ['videoId' => 'dQw4w9WgXcQ'],
                    'snippet' => [
                        'title' => 'Live jazz',
                        'channelTitle' => 'Demo',
                        'publishedAt' => '2024-01-01T00:00:00Z',
                        'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg']],
                    ],
                ]],
                'pageInfo' => ['totalResults' => 1],
            ]),
        ]);

        $this->actingAs($this->makeUser())
            ->get('/video?q='.urlencode('ジャズ'))
            ->assertOk()
            ->assertSee('Live jazz', false)
            ->assertSee('dQw4w9WgXcQ', false);
    }

    public function test_keyword_html_search_shows_next_page(): void
    {
        config(['youtube.data_api_key' => 'test-key']);
        Http::fake([
            'https://www.googleapis.com/youtube/v3/search*' => Http::response([
                'items' => [[
                    'id' => ['videoId' => 'dQw4w9WgXcQ'],
                    'snippet' => [
                        'title' => 'Live jazz',
                        'channelTitle' => 'Demo',
                        'publishedAt' => '2024-01-01T00:00:00Z',
                        'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg']],
                    ],
                ]],
                'nextPageToken' => 'CAUQAA',
                'pageInfo' => ['totalResults' => 40],
            ]),
        ]);

        $html = $this->actingAs($this->makeUser())
            ->get('/video?q='.urlencode('ジャズ'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="youtube-search-pager"', $html);
        $this->assertStringContainsString('pageToken=CAUQAA', $html);
        $this->assertStringContainsString('全約40件', $html);
    }

    public function test_keyword_html_search_forwards_page_token(): void
    {
        config(['youtube.data_api_key' => 'test-key']);
        Http::fake([
            'https://www.googleapis.com/youtube/v3/search*' => Http::response([
                'items' => [[
                    'id' => ['videoId' => 'dQw4w9WgXcQ'],
                    'snippet' => [
                        'title' => 'Page two',
                        'channelTitle' => 'Demo',
                        'publishedAt' => '2024-01-01T00:00:00Z',
                        'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg']],
                    ],
                ]],
                'prevPageToken' => 'CDIQAQ',
                'pageInfo' => ['totalResults' => 40],
            ]),
        ]);

        $this->actingAs($this->makeUser())
            ->get('/video?q='.urlencode('ジャズ').'&pageToken=CAUQAA')
            ->assertOk()
            ->assertSee('Page two', false)
            ->assertSee('pageToken=CDIQAQ', false);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'youtube/v3/search')
                && ($request['pageToken'] ?? null) === 'CAUQAA';
        });
    }

    public function test_library_paginates_after_twenty_videos(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get('/video')->assertOk();
        $libraryId = \App\Models\VideoLibrary::query()->where('user_id', $user->id)->value('id');
        $this->assertNotNull($libraryId);

        for ($i = 0; $i < 21; $i++) {
            $youtubeId = sprintf('libvid%02dxxxxxx', $i);
            \App\Models\YoutubeVideo::query()->create([
                'user_id' => $user->id,
                'video_library_id' => $libraryId,
                'provider' => 'youtube',
                'youtube_id' => $youtubeId,
                'title' => 'Library clip '.$i,
                'url' => 'https://www.youtube.com/watch?v='.$youtubeId,
                'sort_order' => $i,
            ]);
        }

        $first = $this->actingAs($user)->get('/video')->assertOk()->getContent();
        $this->assertStringContainsString('id="library-pager"', $first);
        $this->assertStringContainsString('Library clip 0', $first);
        $this->assertStringNotContainsString('Library clip 20', $first);
        $this->assertStringContainsString('page=2', $first);

        $second = $this->actingAs($user)->get('/video?page=2')->assertOk()->getContent();
        $this->assertStringContainsString('Library clip 20', $second);
        $this->assertStringNotContainsString('Library clip 0', $second);
    }

    public function test_embed_helper_matches_player_hosts(): void
    {
        $svc = app(YoutubeVideoService::class);
        $this->assertStringContainsString('youtube.com', $svc->embedUrlFor('abcDEF12345'));
        $this->assertStringContainsString('tiktok.com/player/v1/', $svc->tiktokEmbedUrlFor('12345678901'));
    }
}
