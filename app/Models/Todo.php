<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Todo extends Model
{
    protected $fillable = [
        'title',
        'completed',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'importance',
        'category',
        'reminders',
        'notify_via',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'reminders' => 'array',
            'notified_at' => 'array',
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'completed' => $this->completed,
            'startDate' => $this->start_date?->format('Y-m-d'),
            'endDate' => $this->end_date?->format('Y-m-d'),
            'startTime' => $this->start_time,
            'endTime' => $this->end_time,
            'importance' => $this->importance,
            'category' => $this->category,
            'reminders' => $this->reminders ?? [],
            'notifyVia' => $this->notify_via,
            'notifiedAt' => $this->notified_at ?? [],
        ];
    }
}
