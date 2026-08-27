<?php

namespace Tests\Unit;

use App\Models\MediaStorageSetting;
use App\Services\YoutubeVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YoutubeVideoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_youtube_watch_and_short_urls(): void
    {
        $svc = app(YoutubeVideoService::class);

        $this->assertSame('dQw4w9WgXcQ', $svc->extractVideoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', $svc->extractVideoId('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', $svc->extractVideoId('https://www.youtube.com/shorts/dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', $svc->extractVideoId('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'));
    }

    public function test_extracts_tiktok_video_id_from_canonical_url(): void
    {
        $svc = app(YoutubeVideoService::class);

        $this->assertSame(
            '7123456789012345678',
            $svc->extractTikTokVideoId('https://www.tiktok.com/@demo/video/7123456789012345678?is_from_webapp=1')
        );
        $this->assertNull($svc->extractTikTokVideoId('https://example.com/video/7123456789012345678'));
        $this->assertNull($svc->extractTikTokVideoId('http://127.0.0.1/video/7123456789012345678'));
    }

    public function test_embed_urls_do_not_hardcode_app_origin(): void
    {
        config(['app.url' => 'http://wrong.example']);
        $svc = app(YoutubeVideoService::class);

        $yt = $svc->embedUrlFor('dQw4w9WgXcQ');
        $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $yt);
        $this->assertStringNotContainsString('wrong.example', $yt);
        $this->assertStringNotContainsString('origin=', $yt);
        $this->assertStringNotContainsString('enablejsapi', $yt);

        $tt = $svc->tiktokEmbedUrlFor('7123456789012345678');
        $this->assertStringContainsString('tiktok.com/player/v1/7123456789012345678', $tt);
    }

    public function test_env_api_key_makes_search_ready_without_enabled_checkbox(): void
    {
        config(['youtube.data_api_key' => 'env-key']);
        $svc = app(YoutubeVideoService::class);

        $this->assertTrue($svc->isSearchReady());
    }

    public function test_stored_key_can_be_turned_off(): void
    {
        config(['youtube.data_api_key' => 'env-key']);
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_YOUTUBE);
        $row->fill([
            'enabled' => false,
            'secrets' => ['api_key' => 'db-key'],
        ]);
        $row->save();

        $this->assertFalse(app(YoutubeVideoService::class)->isSearchReady());
    }

    public function test_preview_from_youtube_url_does_not_need_data_api(): void
    {
        config(['youtube.data_api_key' => '']);
        Http::fake([
            'https://www.youtube.com/oembed*' => Http::response([
                'title' => 'Never Gonna Give You Up',
                'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            ]),
        ]);

        $svc = app(YoutubeVideoService::class);
        $this->assertFalse($svc->isSearchReady());

        $item = $svc->previewFromInput('https://youtu.be/dQw4w9WgXcQ');
        $this->assertIsArray($item);
        $this->assertSame('youtube', $item['source']);
        $this->assertSame('dQw4w9WgXcQ', $item['youtubeId']);
        $this->assertSame('Never Gonna Give You Up', $item['title']);
    }

    public function test_preview_from_tiktok_url_does_not_need_an_api_key(): void
    {
        Http::fake([
            'https://www.tiktok.com/oembed*' => Http::response([
                'title' => 'A clip',
                'author_name' => 'demo',
                'thumbnail_url' => 'https://example.com/thumb.jpg',
            ]),
        ]);

        $item = app(YoutubeVideoService::class)
            ->previewFromInput('https://www.tiktok.com/@demo/video/7123456789012345678');

        $this->assertIsArray($item);
        $this->assertSame('tiktok', $item['source']);
        $this->assertSame('7123456789012345678', $item['youtubeId']);
        $this->assertStringContainsString('player/v1/7123456789012345678', $item['embedUrl']);
    }

    public function test_preview_rejects_non_video_hosts(): void
    {
        Http::fake();

        $this->assertNull(app(YoutubeVideoService::class)->previewFromInput('https://127.0.0.1/secret'));
        $this->assertNull(app(YoutubeVideoService::class)->previewFromInput('ジャズ ライブ'));
        Http::assertNothingSent();
    }
}
