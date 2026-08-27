<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MusicLibrary;
use App\Models\MusicTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MusicLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'email' => 'music@example.com',
            'display_name' => 'Listener',
            'password' => Hash::make('password123'),
            'role' => UserRole::SuperAdmin,
        ]);
    }

    private function addTrack(User $user, int $libraryId, string $title, int $sort = 0): MusicTrack
    {
        return MusicTrack::query()->create([
            'user_id' => $user->id,
            'music_library_id' => $libraryId,
            'title' => $title,
            'original_name' => $title.'.mp3',
            'path' => 'music/'.$user->id.'/'.md5($title).'.mp3',
            'mime' => 'audio/mpeg',
            'size_bytes' => 1024,
            'sort_order' => $sort,
        ]);
    }

    private function defaultLibraryId(User $user): int
    {
        $this->actingAs($user)->get('/music')->assertOk();

        return (int) MusicLibrary::query()
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->value('id');
    }

    public function test_music_page_shows_default_library(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get('/music')
            ->assertOk()
            ->assertSee(__('ライブラリ'), false)
            ->assertSee(__('マイリスト'), false);

        $this->assertDatabaseHas('music_libraries', [
            'user_id' => $user->id,
            'is_default' => true,
        ]);
    }

    public function test_user_can_create_and_rename_a_library(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/music/libraries', [
            'name' => 'ドライブ',
            'returnTo' => '/music',
        ])->assertRedirect();

        $library = MusicLibrary::query()->where('user_id', $user->id)->where('name', 'ドライブ')->first();
        $this->assertNotNull($library);

        $this->actingAs($user)->post('/music/libraries/'.$library->id.'/update', [
            'name' => '作業用',
            'returnTo' => '/music?library='.$library->id,
        ])->assertRedirect();

        $this->assertSame('作業用', $library->fresh()->name);
    }

    public function test_tracks_are_listed_per_library(): void
    {
        $user = $this->makeUser();
        $defaultId = $this->defaultLibraryId($user);
        $this->actingAs($user)->post('/music/libraries', ['name' => 'ジャズ', 'returnTo' => '/music']);
        $jazzId = (int) MusicLibrary::query()->where('user_id', $user->id)->where('name', 'ジャズ')->value('id');

        $this->addTrack($user, $defaultId, 'Default song');
        $this->addTrack($user, $jazzId, 'Jazz song');

        $this->actingAs($user)->get('/music')
            ->assertOk()
            ->assertSee('Default song', false)
            ->assertDontSee('Jazz song', false);

        $this->actingAs($user)->get('/music?library='.$jazzId)
            ->assertOk()
            ->assertSee('Jazz song', false)
            ->assertDontSee('Default song', false);
    }

    public function test_track_can_move_between_libraries(): void
    {
        $user = $this->makeUser();
        $defaultId = $this->defaultLibraryId($user);
        $this->actingAs($user)->post('/music/libraries', ['name' => '夜用', 'returnTo' => '/music']);
        $nightId = (int) MusicLibrary::query()->where('user_id', $user->id)->where('name', '夜用')->value('id');
        $track = $this->addTrack($user, $defaultId, 'Movable');

        $this->actingAs($user)->post('/music/'.$track->id.'/move', [
            'library_id' => $nightId,
            'returnTo' => '/music',
        ])->assertRedirect();

        $this->assertSame($nightId, (int) $track->fresh()->music_library_id);
    }

    public function test_deleting_a_library_moves_tracks_to_my_list(): void
    {
        $user = $this->makeUser();
        $defaultId = $this->defaultLibraryId($user);
        $this->actingAs($user)->post('/music/libraries', ['name' => '一時', 'returnTo' => '/music']);
        $tempId = (int) MusicLibrary::query()->where('user_id', $user->id)->where('name', '一時')->value('id');
        $track = $this->addTrack($user, $tempId, 'Rescued');

        $this->actingAs($user)->post('/music/libraries/'.$tempId.'/delete', [
            'returnTo' => '/music',
        ])
            ->assertRedirect('/music')
            ->assertSessionHas('notice', __('ライブラリを削除しました。曲はマイリストへ移動しました。'));

        $this->assertDatabaseMissing('music_libraries', ['id' => $tempId]);
        $this->assertSame($defaultId, (int) $track->fresh()->music_library_id);
    }

    public function test_my_list_cannot_be_deleted(): void
    {
        $user = $this->makeUser();
        $defaultId = $this->defaultLibraryId($user);

        $this->actingAs($user)->post('/music/libraries/'.$defaultId.'/delete', [
            'returnTo' => '/music',
        ])->assertRedirect();

        $this->assertDatabaseHas('music_libraries', ['id' => $defaultId]);
    }

    public function test_library_paginates_after_twenty_tracks(): void
    {
        $user = $this->makeUser();
        $defaultId = $this->defaultLibraryId($user);
        for ($i = 0; $i < 21; $i++) {
            $this->addTrack($user, $defaultId, 'Track '.$i, $i);
        }

        $first = $this->actingAs($user)->get('/music')->assertOk()->getContent();
        $this->assertStringContainsString('id="music-pager"', $first);
        $this->assertStringContainsString('Track 0', $first);
        $this->assertStringNotContainsString('Track 20', $first);

        $second = $this->actingAs($user)->get('/music?page=2')->assertOk()->getContent();
        $this->assertStringContainsString('Track 20', $second);
        $this->assertStringNotContainsString('Track 0', $second);
    }

    public function test_share_landing_page_renders(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get('/music/share?shared=2')
            ->assertOk()
            ->assertSee('sa2-shared-audio-v1', false)
            ->assertSee(__('共有された曲'), false);
    }

    public function test_share_landing_page_requires_login(): void
    {
        $this->get('/music/share')->assertRedirect('/login');
    }

    public function test_manifest_declares_the_audio_share_target(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('/music/share', $manifest['share_target']['action'] ?? null);
        $this->assertSame('POST', $manifest['share_target']['method'] ?? null);
        $this->assertSame('multipart/form-data', $manifest['share_target']['enctype'] ?? null);
        $this->assertSame('tracks', $manifest['share_target']['params']['files'][0]['name'] ?? null);
    }

    public function test_upload_endpoint_answers_json_for_ajax(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/music', ['returnTo' => '/music'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_filter_matches_titles(): void
    {
        $user = $this->makeUser();
        $defaultId = $this->defaultLibraryId($user);
        $this->addTrack($user, $defaultId, 'Morning jazz');
        $this->addTrack($user, $defaultId, 'Night rock');

        $this->actingAs($user)->get('/music?q='.urlencode('jazz'))
            ->assertOk()
            ->assertSee('Morning jazz', false)
            ->assertDontSee('Night rock', false);
    }
}
