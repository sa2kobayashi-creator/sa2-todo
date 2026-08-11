@php
  /** @var array<string, mixed> $home */
  $counts = $home['counts'] ?? [];
  $links = $home['links'] ?? [];
  $nextActions = $home['nextActions'] ?? [];
  $calendar = $home['calendar'] ?? ['connected' => false, 'events' => []];
  $photos = $home['photos'] ?? ['items' => [], 'mode' => 'recent', 'title' => ''];
  $pinnedNotes = $home['pinnedNotes'] ?? [];
@endphp

<section class="dash-home" aria-label="{{ __('今日') }}">
  <header class="dash-home-greeting">
    <p class="dash-home-date">{{ $home['dateLabel'] ?? '' }}</p>
    <h1 class="dash-home-hello">{{ $home['greetingLine'] ?? '' }}</h1>
    <ul class="dash-home-counts" aria-label="{{ __('今日の概要') }}">
      <li>
        <span class="dash-home-count-icon" aria-hidden="true">📅</span>
        <span class="dash-home-count-num">{{ (int) ($counts['events'] ?? 0) }}</span>
        <span class="dash-home-count-label">{{ __('今日の予定') }}</span>
      </li>
      <li>
        <span class="dash-home-count-icon" aria-hidden="true">✓</span>
        <span class="dash-home-count-num">{{ (int) ($counts['todos'] ?? 0) }}</span>
        <span class="dash-home-count-label">{{ __('今日のTodo') }}</span>
      </li>
    </ul>
  </header>

  <div class="dash-home-grid">
    <section class="dash-home-card dash-home-next" aria-labelledby="dash-home-next-title">
      <div class="dash-home-card-head">
        <h2 id="dash-home-next-title">{{ __('次にやること') }}</h2>
      </div>
      @if(count($nextActions) === 0)
        <p class="dash-home-empty">{{ __('今日の ToDo はありません') }}</p>
      @else
        <ul class="dash-home-list">
          @foreach($nextActions as $item)
            <li @class(['is-overdue' => !empty($item['isOverdue'])])>
              <span class="dash-home-list-time">{{ $item['timeLabel'] ?? '' }}</span>
              <a class="dash-home-list-title" href="{{ $item['url'] ?? ($links['todosToday'] ?? '/todos') }}">{{ $item['title'] ?? '' }}</a>
            </li>
          @endforeach
        </ul>
      @endif
      <div class="dash-home-card-foot">
        <a class="dash-home-action" href="{{ $links['todosNew'] ?? '/todos' }}">＋ {{ __('Todoを追加') }}</a>
        <a class="dash-home-more" href="{{ $links['todosToday'] ?? '/todos?today=1' }}">{{ __('今日のTodo') }} →</a>
      </div>
    </section>

    <section class="dash-home-card dash-home-events" aria-labelledby="dash-home-events-title">
      <div class="dash-home-card-head">
        <h2 id="dash-home-events-title">{{ __('今日の予定') }}</h2>
        @if(!empty($calendar['nextInLabel']))
          <p class="dash-home-countdown">{{ __('次の予定まで :label', ['label' => $calendar['nextInLabel']]) }}</p>
        @endif
      </div>
      @if(empty($calendar['connected']))
        <p class="dash-home-empty">{{ __('Googleカレンダーは未連携です') }}</p>
        <div class="dash-home-card-foot">
          <a class="dash-home-action" href="{{ $links['googleCalendarConnect'] ?? '/settings?section=integration#google-calendar' }}">{{ __('Googleカレンダーを連携') }}</a>
        </div>
      @elseif(count($calendar['events'] ?? []) === 0)
        <p class="dash-home-empty">{{ __('今日の予定はありません') }}</p>
        <div class="dash-home-card-foot">
          <a class="dash-home-more" href="{{ $links['googleCalendar'] ?? 'https://calendar.google.com/calendar/r/day' }}" target="_blank" rel="noopener noreferrer">Google Calendar →</a>
        </div>
      @else
        <ul class="dash-home-list">
          @foreach($calendar['events'] as $event)
            <li @class(['is-next' => ($calendar['next']['id'] ?? null) === ($event['id'] ?? null)])>
              <span class="dash-home-list-time">{{ $event['timeLabel'] ?? '' }}</span>
              @if(!empty($event['htmlLink']))
                <a class="dash-home-list-title" href="{{ $event['htmlLink'] }}" target="_blank" rel="noopener noreferrer">{{ $event['title'] ?? '' }}</a>
              @else
                <span class="dash-home-list-title">{{ $event['title'] ?? '' }}</span>
              @endif
            </li>
          @endforeach
        </ul>
        <div class="dash-home-card-foot">
          <a class="dash-home-more" href="{{ $links['googleCalendar'] ?? 'https://calendar.google.com/calendar/r/day' }}" target="_blank" rel="noopener noreferrer">Google Calendar →</a>
        </div>
      @endif
    </section>

    <section class="dash-home-card dash-home-photos" aria-labelledby="dash-home-photos-title">
      <div class="dash-home-card-head">
        <h2 id="dash-home-photos-title">{{ __('Photos') }}</h2>
        <p class="dash-home-card-sub">
          {{ $photos['title'] ?? __('最近の写真') }}
          @if(!empty($photos['subtitle']))
            <span class="dash-home-card-note">{{ $photos['subtitle'] }}</span>
          @endif
        </p>
      </div>
      @if(count($photos['items'] ?? []) === 0)
        <p class="dash-home-empty">{{ __('まだ写真がありません') }}</p>
      @else
        <ul class="dash-home-photo-grid">
          @foreach($photos['items'] as $photo)
            <li>
              <a href="{{ $links['photos'] ?? '/photos' }}" class="dash-home-photo-link" title="{{ $photo['originalName'] ?? '' }}">
                <img
                  src="{{ $photo['thumbUrl'] ?? ($photo['url'] ?? '') }}"
                  alt=""
                  loading="lazy"
                  decoding="async"
                />
              </a>
            </li>
          @endforeach
        </ul>
      @endif
      <div class="dash-home-card-foot">
        @if(((int) ($photos['addedToday'] ?? 0)) > 0)
          <span class="dash-home-card-note">{{ __('今日追加 :count枚', ['count' => (int) $photos['addedToday']]) }}</span>
        @endif
        <a class="dash-home-more" href="{{ $links['photos'] ?? '/photos' }}">{{ __('Photosを見る') }} →</a>
      </div>
    </section>
  </div>

  <footer class="dash-home-footer">
    @if(count($pinnedNotes) > 0)
      <div class="dash-home-pins">
        <span class="dash-home-pins-label">{{ __('ピン留めメモ') }}</span>
        <ul class="dash-home-pins-list">
          @foreach($pinnedNotes as $note)
            <li><a href="{{ $note['url'] ?? '/notes' }}">{{ $note['title'] ?? '' }}</a></li>
          @endforeach
        </ul>
      </div>
    @endif
    <nav class="dash-home-links" aria-label="{{ __('ショートカット') }}">
      <a href="{{ $links['notes'] ?? '/notes' }}">{{ __('メモ') }}</a>
      <span aria-hidden="true">｜</span>
      <a href="{{ $links['aiSettings'] ?? '/settings?section=ai' }}">{{ __('AI設定') }}</a>
      <span aria-hidden="true">｜</span>
      <a href="{{ $links['map'] ?? '/map' }}">{{ __('Map') }}</a>
      <span aria-hidden="true">｜</span>
      <a href="{{ $links['transit'] ?? '/transit' }}">{{ __('路線検索') }}</a>
    </nav>
  </footer>
</section>
