<div class="calendar-week-view">
  <div class="cal-week-header">
    <div class="cal-week-gutter-spacer" aria-hidden="true"></div>
    @foreach($weekView['days'] as $day)
      <div @class(['cal-week-day-head', 'is-today' => !empty($day['isToday']), 'is-holiday' => !empty($day['isHoliday']), 'sun' => ($day['weekday'] ?? null) === 0, 'sat' => ($day['weekday'] ?? null) === 6])>
        <span class="cal-week-weekday">{{ $day['weekdayLabel'] }}</span>
        <a class="cal-week-daynum" href="{{ $buildDashboardQuery('day', $day['date']) }}" title="{{ __('日表示へ') }}">{{ $day['day'] }}</a>
        @if(!empty($day['holidayName']))
          <span class="cal-week-holiday" title="{{ $day['holidayName'] }}">{{ $day['holidayName'] }}</span>
        @endif
      </div>
    @endforeach
  </div>

  <div class="cal-allday-row cal-week-allday">
    <div class="cal-allday-label">{{ __('終日') }}</div>
    <div class="cal-week-allday-cols">
      @foreach($weekView['days'] as $day)
        <div class="cal-week-allday-col" data-date="{{ $day['date'] }}">
          <button type="button" class="day-add-btn" data-date="{{ $day['date'] }}" title="{{ __('ToDo を追加') }}">+</button>
          @foreach($day['allDay'] as $todo)
            <button
              type="button"
              @class([
                'event-chip',
                'category-'.($todo['category'] ?? 'task'),
                'importance-'.($todo['importance'] ?? 'medium'),
                'done' => ! empty($todo['completed']),
              ])
              data-todo-id="{{ $todo['id'] }}"
              data-tip-title="{{ $todo['title'] }}"
              data-tip-date="{{ $formatPeriodLabel($todo) }}"
              data-tip-time="{{ __('終日') }}"
              @if(!empty($todo['googleMeetLink'])) data-tip-meet="{{ $todo['googleMeetLink'] }}" @endif
            >
              <span class="event-title">{{ $truncateTitle($todo['title'], 18) }}</span>
              @if(!empty($todo['googleMeetLink']))
                <span class="event-meet-badge" aria-label="Google Meet">Meet</span>
              @endif
            </button>
          @endforeach
          @foreach(($day['notes'] ?? []) as $note)
            <button type="button" class="event-chip note-chip" data-note-id="{{ $note['id'] }}">
              <span class="event-title">📝 {{ $truncateTitle($getNoteDisplayTitle($note), 14) }}</span>
            </button>
          @endforeach
        </div>
      @endforeach
    </div>
  </div>

  <div class="cal-timed-scroll">
    <div class="cal-timed-grid cal-week-timed-grid">
      <div class="cal-time-gutter" aria-hidden="true">
        @foreach($weekView['hours'] as $hour)
          <div class="cal-time-slot">
            <span class="cal-time-label">{{ \App\Services\CalendarService::formatHourLabel((int) $hour) }}</span>
          </div>
        @endforeach
      </div>
      @foreach($weekView['days'] as $day)
        <div class="cal-day-canvas @if(!empty($day['isToday'])) is-today @endif" data-date="{{ $day['date'] }}">
          @foreach($weekView['hours'] as $hour)
            <div class="cal-hour-line" data-hour="{{ $hour }}"></div>
          @endforeach
          @foreach($day['timed'] as $todo)
            <button
              type="button"
              @class([
                'cal-timed-event',
                'event-chip',
                'category-'.($todo['category'] ?? 'task'),
                'importance-'.($todo['importance'] ?? 'medium'),
                'done' => ! empty($todo['completed']),
              ])
              style="top: {{ $todo['layoutTop'] }}%; height: {{ $todo['layoutHeight'] }}%;"
              data-todo-id="{{ $todo['id'] }}"
              data-tip-title="{{ $todo['title'] }}"
              data-tip-date="{{ $formatPeriodLabel($todo) }}"
              data-tip-time="{{ $todo['layoutStartLabel'] }}"
              @if(!empty($todo['googleMeetLink'])) data-tip-meet="{{ $todo['googleMeetLink'] }}" @endif
            >
              <span class="cal-timed-event-time">{{ $todo['layoutStartLabel'] }}</span>
              <span class="event-title">{{ $truncateTitle($todo['title'], 20) }}</span>
              @if(!empty($todo['googleMeetLink']))
                <span class="event-meet-badge" aria-label="Google Meet">Meet</span>
              @endif
            </button>
          @endforeach
        </div>
      @endforeach
    </div>
  </div>
</div>
