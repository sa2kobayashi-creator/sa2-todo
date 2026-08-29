<?php

namespace App\Services;

use App\Models\MessagingConnection;
use App\Models\Todo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReminderNotificationService
{
    public function __construct(
        private LineMessagingService $line,
        private MessengerMessagingService $messenger,
        private MessagingLinkService $links,
    ) {}

    /**
     * @return array{checked: int, sent: int, skipped: int, errors: int}
     */
    public function dispatchDueReminders(?Carbon $now = null): array
    {
        $now = ($now ?? now())->timezone(config('app.timezone'));
        $stats = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0];

        $todos = Todo::query()
            ->with('user')
            ->where('completed', false)
            ->whereNotNull('notify_via')
            ->whereIn('notify_via', ['line', 'messenger'])
            ->whereNotNull('start_date')
            ->whereBetween('start_date', [
                $now->copy()->subDays(2)->toDateString(),
                $now->copy()->addDays(2)->toDateString(),
            ])
            ->orderBy('id')
            ->get();

        foreach ($todos as $todo) {
            $stats['checked']++;
            $reminders = is_array($todo->reminders) ? $todo->reminders : [];
            if ($reminders === []) {
                $stats['skipped']++;

                continue;
            }

            $notified = is_array($todo->notified_at) ? $todo->notified_at : [];
            $changed = false;

            foreach ($reminders as $key) {
                if (! is_string($key) || $key === '') {
                    continue;
                }
                if (! empty($notified[$key])) {
                    continue;
                }

                $fireAt = $this->fireAtFor($todo, $key);
                if ($fireAt === null || $fireAt->gt($now)) {
                    continue;
                }

                $result = $this->sendReminder($todo, $key, $fireAt);
                if (! empty($result['ok'])) {
                    $notified[$key] = $now->toIso8601String();
                    $changed = true;
                    $stats['sent']++;
                } else {
                    $stats['errors']++;
                    Log::warning('Reminder send failed', [
                        'todo_id' => $todo->id,
                        'reminder' => $key,
                        'message' => $result['message'] ?? 'unknown',
                    ]);
                }
            }

            if ($changed) {
                $todo->notified_at = $notified;
                $todo->save();
            }
        }

        return $stats;
    }

    public function fireAtFor(Todo $todo, string $reminderKey): ?Carbon
    {
        $date = $todo->start_date?->format('Y-m-d');
        if (! $date) {
            return null;
        }

        $tz = (string) config('app.timezone', 'Asia/Tokyo');
        $time = trim((string) ($todo->start_time ?? ''));
        if ($time === '') {
            $time = '09:00';
        }

        try {
            $eventAt = Carbon::parse($date.' '.$time, $tz);
        } catch (\Throwable) {
            return null;
        }

        if (preg_match('/^at:(\d{2}:\d{2})$/', $reminderKey, $matches) === 1) {
            try {
                return Carbon::parse($date.' '.$matches[1], $tz);
            } catch (\Throwable) {
                return null;
            }
        }

        return match ($reminderKey) {
            'at9am' => Carbon::parse($date.' 09:00', $tz),
            '5min' => $eventAt->copy()->subMinutes(5),
            '10min' => $eventAt->copy()->subMinutes(10),
            '30min' => $eventAt->copy()->subMinutes(30),
            '1hour' => $eventAt->copy()->subHour(),
            '1day' => $eventAt->copy()->subDay(),
            default => null,
        };
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function sendReminder(Todo $todo, string $reminderKey, Carbon $fireAt): array
    {
        $user = $todo->relationLoaded('user') ? $todo->user : User::find($todo->user_id);
        if (! $user) {
            return ['ok' => false, 'message' => 'user_missing'];
        }

        $label = TodoService::REMINDER_LABELS[$reminderKey] ?? $reminderKey;
        if (preg_match('/^at:(\d{2}:\d{2})$/', $reminderKey, $matches) === 1) {
            $label = __('当日 :time', ['time' => $matches[1]]);
        }
        $when = $todo->start_date?->format('Y-m-d');
        $time = trim((string) ($todo->start_time ?? ''));
        $schedule = $when.($time !== '' ? ' '.$time : '');
        $text = sprintf(
            "【%s リマインダ】\n%s\n予定: %s\n通知: %s",
            config('app.name'),
            $todo->title,
            $schedule,
            $label
        );

        return match ($todo->notify_via) {
            'line' => $this->line->sendText($user, $text),
            'messenger' => $this->messenger->sendText($user, $text),
            default => ['ok' => false, 'message' => 'unsupported_channel'],
        };
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function sendTest(User $user, string $provider): array
    {
        $connection = $this->links->connectionFor($user, $provider);
        if (! $connection) {
            return ['ok' => false, 'message' => __('未連携です。先に連携コードで接続してください。')];
        }

        $text = __('【:app】テスト通知です。連携は正常です。', ['app' => config('app.name')]);

        return match ($provider) {
            MessagingConnection::PROVIDER_LINE => $this->line->sendText($user, $text),
            MessagingConnection::PROVIDER_MESSENGER => $this->messenger->sendText($user, $text),
            default => ['ok' => false, 'message' => 'unsupported'],
        };
    }
}
