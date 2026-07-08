<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\TranslationApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NoteTranslateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'test@example.com',
            'display_name' => 'Tester',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);
    }

    public function test_translate_returns_422_when_translation_not_configured(): void
    {
        config(['services.translation.api_key' => null]);

        $note = Note::create(['title' => 'テスト', 'body' => '本文']);

        $response = $this->actingAs($this->user)
            ->postJson("/notes/{$note->id}/translate");

        $response->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_translate_returns_404_for_missing_note(): void
    {
        TranslationApiKey::create([
            'name' => 'Key',
            'api_key' => 'key:fx',
            'provider' => 'deepl',
            'api_url' => 'https://api-free.deepl.com/v2/translate',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/notes/99999/translate');

        $response->assertStatus(404)
            ->assertJson(['ok' => false, 'message' => 'メモが見つかりません']);
    }

    public function test_translate_japanese_note_to_english(): void
    {
        TranslationApiKey::create([
            'name' => 'Key',
            'api_key' => 'key:fx',
            'provider' => 'deepl',
            'api_url' => 'https://api-free.deepl.com/v2/translate',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api-free.deepl.com/v2/translate' => Http::sequence()
                ->push(['translations' => [['text' => 'Item EN']]])
                ->push(['translations' => [['text' => 'Title EN']]])
                ->push(['translations' => [['text' => 'Body EN']]]),
        ]);

        $note = Note::create([
            'title' => 'タイトル',
            'body' => '本文です',
            'items' => [['text' => '項目', 'done' => false]],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/notes/{$note->id}/translate");

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'target' => 'en',
                'title' => 'Title EN',
                'body' => 'Body EN',
                'items' => ['Item EN'],
            ]);
    }

    public function test_translate_respects_explicit_target_lang(): void
    {
        TranslationApiKey::create([
            'name' => 'Key',
            'api_key' => 'key:fx',
            'provider' => 'deepl',
            'api_url' => 'https://api-free.deepl.com/v2/translate',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api-free.deepl.com/v2/translate' => Http::response([
                'translations' => [['text' => 'こんにちは']],
            ]),
        ]);

        $note = Note::create([
            'title' => 'Hello',
            'body' => 'World',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/notes/{$note->id}/translate", ['target_lang' => 'ja']);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'target' => 'ja',
                'title' => 'こんにちは',
            ]);
    }
}
