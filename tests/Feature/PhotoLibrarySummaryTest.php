<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Photo;
use App\Models\User;
use App\Services\PhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhotoLibrarySummaryTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'display_name' => 'Librarian',
            'password' => Hash::make('password123'),
            'role' => UserRole::Standard,
        ]);
    }

    private function makePhoto(User $user, string $file, string $mime, string $takenAt): Photo
    {
        return Photo::create([
            'user_id' => $user->id,
            'path' => "photos/{$user->id}/{$file}",
            'mime' => $mime,
            'size_bytes' => 1024,
            'storage_tier' => 'hot',
            'taken_at' => $takenAt,
        ]);
    }

    public function test_storage_summary_counts_photos_and_videos_separately(): void
    {
        $user = $this->makeUser('counts@example.com');
        $this->makePhoto($user, 'a.jpg', 'image/jpeg', '2026-07-27 10:00:00');
        $this->makePhoto($user, 'b.jpg', 'image/jpeg', '2026-07-27 11:00:00');
        $this->makePhoto($user, 'c.mp4', 'video/mp4', '2026-07-27 12:00:00');
        // 端末によっては MIME が落ちるので、拡張子だけでも動画として数える
        $this->makePhoto($user, 'd.mov', 'application/octet-stream', '2026-07-27 13:00:00');

        $stats = app(PhotoService::class)->storageStats((int) $user->id);

        $this->assertSame(4, $stats['photoCount']);
        $this->assertSame(2, $stats['imageCount']);
        $this->assertSame(2, $stats['videoCount']);

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('（写真 2枚 · 動画 2本）');
    }

    public function test_the_gallery_offers_a_jump_target_for_each_year_on_screen(): void
    {
        $user = $this->makeUser('jump@example.com');
        $this->makePhoto($user, 'y2024.jpg', 'image/jpeg', '2024-03-02 09:00:00');
        $this->makePhoto($user, 'y2025.jpg', 'image/jpeg', '2025-05-06 09:00:00');
        $this->makePhoto($user, 'y2026.jpg', 'image/jpeg', '2026-07-27 09:00:00');

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('id="photos-year-jump"', false)
            ->assertSee('2026年へ')
            ->assertSee('2024年へ')
            ->assertSee('data-year="2024"', false);
    }

    public function test_the_floating_dock_carries_the_year_picker_and_a_top_button(): void
    {
        $user = $this->makeUser('dock@example.com');
        $this->makePhoto($user, 'y2024.jpg', 'image/jpeg', '2024-03-02 09:00:00');
        $this->makePhoto($user, 'y2026.jpg', 'image/jpeg', '2026-07-27 09:00:00');

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('id="photos-year-dock"', false)
            ->assertSee('id="photos-year-dock-select"', false)
            ->assertSee('id="photos-year-dock-top"', false)
            ->assertSee('先頭へ戻る');
    }

    public function test_the_dock_keeps_the_top_button_when_there_is_only_one_year(): void
    {
        $user = $this->makeUser('dock-one@example.com');
        $this->makePhoto($user, 'one.jpg', 'image/jpeg', '2026-07-27 09:00:00');

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('id="photos-year-dock-top"', false)
            ->assertDontSee('id="photos-year-dock-select"', false);
    }

    public function test_the_column_control_offers_step_buttons_at_both_ends(): void
    {
        $user = $this->makeUser('cols@example.com');
        $this->makePhoto($user, 'a.jpg', 'image/jpeg', '2026-07-27 09:00:00');

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('data-cols-step="-1"', false)
            ->assertSee('data-cols-step="1"', false)
            ->assertSee('1列減らす')
            ->assertSee('1列増やす')
            ->assertSee('min="1"', false)
            ->assertSee('max="7"', false);
    }

    public function test_a_single_year_library_does_not_show_the_jump_control(): void
    {
        $user = $this->makeUser('oneyear@example.com');
        $this->makePhoto($user, 'one.jpg', 'image/jpeg', '2026-07-27 09:00:00');
        $this->makePhoto($user, 'two.jpg', 'image/jpeg', '2026-02-11 09:00:00');

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertDontSee('id="photos-year-jump"', false)
            ->assertDontSee('2026年へ');
    }

    public function test_jump_years_follow_the_order_the_groups_are_rendered(): void
    {
        $service = app(PhotoService::class);

        $years = $service->groupYearOptions([
            ['date' => '2026-07-27', 'label' => '2026年7月27日', 'photos' => []],
            ['date' => '2026-02-11', 'label' => '2026年2月11日', 'photos' => []],
            ['date' => '2024-03-02', 'label' => '2024年3月2日', 'photos' => []],
            ['date' => 'all', 'label' => 'すべて', 'photos' => []],
        ]);

        $this->assertSame([2026, 2024], $years);
    }

    public function test_large_libraries_auto_scope_to_latest_year_until_all_requested(): void
    {
        $user = $this->makeUser('autoscope@example.com');
        for ($i = 0; $i < 121; $i++) {
            $this->makePhoto($user, "old{$i}.jpg", 'image/jpeg', sprintf('2024-01-%02d 10:00:00', ($i % 28) + 1));
        }
        $this->makePhoto($user, 'new.jpg', 'image/jpeg', '2026-08-01 10:00:00');

        $this->actingAs($user)->get('/photos')
            ->assertOk()
            ->assertSee('最新の 2026 年のみ表示')
            ->assertSee('すべての年を表示')
            ->assertSee('data-year="2026"', false)
            ->assertDontSee('data-year="2024"', false)
            ->assertSee('id="photos-year-jump"', false)
            ->assertSee('2024年へ');

        $this->actingAs($user)->get('/photos?year=all')
            ->assertOk()
            ->assertDontSee('最新の 2026 年のみ表示')
            ->assertSee('data-year="2024"', false);
    }

    public function test_year_filter_outside_current_scope_is_dropped_instead_of_empty_gallery(): void
    {
        $user = $this->makeUser('yearswitch@example.com');
        $album = \App\Models\PhotoAlbum::create([
            'user_id' => $user->id,
            'name' => 'Travel',
            'visibility' => 'private',
        ]);
        $this->makePhoto($user, 'a2012.jpg', 'image/jpeg', '2012-04-05 10:00:00')
            ->forceFill(['album_id' => $album->id])
            ->save();
        $other = \App\Models\PhotoAlbum::create([
            'user_id' => $user->id,
            'name' => 'Other',
            'visibility' => 'private',
        ]);
        $this->makePhoto($user, 'b2024.jpg', 'image/jpeg', '2024-06-01 10:00:00')
            ->forceFill(['album_id' => $other->id])
            ->save();

        // 別アルバムで選んだ年を持ち込むと、その年が無いので year を外してリダイレクト
        $this->actingAs($user)
            ->get('/photos?album='.$other->id.'&year=2012')
            ->assertRedirect('/photos?album='.$other->id);

        $this->actingAs($user)
            ->get('/photos?album='.$other->id)
            ->assertOk()
            ->assertSee('data-year="2024"', false)
            ->assertDontSee('まだメディアがありません');
    }
}
