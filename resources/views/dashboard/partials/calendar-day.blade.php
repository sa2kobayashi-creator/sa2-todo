<div class="calendar-day-view" data-date="{{ $dayView['date'] }}">
  <div class="cal-allday-row">
    <div class="cal-allday-label">終日</div>
    <div class="cal-allday-events">
      @foreach($dayView['allDay'] as $todo)
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
          data-tip-time="終日"
        >
          <span class="event-title">{{ $truncateTitle($todo['title'], 40) }}</span>
        </button>
      @endforeach
      @foreach(($dayView['cell']['notes'] ?? []) as $note)
        @php($notePalette = $noteColors[$note['color'] ?? 'default'] ?? $noteColors['default'])
        <button
          type="button"
          class="event-chip note-chip"
          data-note-id="{{ $note['id'] }}"
          style="--note-bg: {{ $notePalette['bg'] }}; --note-border: {{ $notePalette['border'] }}"
        >
          <span class="event-title">📝 {{ $truncateTitle($getNoteDisplayTitle($note), 40) }}</span>
        </button>
      @endforeach
      @if(count($dayView['allDay']) === 0 && count($dayView['cell']['notes'] ?? []) === 0)
        <span class="cal-allday-empty">終日の予定はありません</span>
      @endif
      <button type="button" class="day-add-btn cal-allday-add" data-date="{{ $dayView['date'] }}" title="ToDo を追加">+</button>
    </div>
  </div>

  <div class="cal-timed-scroll">
    <div class="cal-timed-grid">
      <div class="cal-time-gutter" aria-hidden="true">
        @foreach($dayView['hours'] as $hour)
          <div class="cal-time-slot">
            <span class="cal-time-label">{{ $hour }}時</span>
          </div>
        @endforeach
      </div>
      <div class="cal-day-canvas @if(!empty($dayView['cell']['isToday'])) is-today @endif">
        @foreach($dayView['hours'] as $hour)
          <div class="cal-hour-line" data-hour="{{ $hour }}"></div>
        @endforeach
        @foreach($dayView['timed'] as $todo)
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
            data-tip-time="{{ $todo['layoutStartLabel'] }}@if(!empty($todo['layoutEndLabel']) && $todo['layoutEndLabel'] !== $todo['layoutStartLabel'])～{{ $todo['layoutEndLabel'] }}@endif"
          >
            <span class="cal-timed-event-time">{{ $todo['layoutStartLabel'] }}</span>
            <span class="event-title">{{ $truncateTitle($todo['title'], 36) }}</span>
          </button>
        @endforeach
      </div>
    </div>
  </div>
</div>
