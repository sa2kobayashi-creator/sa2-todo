<?php

namespace Tests\Feature;

use App\Models\TranslationApiKey;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranslationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_is_configured_returns_false_without_api_key(): void
    {
        config(['services.translation.api_key' => null]);

        $service = new TranslationService;

        $this->assertFalse($service->isConfigured());
    }

    public function test_translate_returns_empty_text_unchanged(): void
    {
        config(['services.translation.api_key' => 'env-key:fx']);

        $service = new TranslationService;

        $this->assertSame('', $service->translate('', 'ja', 'en'));
        $this->assertSame('   ', $service->translate('   ', 'ja', 'en'));
    }

    public function test_translate_returns_same_text_when_source_equals_target(): void
    {
        config(['services.translation.api_key' => 'env-key:fx']);

        $service = new TranslationService;

        $this->assertSame('hello', $service->translate('hello', 'en', 'en'));
    }

    public function test_translate_calls_deepl_and_caches_result(): void
    {
        config([
            'services.translation.api_key' => 'env-key:fx',
            'services.translation.api_url' => 'https://api-free.deepl.com/v2/translate',
        ]);

        Http::fake([
            'https://api-free.deepl.com/v2/translate' => Http::response([
                'translations' => [['text' => 'Hello']],
            ]),
        ]);

        $service = new TranslationService;
        $first = $service->translate('こんにちは', 'ja', 'en');
        $second = $service->translate('こんにちは', 'ja', 'en');

        $this->assertSame('Hello', $first);
        $this->assertSame('Hello', $second);
        Http::assertSentCount(1);
    }

    public function test_translate_uses_database_api_key_and_increments_usage(): void
    {
        $key = TranslationApiKey::create([
            'name' => 'DB Key',
            'api_key' => 'db-key:fx',
            'provider' => 'deepl',
            'api_url' => 'https://api-free.deepl.com/v2/translate',
            'current_daily_usage' => 0,
            'current_monthly_usage' => 0,
            'last_reset_date' => now()->toDateString(),
            'last_monthly_reset_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        Http::fake([
            'https://api-free.deepl.com/v2/translate' => Http::response([
                'translations' => [['text' => 'Test']],
            ]),
        ]);

        $service = new TranslationService;
        $result = $service->translate('テスト', 'ja', 'en');

        $this->assertTrue($service->isConfigured());
        $this->assertSame('Test', $result);

        $key->refresh();
        $this->assertSame(mb_strlen('テスト'), $key->current_daily_usage);
        $this->assertSame(mb_strlen('テスト'), $key->current_monthly_usage);
    }
}
