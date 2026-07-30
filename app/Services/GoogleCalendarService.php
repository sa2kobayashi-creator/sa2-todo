<?php

namespace App\Services;

use App\Models\GoogleCalendarConnection;
use App\Models\Todo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleCalendarService
{
    public function __construct(private GoogleCalendarOAuthService $oauth) {}

    public function connectionFor(User $user): ?GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->where('user_id', $user->id)->first();
    }

    /**
     * 有効な access token を返す（必要なら refresh）。
     */
    public function accessTokenFor(User $user): string
    {
        $connection = $this->connectionFor($user);
        if (! $connection) {
            throw new \RuntimeException('Google カレンダーは連携されていません。');
        }

        if ($connection->accessTokenExpired()) {
            $connection = $this->oauth->refreshAccessToken($connection);
        }

        $token = $connection->access_token;
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('アクセストークンがありません。再連携してください。');
        }

        return $token;
    }

    /**
     * @return list<array{id: string, summary: string, primary: bool, accessRole: string, backgroundColor: ?string}>
     */
    public function listCalendars(User $user): array
    {
        $token = $this->accessTokenFor($user);
        $response = Http::withToken($token)
            ->timeout(20)
            ->acceptJson()
            ->get('https://www.googleapis.com/calendar/v3/users/me/calendarList', [
                'minAccessRole' => 'reader',
            ]);

        if (! $response->successful()) {
            Log::warning('Google Calendar listCalendars failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);
            throw new \RuntimeException(__('カレンダー一覧の取得に失敗しました。権限を再許可するため再連携してください。'));
        }

        $items = $response->json('items');
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['id'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $item['id'],
                'summary' => (string) ($item['summary'] ?? $item['id']),
                'primary' => ! empty($item['primary']),
                'accessRole' => (string) ($item['accessRole'] ?? 'reader'),
                'backgroundColor' => isset($item['backgroundColor']) ? (string) $item['backgroundColor'] : null,
            ];
        }

        usort($out, function ($a, $b) {
            if ($a['primary'] !== $b['primary']) {
                return $a['primary'] ? -1 : 1;
            }

            return strcmp($a['summary'], $b['summary']);
        });

        return $out;
    }

    /**
     * @param  list<string>|null  $calendarIds
     * @return list<array<string, mixed>> Todo互換の予定配列
     */
    public function listEventsAsTodos(User $user, string $timeMin, string $timeMax, ?array $calendarIds = null): array
    {
        $connection = $this->connectionFor($user);
        if (! $connection) {
            return [];
        }

        $ids = $calendarIds ?? $connection->selectedCalendarIds();
        $token = $this->accessTokenFor($user);
        $events = [];

        foreach ($ids as $calendarId) {
            $pageToken = null;
            do {
                $query = [
                    'timeMin' => Carbon::parse($timeMin, config('app.timezone'))->toRfc3339String(),
                    'timeMax' => Carbon::parse($timeMax, config('app.timezone'))->toRfc3339String(),
                    'singleEvents' => 'true',
                    'orderBy' => 'startTime',
                    'maxResults' => 250,
                ];
                if ($pageToken) {
                    $query['pageToken'] = $pageToken;
                }

                $response = Http::withToken($token)
                    ->timeout(30)
                    ->acceptJson()
                    ->get('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events', $query);

                if ($response->status() === 401) {
                    $this->oauth->refreshAccessToken($connection);
                    $token = $this->accessTokenFor($user);
                    $response = Http::withToken($token)
                        ->timeout(30)
                        ->acceptJson()
                        ->get('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events', $query);
                }

                if (! $response->successful()) {
                    Log::warning('Google Calendar listEvents failed', [
                        'user_id' => $user->id,
                        'calendar_id' => $calendarId,
                        'status' => $response->status(),
                    ]);
                    break;
                }

                $items = $response->json('items');
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if (! is_array($item) || empty($item['id'])) {
                            continue;
                        }
                        $events[] = $this->mapGoogleEventToTodo($item, $calendarId, $user->id);
                    }
                }
                $pageToken = $response->json('nextPageToken');
            } while (is_string($pageToken) && $pageToken !== '');
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $todo  Todo配列
     * @return array{id: string, htmlLink: ?string, calendarId: string, meetLink: ?string}
     */
    public function createEventFromTodo(User $user, array $todo, ?string $calendarId = null): array
    {
        $connection = $this->connectionFor($user);
        if (! $connection) {
            throw new \RuntimeException('Google カレンダーは連携されていません。');
        }

        $calendarId = $calendarId ?: $connection->syncCalendarId();
        $payload = $this->todoToGoogleEventPayload($todo, withMeet: true);
        $token = $this->accessTokenFor($user);
        $url = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';

        $response = Http::withToken($token)
            ->timeout(20)
            ->acceptJson()
            ->post($url.'?conferenceDataVersion=1', $payload);

        // Meet 作成不可（権限・ワークスペース設定等）の場合は Meet なしで再試行
        if (! $response->successful()) {
            Log::warning('Google Calendar createEvent with Meet failed, retrying without Meet', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);
            $payloadNoMeet = $this->todoToGoogleEventPayload($todo, withMeet: false);
            $response = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->post($url, $payloadNoMeet);
        }

        if (! $response->successful()) {
            Log::warning('Google Calendar createEvent failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);
            throw new \RuntimeException(__('Google カレンダーへの予定作成に失敗しました。'));
        }

        $data = $response->json();
        if (! is_array($data)) {
            $data = [];
        }

        return [
            'id' => (string) ($data['id'] ?? ''),
            'htmlLink' => isset($data['htmlLink']) ? (string) $data['htmlLink'] : null,
            'calendarId' => $calendarId,
            'meetLink' => $this->extractMeetLink($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $todo
     * @return array{meetLink: ?string}
     */
    public function updateEventFromTodo(User $user, array $todo): array
    {
        $eventId = (string) ($todo['googleEventId'] ?? '');
        $calendarId = (string) ($todo['googleCalendarId'] ?? '');
        if ($eventId === '' || $calendarId === '') {
            return ['meetLink' => $todo['googleMeetLink'] ?? null];
        }

        $token = $this->accessTokenFor($user);
        // PATCH: conferenceData を送らないので既存 Meet を維持
        $payload = $this->todoToGoogleEventPayload($todo, withMeet: false);

        $response = Http::withToken($token)
            ->timeout(20)
            ->acceptJson()
            ->patch(
                'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId),
                $payload
            );

        if (! $response->successful()) {
            Log::warning('Google Calendar updateEvent failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);
            throw new \RuntimeException(__('Google カレンダーの予定更新に失敗しました。'));
        }

        $data = $response->json();
        $meet = is_array($data) ? $this->extractMeetLink($data) : null;

        return [
            'meetLink' => $meet ?: ($todo['googleMeetLink'] ?? null),
        ];
    }

    public function deleteEvent(User $user, string $calendarId, string $eventId): void
    {
        if ($calendarId === '' || $eventId === '') {
            return;
        }

        $token = $this->accessTokenFor($user);
        $response = Http::withToken($token)
            ->timeout(20)
            ->delete(
                'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId)
            );

        // 404 は既に削除済みとして成功扱い
        if (! $response->successful() && $response->status() !== 404) {
            Log::warning('Google Calendar deleteEvent failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);
            throw new \RuntimeException(__('Google カレンダーの予定削除に失敗しました。'));
        }
    }

    /**
     * ローカル ToDo に Google 予定をマージ（同一 google_event_id は重複除外）。
     *
     * @param  list<array<string, mixed>>  $localTodos
     * @return list<array<string, mixed>>
     */
    public function mergeEventsIntoTodos(User $user, array $localTodos, string $timeMin, string $timeMax): array
    {
        if (! $this->connectionFor($user)) {
            return $localTodos;
        }

        try {
            $remote = $this->listEventsAsTodos($user, $timeMin, $timeMax);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar mergeEventsIntoTodos failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return $localTodos;
        }

        $known = [];
        foreach ($localTodos as $todo) {
            if (! empty($todo['googleEventId'])) {
                $known[(string) $todo['googleEventId']] = true;
            }
        }

        foreach ($remote as $event) {
            $eid = (string) ($event['googleEventId'] ?? '');
            if ($eid !== '' && isset($known[$eid])) {
                continue;
            }
            $localTodos[] = $event;
            if ($eid !== '') {
                $known[$eid] = true;
            }
        }

        return $localTodos;
    }

    /**
     * primary カレンダーの直近イベントを1件取得（疎通確認用）。
     *
     * @return array{ok: bool, message: string, event: ?array{summary: string, start: string, end: string, id: string}}
     */
    public function probePrimaryCalendar(User $user): array
    {
        try {
            $token = $this->accessTokenFor($user);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'event' => null];
        }

        $response = Http::withToken($token)
            ->timeout(20)
            ->acceptJson()
            ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
                'maxResults' => 1,
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'timeMin' => now()->subDays(30)->toRfc3339String(),
            ]);

        if ($response->status() === 401) {
            try {
                $connection = $this->connectionFor($user);
                if ($connection) {
                    $this->oauth->refreshAccessToken($connection);
                    $token = $this->accessTokenFor($user);
                    $response = Http::withToken($token)
                        ->timeout(20)
                        ->acceptJson()
                        ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
                            'maxResults' => 1,
                            'singleEvents' => 'true',
                            'orderBy' => 'startTime',
                            'timeMin' => now()->subDays(30)->toRfc3339String(),
                        ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Google Calendar probe refresh failed', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);

                return [
                    'ok' => false,
                    'message' => __('認証の有効期限が切れています。再連携してください。'),
                    'event' => null,
                ];
            }
        }

        if ($response->status() === 403) {
            Log::warning('Google Calendar probe forbidden', ['user_id' => $user->id]);

            return [
                'ok' => false,
                'message' => __('カレンダーへのアクセス権限が不足しています。再連携で権限を許可してください。'),
                'event' => null,
            ];
        }

        if (! $response->successful()) {
            Log::warning('Google Calendar probe failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);

            return [
                'ok' => false,
                'message' => __('Google Calendar API の呼び出しに失敗しました。'),
                'event' => null,
            ];
        }

        $items = $response->json('items');
        if (! is_array($items) || $items === []) {
            return [
                'ok' => true,
                'message' => __('接続成功（直近30日に予定はありません）'),
                'event' => null,
            ];
        }

        $item = $items[0];
        $summary = (string) ($item['summary'] ?? __('（タイトルなし）'));
        $start = (string) ($item['start']['dateTime'] ?? $item['start']['date'] ?? '');
        $end = (string) ($item['end']['dateTime'] ?? $item['end']['date'] ?? '');
        $id = (string) ($item['id'] ?? '');

        return [
            'ok' => true,
            'message' => __('接続成功'),
            'event' => [
                'id' => $id,
                'summary' => $summary,
                'start' => $start,
                'end' => $end,
            ],
        ];
    }

    public function saveCalendarSelection(User $user, array $selectedIds, ?string $syncCalendarId): GoogleCalendarConnection
    {
        $connection = $this->connectionFor($user);
        if (! $connection) {
            throw new \RuntimeException('Google カレンダーは連携されていません。');
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($v) => trim((string) $v),
            $selectedIds
        ), fn ($v) => $v !== '')));

        if ($ids === []) {
            $ids = ['primary'];
        }

        $sync = trim((string) ($syncCalendarId ?? ''));
        if ($sync === '') {
            $sync = $ids[0];
        }
        if (! in_array($sync, $ids, true)) {
            $ids[] = $sync;
        }

        $connection->selected_calendar_ids = $ids;
        $connection->sync_calendar_id = $sync;
        $connection->save();

        return $connection->fresh();
    }

    /**
     * Google 予定を仕事 ToDo として取り込む（既存 google_event_id は更新）。
     *
     * @return array{created: int, updated: int}
     */
    public function importEventsToWorkTodos(User $user, string $timeMin, string $timeMax): array
    {
        $events = $this->listEventsAsTodos($user, $timeMin, $timeMax);
        $created = 0;
        $updated = 0;

        foreach ($events as $event) {
            $eventId = (string) ($event['googleEventId'] ?? '');
            if ($eventId === '') {
                continue;
            }

            $todo = Todo::query()
                ->where('user_id', $user->id)
                ->where('google_event_id', $eventId)
                ->first();

            $attrs = [
                'title' => (string) ($event['title'] ?? __('（無題）')),
                'context' => 'work',
                'group_id' => null,
                'start_date' => $event['startDate'] ?? null,
                'end_date' => $event['endDate'] ?? null,
                'start_time' => $event['startTime'] ?? null,
                'end_time' => $event['endTime'] ?? null,
                'google_calendar_id' => $event['googleCalendarId'] ?? null,
                'google_meet_link' => $event['googleMeetLink'] ?? null,
                'google_synced_at' => now(),
                'category' => 'task',
                'importance' => 'medium',
            ];

            if ($todo) {
                $todo->fill($attrs);
                $todo->save();
                $updated++;
            } else {
                Todo::create(array_merge($attrs, [
                    'user_id' => $user->id,
                    'google_event_id' => $eventId,
                    'completed' => false,
                    'reminders' => [],
                    'notified_at' => [],
                ]));
                $created++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /** 設定画面用（トークンは含めない） */
    public function formState(?User $user): array
    {
        $configured = $this->oauth->isConfigured();
        if (! $user) {
            return [
                'configured' => $configured,
                'connected' => false,
                'googleEmail' => null,
                'connectedAt' => null,
                'calendars' => [],
                'selectedCalendarIds' => [],
                'syncCalendarId' => null,
                'needsRescope' => false,
                'probe' => null,
            ];
        }

        $connection = $this->connectionFor($user);
        $calendars = [];
        $needsRescope = false;
        if ($connection) {
            try {
                $calendars = $this->listCalendars($user);
            } catch (\Throwable $e) {
                $needsRescope = true;
            }
        }

        return [
            'configured' => $configured,
            'connected' => $connection !== null,
            'googleEmail' => $connection?->google_email,
            'connectedAt' => $connection?->updated_at?->format('Y-m-d H:i'),
            'calendars' => $calendars,
            'selectedCalendarIds' => $connection?->selectedCalendarIds() ?? [],
            'syncCalendarId' => $connection?->syncCalendarId(),
            'needsRescope' => $needsRescope,
            'probe' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapGoogleEventToTodo(array $item, string $calendarId, int $userId): array
    {
        $startRaw = $item['start']['dateTime'] ?? $item['start']['date'] ?? null;
        $endRaw = $item['end']['dateTime'] ?? $item['end']['date'] ?? null;
        $allDay = isset($item['start']['date']) && ! isset($item['start']['dateTime']);

        $startDate = null;
        $endDate = null;
        $startTime = null;
        $endTime = null;
        $tz = config('app.timezone', 'Asia/Tokyo');

        if (is_string($startRaw) && $startRaw !== '') {
            if ($allDay) {
                $startDate = substr($startRaw, 0, 10);
            } else {
                $start = Carbon::parse($startRaw)->timezone($tz);
                $startDate = $start->format('Y-m-d');
                $startTime = $start->format('H:i');
            }
        }

        if (is_string($endRaw) && $endRaw !== '') {
            if ($allDay) {
                // Google 終日の end は排他的翌日
                $end = Carbon::parse(substr($endRaw, 0, 10), $tz)->subDay();
                $endDate = $end->format('Y-m-d');
                if ($startDate && $endDate < $startDate) {
                    $endDate = $startDate;
                }
            } else {
                $end = Carbon::parse($endRaw)->timezone($tz);
                $endDate = $end->format('Y-m-d');
                $endTime = $end->format('H:i');
            }
        }

        return [
            'id' => 'gcal:'.$item['id'],
            'userId' => $userId,
            'groupId' => null,
            'context' => 'work',
            'title' => (string) ($item['summary'] ?? __('（無題）')),
            'completed' => false,
            'startDate' => $startDate,
            'endDate' => $endDate ?: $startDate,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'importance' => 'medium',
            'category' => 'task',
            'reminders' => [],
            'notifyVia' => null,
            'notifiedAt' => [],
            'googleEventId' => (string) $item['id'],
            'googleCalendarId' => $calendarId,
            'googleMeetLink' => $this->extractMeetLink($item),
            'source' => 'google',
            'htmlLink' => isset($item['htmlLink']) ? (string) $item['htmlLink'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function extractMeetLink(array $item): ?string
    {
        if (! empty($item['hangoutLink']) && is_string($item['hangoutLink'])) {
            return $item['hangoutLink'];
        }

        $entryPoints = $item['conferenceData']['entryPoints'] ?? null;
        if (! is_array($entryPoints)) {
            return null;
        }

        foreach ($entryPoints as $ep) {
            if (! is_array($ep)) {
                continue;
            }
            if (($ep['entryPointType'] ?? '') === 'video' && ! empty($ep['uri']) && is_string($ep['uri'])) {
                return $ep['uri'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $todo
     * @return array<string, mixed>
     */
    private function todoToGoogleEventPayload(array $todo, bool $withMeet = false): array
    {
        $title = trim((string) ($todo['title'] ?? ''));
        $startDate = $todo['startDate'] ?? null;
        $endDate = $todo['endDate'] ?? $startDate;
        $startTime = $todo['startTime'] ?? null;
        $endTime = $todo['endTime'] ?? null;
        $tz = config('app.timezone', 'Asia/Tokyo');

        if (! is_string($startDate) || $startDate === '') {
            throw new \InvalidArgumentException(__('仕事 ToDo を Google に同期するには日付が必要です。'));
        }
        if (! is_string($endDate) || $endDate === '') {
            $endDate = $startDate;
        }

        $payload = [
            'summary' => $title !== '' ? $title : __('（無題）'),
        ];

        if (is_string($startTime) && $startTime !== '') {
            $startDt = Carbon::parse($startDate.' '.$startTime, $tz);
            $endDt = (is_string($endTime) && $endTime !== '')
                ? Carbon::parse($endDate.' '.$endTime, $tz)
                : $startDt->copy()->addHour();
            $payload['start'] = ['dateTime' => $startDt->toRfc3339String(), 'timeZone' => $tz];
            $payload['end'] = ['dateTime' => $endDt->toRfc3339String(), 'timeZone' => $tz];
        } else {
            // 終日: end は排他的翌日
            $endExclusive = Carbon::parse($endDate, $tz)->addDay()->format('Y-m-d');
            $payload['start'] = ['date' => $startDate];
            $payload['end'] = ['date' => $endExclusive];
        }

        if ($withMeet) {
            $payload['conferenceData'] = [
                'createRequest' => [
                    'requestId' => (string) Str::uuid(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }

        return $payload;
    }
}
