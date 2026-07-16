<div class="calendar-year-view">
  @foreach($yearView['months'] as $monthBlock)
    <section class="cal-year-month">
      <h3 class="cal-year-month-title">
        <a href="{{ $buildDashboardQuery('month', sprintf('%04d-%02d-01', $monthBlock['year'], $monthBlock['month'])) }}">
          {{ $monthBlock['label'] }}
        </a>
      </h3>
      <div class="cal-year-weekdays">
        @foreach($weekdayLabels as $index => $label)
          <span class="{{ $index === 0 ? 'sun' : ($index === 6 ? 'sat' : '') }}">{{ $label }}</span>
        @endforeach
      </div>
      <div class="cal-year-days">
        @foreach($monthBlock['weeks'] as $week)
          @foreach($week as $cell)
            @php
              $hasTodos = count($cell['todos'] ?? []) > 0;
            @endphp
            <a
              href="{{ $buildDashboardQuery('day', $cell['date']) }}"
              @class([
                'cal-year-day',
                'other-month' => empty($cell['inMonth']),
                'is-today' => !empty($cell['isToday']),
                'is-holiday' => !empty($cell['isHoliday']),
                'has-events' => $hasTodos,
              ])
              title="{{ $cell['date'] }}@if(!empty($cell['holidayName'])) {{ $cell['holidayName'] }}@endif"
            >
              <span class="cal-year-daynum">{{ $cell['day'] }}</span>
              @if($hasTodos)
                <span class="cal-year-dot" aria-hidden="true"></span>
              @endif
            </a>
          @endforeach
        @endforeach
      </div>
    </section>
  @endforeach
</div>
