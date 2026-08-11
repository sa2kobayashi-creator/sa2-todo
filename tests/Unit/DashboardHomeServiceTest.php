<?php

namespace Tests\Unit;

use App\Models\GoogleCalendarConnection;
use App\Models\Note;
use App\Models\Photo;
use App\Models\Todo;
use App\Models\User;
use App\Services\DashboardHomeService;
use App\Services\GoogleCalendarService;
use App\Services\NoteService;
use App\Services\PhotoService;
use App\Services\TodoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class DashboardHomeServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GoogleCalendarService $google;

    private DashboardHomeService $home;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 10:00:00', 'Asia/Tokyo'));

        $this->user = User::create([
            'email' => 'dashhome@example.com',
            'display_name' => '小林',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);

        $this->google = Mockery::mock(GoogleCalendarService::class);
        $this->app->instance(GoogleCalendarService::class, $this->google);

        $this->home = new DashboardHomeService(
            app(TodoService::class),
            app(NoteService::class),
            app(PhotoService::class),
            $this->google,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_greeting_and_counts_for_personal_todos(): void
    {
        $this->google->shouldReceive('connectionFor')->andReturn(null);

        Todo::create([
            'user_id' => $this->user->id,
            'title' => '今日の仕事',
            'completed' => false,
            'start_date' => '2026-08-11',
            'end_date' => '2026-08-11',
            'start_time' => '14:00',
            'importance' => 'high',
            'category' => 'task',
        ]);
        Todo::create([
            'user_id' => $this->user->id,
            'title' => '午前の予定',
            'completed' => false,
            'start_date' => '2026-08-11',
            'end_date' => '2026-08-11',
            'start_time' => '09:00',
            'importance' => 'medium',
            'category' => 'task',
        ]);
        Todo::create([
            'user_id' => $this->user->id,
            'title' => '期限切れ',
            'completed' => false,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-01',
            'importance' => 'medium',
            'category' => 'task',
        ]);
        Todo::create([
            'user_id' => $this->user->id,
            'title' => '完了済み',
            'completed' => true,
            'start_date' => '2026-08-11',
            'end_date' => '2026-08-11',
            'importance' => 'medium',
            'category' => 'task',
        ]);

        $data = $this->home->build($this->user);

        $this->assertStringContainsString('8', $data['dateLabel']);
        $this->assertStringContainsString('小林', $data['greetingLine']);
        $this->assertSame(2, $data['counts']['todos']);
        $this->assertArrayNotHasKey('attention', $data['counts']);
        $this->assertArrayNotHasKey('photosToday', $data['counts']);
        $this->assertSame(0, $data['counts']['events']);
        $this->assertCount(1, $data['nextActions']);
        $this->assertSame('今日の仕事', $data['nextActions'][0]['title']);
        $this->assertSame('https://calendar.google.com/calendar/r/day', $data['links']['googleCalendar']);
        $this->assertFalse($data['calendar']['connected']);
    }

    public function test_next_actions_skip_past_and_overdue(): void
    {
        $this->google->shouldReceive('connectionFor')->andReturn(null);

        Todo::create([
            'user_id' => $this->user->id,
            'title' => '過去の通院',
            'completed' => false,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-20',
            'start_time' => '09:00',
            'importance' => 'medium',
            'category' => 'task',
        ]);
        Todo::create([
            'user_id' => $this->user->id,
            'title' => '午後の通院',
            'completed' => false,
            'start_date' => '2026-08-11',
            'end_date' => '2026-08-11',
            'start_time' => '13:00',
            'importance' => 'medium',
            'category' => 'task',
        ]);

        $data = $this->home->build($this->user);

        $this->assertCount(1, $data['nextActions']);
        $this->assertSame('午後の通院', $data['nextActions'][0]['title']);
    }

    public function test_google_calendar_events_and_countdown(): void
    {
        $connection = new GoogleCalendarConnection([
            'user_id' => $this->user->id,
            'google_user_id' => 'g-1',
            'google_email' => 'g@example.com',
        ]);
        $this->google->shouldReceive('connectionFor')->andReturn($connection);
        $this->google->shouldReceive('listEventsAsTodos')->once()->andReturn([
            [
                'id' => 'gcal:1',
                'title' => '会議',
                'startTime' => '11:30',
                'endTime' => '12:00',
                'htmlLink' => 'https://calendar.google.com/event/1',
                'googleEventId' => '1',
            ],
            [
                'id' => 'gcal:2',
                'title' => '終日イベント',
                'startTime' => null,
                'endTime' => null,
                'htmlLink' => null,
                'googleEventId' => '2',
            ],
        ]);

        $data = $this->home->build($this->user);

        $this->assertTrue($data['calendar']['connected']);
        $this->assertSame(2, $data['counts']['events']);
        $this->assertSame('会議', $data['calendar']['next']['title']);
        $this->assertSame('あと 1時間30分', $data['calendar']['nextInLabel']);
        $this->assertSame('11:30', $data['calendar']['events'][0]['timeLabel']);
        $this->assertSame('終日', $data['calendar']['events'][1]['timeLabel']);
    }

    public function test_photos_prefer_on_this_day_over_recent(): void
    {
        $this->google->shouldReceive('connectionFor')->andReturn(null);

        Photo::create([
            'user_id' => $this->user->id,
            'path' => 'photos/old.jpg',
            'original_name' => 'old.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'taken_at' => '2024-08-11 12:00:00',
        ]);
        Photo::create([
            'user_id' => $this->user->id,
            'path' => 'photos/recent.jpg',
            'original_name' => 'recent.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'taken_at' => '2026-08-01 12:00:00',
        ]);

        $data = $this->home->build($this->user);

        $this->assertSame('on_this_day', $data['photos']['mode']);
        $this->assertStringContainsString('年前の今日', $data['photos']['title']);
        $this->assertGreaterThanOrEqual(1, count($data['photos']['items']));
        $this->assertGreaterThanOrEqual(1, count($data['photos']['pool']));
        $this->assertSame(60_000, $data['photos']['rotateMs']);
        $this->assertSame('old.jpg', $data['photos']['items'][0]['originalName']);
    }

    public function test_photos_pool_is_larger_than_visible_slot_for_rotation(): void
    {
        $this->google->shouldReceive('connectionFor')->andReturn(null);

        foreach (range(1, 6) as $i) {
            Photo::create([
                'user_id' => $this->user->id,
                'path' => "photos/recent-{$i}.jpg",
                'original_name' => "recent-{$i}.jpg",
                'mime' => 'image/jpeg',
                'size_bytes' => 100,
                'taken_at' => sprintf('2026-08-%02d 12:00:00', $i),
            ]);
        }

        $data = $this->home->build($this->user);

        $this->assertSame('recent', $data['photos']['mode']);
        $this->assertSame(4, $data['photos']['visible']);
        $this->assertCount(4, $data['photos']['items']);
        $this->assertCount(6, $data['photos']['pool']);
    }

    public function test_on_this_day_pool_is_filled_with_recent_photos_for_rotation(): void
    {
        $this->google->shouldReceive('connectionFor')->andReturn(null);

        Photo::create([
            'user_id' => $this->user->id,
            'path' => 'photos/old.jpg',
            'original_name' => 'old.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 100,
            'taken_at' => '2024-08-11 12:00:00',
        ]);
        foreach (range(1, 5) as $i) {
            Photo::create([
                'user_id' => $this->user->id,
                'path' => "photos/fill-{$i}.jpg",
                'original_name' => "fill-{$i}.jpg",
                'mime' => 'image/jpeg',
                'size_bytes' => 100,
                'album_id' => null,
                'taken_at' => sprintf('2026-07-%02d 12:00:00', $i),
            ]);
        }

        $data = $this->home->build($this->user);

        $this->assertSame('on_this_day', $data['photos']['mode']);
        $this->assertGreaterThan(4, count($data['photos']['pool']));
        $this->assertSame('old.jpg', $data['photos']['pool'][0]['originalName']);
    }

    public function test_pinned_notes_appear_in_home(): void
    {
        $this->google->shouldReceive('connectionFor')->andReturn(null);

        Note::create([
            'user_id' => $this->user->id,
            'title' => 'ピン済み',
            'body' => '重要',
            'pinned' => true,
            'archived' => false,
            'type' => 'memo',
            'category' => 'personal',
        ]);
        Note::create([
            'user_id' => $this->user->id,
            'title' => '普通',
            'body' => '普通',
            'pinned' => false,
            'archived' => false,
            'type' => 'memo',
            'category' => 'personal',
        ]);

        $data = $this->home->build($this->user);

        $this->assertCount(1, $data['pinnedNotes']);
        $this->assertSame('ピン済み', $data['pinnedNotes'][0]['title']);
    }
}
