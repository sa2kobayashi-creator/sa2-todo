<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarOAuthService;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarMeetLinkTest extends TestCase
{
    use RefreshDatabase;

    private GoogleCalendarService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GoogleCalendarService(app(GoogleCalendarOAuthService::class));
    }

    public function test_extracts_hangout_link(): void
    {
        $link = $this->service->extractMeetLink([
            'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $link);
    }

    public function test_extracts_conference_data_video_entrypoint(): void
    {
        $link = $this->service->extractMeetLink([
            'conferenceData' => [
                'entryPoints' => [
                    ['entryPointType' => 'phone', 'uri' => 'tel:+81-3-0000-0000'],
                    ['entryPointType' => 'video', 'uri' => 'https://meet.google.com/xyz-uvwx-yz0?authuser=0'],
                ],
            ],
        ]);
        $this->assertSame('https://meet.google.com/xyz-uvwx-yz0', $link);
    }

    public function test_extracts_meet_url_from_description(): void
    {
        $link = $this->service->extractMeetLink([
            'description' => "議題メモ\n参加: https://meet.google.com/aaa-bbbb-ccc\n以上",
        ]);
        $this->assertSame('https://meet.google.com/aaa-bbbb-ccc', $link);
    }

    public function test_extracts_lookup_style_meet_url(): void
    {
        $link = $this->service->extractMeetLink([
            'location' => 'https://meet.google.com/lookup/examplecode',
        ]);
        $this->assertSame('https://meet.google.com/lookup/examplecode', $link);
    }

    public function test_merge_refreshes_meet_link_on_known_google_event(): void
    {
        $user = User::create([
            'email' => 'meet-merge@example.com',
            'display_name' => 'Meet Merge',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
        ]);

        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'google_user_id' => 'sub-meet-merge',
            'google_email' => 'work@example.com',
            'access_token' => 'access-token-value',
            'refresh_token' => 'refresh-token-value',
            'token_expires_at' => now()->addHour(),
            'scopes' => 'openid email https://www.googleapis.com/auth/calendar.events',
            'selected_calendar_ids' => ['primary'],
            'sync_calendar_id' => 'primary',
        ]);

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/*' => Http::response([
                'items' => [[
                    'id' => 'evt-1',
                    'summary' => '定例',
                    'htmlLink' => 'https://calendar.google.com/event?eid=1',
                    'hangoutLink' => 'https://meet.google.com/mmm-nnnn-ooo',
                    'start' => ['dateTime' => '2026-08-03T10:00:00+09:00'],
                    'end' => ['dateTime' => '2026-08-03T11:00:00+09:00'],
                ]],
            ], 200),
        ]);

        $merged = $this->service->mergeEventsIntoTodos($user, [[
            'id' => 10,
            'title' => '定例',
            'googleEventId' => 'evt-1',
            'googleMeetLink' => null,
            'htmlLink' => null,
        ]], '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->assertCount(1, $merged);
        $this->assertSame('https://meet.google.com/mmm-nnnn-ooo', $merged[0]['googleMeetLink']);
        $this->assertSame('https://calendar.google.com/event?eid=1', $merged[0]['htmlLink']);
    }
}
