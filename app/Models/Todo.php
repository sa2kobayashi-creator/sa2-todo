<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Todo extends Model
{
    protected $fillable = [
        'user_id',
        'group_id',
        'context',
        'title',
        'memo',
        'completed',
        'keep_on_server',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'importance',
        'category',
        'reminders',
        'notify_via',
        'notified_at',
        'google_event_id',
        'google_calendar_id',
        'google_meet_link',
        'google_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'keep_on_server' => 'boolean',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'reminders' => 'array',
            'notified_at' => 'array',
            'google_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'groupId' => $this->group_id,
            'groupName' => $this->relationLoaded('group') ? $this->group?->name : null,
            'context' => $this->context ?: 'personal',
            'title' => $this->title,
            'memo' => $this->memo ?? '',
            'completed' => $this->completed,
            'startDate' => $this->start_date?->format('Y-m-d'),
            'endDate' => $this->end_date?->format('Y-m-d'),
            'startTime' => $this->start_time,
            'endTime' => $this->end_time,
            'importance' => $this->importance,
            'category' => $this->category,
            'reminders' => $this->reminders ?? [],
            'reminderTime' => \App\Services\TodoService::reminderTimeFromList($this->reminders ?? []),
            'notifyVia' => $this->notify_via,
            'notifiedAt' => $this->notified_at ?? [],
            'googleEventId' => $this->google_event_id,
            'googleCalendarId' => $this->google_calendar_id,
            'googleMeetLink' => $this->google_meet_link,
            'googleSyncedAt' => $this->google_synced_at?->toIso8601String(),
            'source' => $this->google_event_id ? 'google' : 'local',
        ];
    }
}
