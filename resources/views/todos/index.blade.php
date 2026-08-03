<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('Todo') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ @filemtime(public_path('app.css')) ?: time() }}" />
    <script src="{{ asset('voice-entry.js') }}" defer></script>
  </head>
  <body>
    @include('partials.header', ['active' => 'todos'])
    <main class="page-main">
      @if($notice)<div class="banner notice">{{ $notice }}</div>@endif
      @if($error)<div class="banner error">{{ $error }}</div>@endif
      @if(!empty($appContextIsWork))
        <div class="banner notice">
          {{ __('仕事モード: プライベートの ToDo は表示されません。') }}
          @if(($displayMode ?? '') === 'calendar')
            @if(empty($googleCalendarConnected))
              <a href="/settings?section=integration#google-calendar">{{ __('Googleカレンダーを連携') }}</a>
            @else
              {{ __('選択中の Google カレンダー予定も表示します。') }}
              <a href="/settings?section=integration#google-calendar">{{ __('カレンダー選択') }}</a>
            @endif
          @endif
        </div>
      @endif

      <div class="panel">
        <details class="app-accordion" data-accordion-key="todos-add">
        <summary class="app-accordion-summary">
          <h2>{{ __('ToDo を追加（複数行可）') }}</h2>
          <span class="app-accordion-caret" aria-hidden="true">▾</span>
        </summary>
        <div class="app-accordion-body">
        <p class="hint">{{ __('改行ごとに1件ずつ登録。日付は単日または期間を選べます。期間モードでは曜日を指定すると、該当する日ごとに ToDo を作成します。') }}@if(!empty($canSettings)) {{ __('定休日の設定は') }} <a href="/settings?section=holidays#weekday-holidays">{{ __('設定 → 休日設定') }}</a> {{ __('で行います。') }}@endif</p>
        <form class="add" method="post" action="/todos" id="add-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $listReturnTo }}" />
          <textarea id="titles-input" name="titles" placeholder="{{ __('買い物に行く') }}&#10;{{ __('レポートを書く') }}" required></textarea>
          <div class="add-form-grid">
            <div class="form-grid-row form-grid-row-top">
              <span class="field-label">{{ __('指定方法') }}</span>
              <label class="radio-inline">
                <input type="radio" name="dateMode" value="single" checked />
                {{ __('単日') }}
              </label>
              <label class="radio-inline">
                <input type="radio" name="dateMode" value="range" />
                {{ __('期間') }}
              </label>
              <span class="form-grid-spacer"></span>
            </div>
            <div class="form-grid-row form-grid-row-labels">
              <span class="field-label" id="label-start">{{ __('開始日') }}</span>
              <span class="field-label" id="label-end">{{ __('終了日') }}</span>
              <span class="field-label">{{ __('重要度') }}</span>
              <span class="field-label">{{ __('ステータス') }}</span>
            </div>
            <div class="form-grid-row form-grid-row-inputs">
              <div class="form-grid-cell">
                <input type="date" name="startDate" id="start-date" value="{{ $defaultStartDate }}" aria-label="{{ __('開始日') }}" @if(!empty($appContextIsWork)) required @endif />
              </div>
              <div class="form-grid-cell" id="cell-end">
                <input type="date" name="endDate" id="end-date" value="{{ $defaultEndDate }}" aria-label="{{ __('終了日') }}" />
              </div>
              <div class="form-grid-cell">
                <select name="importance" id="importance">
                  @foreach($importanceLabels as $value => $label)
                    <option value="{{ $value }}" @selected($value === 'medium')>{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="form-grid-cell">
                <select name="category" id="category">
                  @foreach($categoryLabels as $value => $label)
                    <option value="{{ $value }}" @selected($value === 'task')>{{ $label }}</option>
                  @endforeach
                </select>
              </div>
            </div>
            <fieldset class="weekday-checkboxes add-weekday-panel date-panel-hidden" id="add-weekday-panel">
              <legend>{{ __('登録する曜日（期間内の該当日のみ）') }}</legend>
              <div class="weekday-check-row">
                @foreach($weekdayLabels as $index => $label)
                  <label class="weekday-check">
                    <input type="checkbox" name="weekdays" value="{{ $index }}" class="add-weekday-input" data-label="{{ $label }}" />
                    {{ $label }}
                  </label>
                @endforeach
              </div>
              <label class="weekday-check exclude-holiday-check">
                <input type="checkbox" id="exclude-holidays" class="add-exclude-holidays" checked />
                <input type="hidden" name="excludeHolidays" id="exclude-holidays-value" value="1" />
                {{ __('祝日を除く（日本の祝日のみ）') }}
              </label>
              <label class="weekday-check exclude-holiday-check">
                <input type="checkbox" id="exclude-closures" class="add-exclude-closures" checked />
                <input type="hidden" name="excludeClosures" id="exclude-closures-value" value="1" />
                {{ __('休業日を除く（会社休業日・定休日）') }}
              </label>
              <p class="hint inline-hint">{{ __('未選択の場合は、期間全体を1件の ToDo として登録します。「祝日を除く」は日本の祝日（例: 山の日）のみ、「休業日を除く」は会社休業日・定休日のみです（PH祝日は除きません）。既に登録済みの予定は自動では消えません。') }}</p>
            </fieldset>
            <div class="schedule-option">
              <label class="schedule-toggle">
                <input type="checkbox" id="enable-time-range" />
                {{ __('時間帯を追加') }}
              </label>
              <div class="time-range-panel date-panel-hidden" id="time-range-panel">
                <div class="time-range-inputs">
                  <input type="time" name="startTime" id="todo-start-time" aria-label="{{ __('開始時刻') }}" disabled />
                  <span class="time-range-separator" aria-hidden="true">～</span>
                  <input type="time" name="endTime" id="todo-end-time" aria-label="{{ __('終了時刻') }}" disabled />
                </div>
              </div>
            </div>
            <fieldset class="reminder-checkboxes">
              <legend>{{ __('通知タイミング') }}</legend>
              <div class="reminder-check-row">
                @foreach($reminderOptions as $key)
                  <label class="reminder-check">
                    <input type="checkbox" name="reminders" value="{{ $key }}" />
                    {{ $reminderLabels[$key] }}
                  </label>
                @endforeach
              </div>
            </fieldset>
            <fieldset class="notify-via-fieldset">
              <legend>{{ __('通知方法') }}</legend>
              <div class="notify-via-row">
                @foreach($notifyViaOptions as $key)
                  <label class="notify-via-option">
                    <input type="radio" name="notifyVia" value="{{ $key }}" />
                    {{ $notifyViaLabels[$key] }}
                  </label>
                @endforeach
              </div>
              <p class="hint inline-hint">{{ __('通知タイミングを選ぶ場合は、いずれか1つを選択してください。') }}</p>
            </fieldset>
            @unless(!empty($appContextIsWork))
            <div class="form-grid-row form-grid-row-labels">
              <span class="field-label">{{ __('共有先') }}</span>
            </div>
            <div class="form-grid-row form-grid-row-inputs">
              <div class="form-grid-cell">
                <select name="groupId" id="todo-group-id" aria-label="{{ __('共有先') }}">
                  <option value="">{{ __('個人（自分のみ）') }}</option>
                  @foreach($approvedGroups ?? [] as $group)
                    <option value="{{ $group['id'] }}">{{ $group['name'] }}</option>
                  @endforeach
                </select>
              </div>
            </div>
            @else
            <p class="hint">{{ __('仕事モードではグループ共有は使えません。日付付き ToDo は Google カレンダーへ同期されます。') }}</p>
            @endunless
          </div>
          <label class="split-option">
            <input type="checkbox" id="split-by-line" name="splitByLine" value="1" checked />
            {{ __('改行ごとに別の ToDo として登録する') }}
          </label>
          <div class="preview" id="line-preview" hidden>
            <h3>{{ __('登録プレビュー') }}（<span id="preview-count">0</span>{{ __('件') }}）</h3>
            <ol id="preview-list"></ol>
          </div>
          <div class="form-actions">
            <button type="submit">{{ __('追加') }}</button>
          </div>
        </form>

        @include('partials.voice-entry', [
          'idPrefix' => 'todo',
          'voiceAiReady' => $voiceAiReady ?? false,
          'voiceAiProvider' => $voiceAiProvider ?? null,
          'placeholder' => __('例: 明日買い物に行く、重要'),
        ])
        </div>
        </details>
      </div>

      <div class="panel" id="todo-list-panel">
        <details class="app-accordion todos-filter-accordion" data-accordion-key="todos-filter" @if(($displayMode ?? 'calendar') !== 'calendar') open @endif>
          <summary class="app-accordion-summary">
            <span>{{ __('検索・絞り込み') }}</span>
            <span class="app-accordion-caret" aria-hidden="true">▾</span>
          </summary>
          <div class="app-accordion-body">
        <form class="filter-bar" method="get" action="/todos#todo-list-panel" id="filter-form">
          @if(($displayMode ?? 'calendar') === 'list')
            <input type="hidden" name="display" value="list" />
          @endif
          <div class="filter-group filter-group-period">
            <label>{{ __('期間') }}@if(($filters['scope'] ?? '') === 'today') <span class="filter-scope-hint">{{ __('（今日で表示中）') }}</span>@endif</label>
            <div class="filter-period-mode" role="group" aria-label="{{ __('期間の指定方法') }}">
              <label class="radio-inline filter-period-mode-option">
                <input type="radio" name="periodMode" value="month" @checked(($filters['periodMode'] ?? $periodMode ?? 'month') === 'month') />
                {{ __('月') }}
              </label>
              <label class="radio-inline filter-period-mode-option">
                <input type="radio" name="periodMode" value="year" @checked(($filters['periodMode'] ?? '') === 'year' || ($filters['scope'] ?? '') === 'year' || ($periodMode ?? '') === 'year') />
                {{ __('年') }}
              </label>
            </div>
            <input
              type="month"
              name="period"
              id="filter-period-month"
              class="filter-period-input"
              value="{{ ($filters['scope'] ?? '') === 'today' ? '' : ($periodValue ?? '') }}"
              @if(($filters['scope'] ?? '') === 'today') placeholder="{{ __('未設定') }}" @endif
            />
            <input
              type="number"
              name="periodYear"
              id="filter-period-year"
              class="filter-period-input"
              min="1970"
              max="2100"
              step="1"
              value="{{ $periodYearValue ?? $filters['year'] }}"
              aria-label="{{ __('年') }}"
            />
          </div>
          <div class="filter-group">
            <label>{{ __('完了') }}</label>
            <select name="status">
              <option value="all" @selected(($filters['status'] ?? 'all') === 'all')>{{ __('すべて') }}</option>
              <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>{{ __('未完了') }}</option>
              <option value="done" @selected(($filters['status'] ?? '') === 'done')>{{ __('完了') }}</option>
            </select>
          </div>
          <div class="filter-group filter-group-categories">
            <label for="filter-category-trigger">{{ __('ステータス') }}</label>
            <div class="filter-dropdown" id="filter-category-dropdown">
              <button
                type="button"
                class="filter-dropdown-trigger"
                id="filter-category-trigger"
                aria-expanded="false"
                aria-haspopup="listbox"
                aria-controls="filter-category-panel"
              >
                <span class="filter-dropdown-label" id="filter-category-label">
                  @if(empty($filters['categories'])){{ __('すべて') }}@else
                    {{ collect($filters['categories'] ?? [])->map(fn($v) => $categoryLabels[$v] ?? $v)->join('、') }}
                  @endif
                </span>
                <span class="filter-dropdown-caret" aria-hidden="true">▼</span>
              </button>
              <div class="filter-dropdown-panel" id="filter-category-panel" role="listbox" hidden>
                @foreach($categoryLabels as $value => $label)
                  <label class="filter-dropdown-check">
                    <input
                      type="checkbox"
                      name="category"
                      value="{{ $value }}"
                      class="filter-category-cb"
                      data-label="{{ $label }}"
                      @checked(in_array($value, $filters['categories'] ?? [], true))
                    />
                    {{ $label }}
                  </label>
                @endforeach
              </div>
            </div>
          </div>
          <div class="filter-group">
            <label>{{ __('重要度') }}</label>
            <select name="importance">
              <option value="all" @selected(($filters['importance'] ?? 'all') === 'all')>{{ __('すべて') }}</option>
              @foreach($importanceLabels as $value => $label)
                <option value="{{ $value }}" @selected(($filters['importance'] ?? '') === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="filter-bar-actions">
            <button type="submit" class="secondary">{{ __('絞り込み') }}</button>
            <a
              href="{{ $todayFilterHref }}"
              class="filter-today-btn {{ ($filters['scope'] ?? '') === 'today' ? 'active' : '' }}"
              id="filter-today-btn"
            >{{ __('今日') }}</a>
            <a
              href="{{ $clearFiltersHref ?? '/todos#todo-list-panel' }}"
              class="filter-clear-btn"
              id="filter-clear-btn"
            >{{ __('クリア') }}</a>
          </div>
        </form>
        @if(($filters['scope'] ?? '') === 'today' && !empty($filters['todayDate']))
          <p class="hint filter-today-note">{{ __('表示中:') }} {{ $filters['todayDate'] }} {{ __('の予定') }}</p>
        @elseif(($filters['scope'] ?? '') === 'year')
          <p class="hint filter-period-note">{{ __('表示中:') }} {{ $filters['year'] }}{{ __('年') }}</p>
        @endif
          </div>
        </details>

        <input type="hidden" id="bulk-return-to" value="{{ $listReturnTo }}" />

        <div class="list-toolbar">
          <div class="list-toolbar-title-row">
            <h2>
              @if(($displayMode ?? 'list') === 'calendar')
                {{ __('カレンダー') }}（{{ $periodLabel ?? ($calendarYear.__('年').$calendarMonth.__('月')) }}）
              @else
                {{ __('一覧') }}（{{ $pagination['total'] }}{{ __('件') }}）
              @endif
            </h2>
            <div class="todos-display-toggle" role="group" aria-label="{{ __('表示切替') }}">
              <a href="{{ $buildTodosQuery(['display' => 'list']) }}#todo-list-panel" class="{{ ($displayMode ?? 'calendar') === 'list' ? 'is-active' : '' }}">{{ __('一覧') }}</a>
              <a href="{{ $buildTodosQuery(['display' => null]) }}#todo-list-panel" class="{{ ($displayMode ?? 'calendar') === 'calendar' ? 'is-active' : '' }}">{{ __('カレンダー') }}</a>
            </div>
          </div>
          @if(($displayMode ?? 'list') === 'list' && ($pagination['total'] ?? 0) > 0)
            <span class="todo-page-summary">{{ $pagination['total'] }}{{ __('件中') }} {{ ($pagination['page'] - 1) * $pagination['perPage'] + 1 }}〜{{ min($pagination['page'] * $pagination['perPage'], $pagination['total']) }}{{ __('件を表示') }}</span>
          @endif
          @if(($displayMode ?? 'list') === 'list')
          <div class="bulk-actions">
            <button type="button" class="secondary" id="select-all-btn">{{ __('全選択') }}</button>
            <button type="button" class="secondary bulk-btn" data-bulk-url="/todos/bulk/complete">{{ __('一括完了') }}</button>
            <button type="button" class="secondary bulk-btn" data-bulk-url="/todos/bulk/uncomplete">{{ __('一括で未完了') }}</button>
            <button type="button" class="danger bulk-btn" data-bulk-url="/todos/bulk/delete" data-confirm="{{ __('選択した ToDo を削除しますか？') }}">{{ __('一括削除') }}</button>
            <button type="button" class="secondary bulk-btn" data-bulk-url="/todos/bulk/duplicate">{{ __('コピー') }}</button>
          </div>
          @else
            <p class="hint inline-hint calendar-edit-hint">{{ __('予定をクリックすると詳細を編集できます。ドラッグで日付を変更できます。') }}</p>
          @endif
        </div>

        @if(($displayMode ?? 'calendar') === 'calendar')
          <div class="todos-calendar-panel calendar-shell" data-calendar-view="{{ $view ?? 'month' }}">
            @include('partials.calendar-nav-toolbar', ['showMemoTodoActions' => false])

            @if(($view ?? 'month') === 'day')
              @include('dashboard.partials.calendar-day')
            @elseif(($view ?? 'month') === 'week')
              @include('dashboard.partials.calendar-week')
            @elseif(($view ?? 'month') === 'year')
              @include('dashboard.partials.calendar-year')
            @else
            <div class="calendar-weekdays">
              @foreach($weekdayLabels as $index => $label)
                <div class="calendar-weekday {{ $index === 0 ? 'sun' : ($index === 6 ? 'sat' : '') }}">{{ $label }}</div>
              @endforeach
            </div>
            <div class="calendar-body">
              @foreach($weeks as $week)
                <div class="calendar-week">
                  @foreach($week as $cell)
                    @php
                      $cellNotes = $cell['notes'] ?? [];
                      $cellData = $limitTodosForCell($cell['todos'] ?? [], 6);
                      $holidayClass = !empty($cell['isHoliday']) ? 'is-holiday is-holiday-'.($cell['holidaySource'] ?? 'national') : '';
                      $eventCount = count($cell['todos'] ?? []);
                    @endphp
                    <div
                      class="calendar-day {{ !empty($cell['inMonth']) ? '' : 'other-month' }} {{ !empty($cell['isToday']) ? 'today' : '' }} {{ count($cellNotes) ? 'has-notes' : '' }} {{ $holidayClass }}"
                      data-date="{{ $cell['date'] }}"
                    >
                      <div class="day-header">
                        <span class="day-num">{{ $cell['day'] }}</span>
                        @if($eventCount > 0)
                          <span class="day-event-count" aria-hidden="true">{{ min($eventCount, 99) }}</span>
                        @endif
                        @if(count($cellNotes) > 0)
                          <a
                            class="day-note-badge"
                            href="/notes?date={{ $cell['date'] }}"
                            title="{{ __('メモ') }} {{ count($cellNotes) }}{{ __('件') }}"
                          >
                            <span class="day-note-badge-icon" aria-hidden="true">📝</span>
                            @if(count($cellNotes) > 1)
                              <span class="day-note-badge-count">{{ count($cellNotes) }}</span>
                            @endif
                          </a>
                        @endif
                      </div>
                      @if(!empty($cell['holidayName']))
                        <div class="holiday-label" title="{{ $cell['holidayName'] }}">{{ $cell['holidayName'] }}</div>
                      @endif
                      <div class="day-events">
                        @foreach($cellData['visible'] as $todo)
                          @php
                            $todoId = $todo['id'] ?? null;
                            $isRemoteGoogle = is_string($todoId) && str_starts_with((string) $todoId, 'gcal:');
                            $chipTitle = ($todo['title'] ?? '').'（'.$formatPeriodLabel($todo).'）';
                            if (! empty($todo['googleMeetLink'])) {
                              $chipTitle .= ' · Google Meet';
                            }
                          @endphp
                          <button
                            type="button"
                            class="event-chip category-{{ $todo['category'] ?? 'task' }} importance-{{ $todo['importance'] ?? 'medium' }} {{ !empty($todo['completed']) ? 'done' : '' }}"
                            title="{{ $chipTitle }}"
                            draggable="{{ $isRemoteGoogle ? 'false' : 'true' }}"
                            data-todo-id="{{ $todo['id'] }}"
                          >
                            <span class="event-title">{{ $truncateTitle($todo['title'], 18) }}</span>
                            @if(!empty($todo['googleMeetLink']))
                              <span class="event-meet-badge" aria-label="Google Meet">Meet</span>
                            @endif
                          </button>
                        @endforeach
                        @if(($cellData['hiddenCount'] ?? 0) > 0)
                          <span class="event-more">{{ __('他') }} {{ $cellData['hiddenCount'] }} {{ __('件') }}</span>
                        @endif
                      </div>
                    </div>
                  @endforeach
                </div>
              @endforeach
            </div>
            @endif
          </div>
          @if(($view ?? 'month') === 'month')
          <div class="calendar-height-resizer" data-cal-height-resizer hidden>
            <span class="calendar-height-resizer-bar" aria-hidden="true"></span>
            <span class="calendar-height-resizer-label">{{ __('下にドラッグして高さを調整') }}</span>
          </div>

          <section class="mobile-month-agenda panel todos-mobile-agenda" aria-label="{{ $calendarMonth }}{{ __('月の予定一覧') }}">
            <h3>{{ $calendarMonth }}{{ __('月の予定') }}（{{ count($monthAgenda) }}{{ __('件') }}）</h3>
            @if(count($monthAgenda) === 0)
              <p class="hint">{{ __('今月の予定はありません。') }}</p>
            @else
              <ul class="mobile-agenda-list">
                @foreach($monthAgenda as $item)
                  @if(($item['kind'] ?? '') === 'note')
                    <li>
                      <a class="mobile-agenda-item mobile-agenda-note" href="/notes?date={{ $getNoteRegisteredDate($item['note']) }}">
                        <span class="mobile-agenda-date">{{ $getNoteRegisteredDate($item['note']) }}</span>
                        <span class="mobile-agenda-title">📝 {{ $getNoteDisplayTitle($item['note']) }}</span>
                        <span class="mobile-agenda-meta">{{ __('メモ') }}</span>
                      </a>
                    </li>
                  @elseif(($item['kind'] ?? '') === 'todo')
                    @php $todo = $item['todo']; @endphp
                    <li @class(['done' => !empty($todo['completed'])])>
                      <button type="button" class="mobile-agenda-item" data-todo-id="{{ $todo['id'] }}">
                        <span class="mobile-agenda-date">{{ $formatPeriodLabel($todo) }}</span>
                        <span class="mobile-agenda-title">
                          {{ $todo['title'] }}
                          @if(!empty($todo['googleMeetLink']))
                            <span class="event-meet-badge" aria-label="Google Meet">Meet</span>
                          @endif
                        </span>
                        <span class="mobile-agenda-meta">
                          @if(!empty($todo['startTime']))
                            {{ $todo['startTime'] }}@if(!empty($todo['endTime']) && $todo['endTime'] !== $todo['startTime'])～{{ $todo['endTime'] }}@endif
                          @else
                            {{ __('終日') }}
                          @endif
                        </span>
                      </button>
                    </li>
                  @endif
                @endforeach
              </ul>
            @endif
          </section>
          @endif

          @include('partials.todo-edit-modal', ['modalReturnTo' => $listReturnTo])

          <script>
            (function () {
              const TODO_ITEMS = {!! \Illuminate\Support\Js::from($todosForJs ?? []) !!};
              const OPEN_EDIT_ID = {!! \Illuminate\Support\Js::from(($editId ?? null) ? (string) $editId : null) !!};
              const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
              const todoModal = document.getElementById('todo-modal');
              const editForm = document.getElementById('todo-edit-form');
              const deleteForm = document.getElementById('todo-delete-form');
              const todoModalTitle = document.getElementById('todo-modal-title');
              const todoModalSaveBtn = document.getElementById('todo-modal-save');
              const todoModalDeleteBtn = document.getElementById('todo-modal-delete');
              const todoModalCopyBtn = document.getElementById('todo-modal-copy');
              const modalCompletedLabel = document.getElementById('modal-completed-label');
              const modalStartDateText = document.getElementById('modal-start-date-text');
              const modalEndDateLabel = document.getElementById('modal-end-date-label');
              const modalDateModeSingle = document.getElementById('modal-date-mode-single');
              const modalDateModeRange = document.getElementById('modal-date-mode-range');
              let editingTodoId = null;
              let todoModalMode = 'edit';

              function findTodo(id) {
                const key = String(id);
                return TODO_ITEMS.find((item) => String(item.id) === key);
              }

              function escapeHtml(text) {
                return String(text)
                  .replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/"/g, '&quot;')
              }

              function closeTodoModal() {
                if (todoModal) todoModal.hidden = true;
              }

              function syncModalDateMode(mode) {
                const isSingle = mode === 'single';
                if (modalDateModeSingle) modalDateModeSingle.checked = isSingle;
                if (modalDateModeRange) modalDateModeRange.checked = !isSingle;
                if (modalStartDateText) {
                  modalStartDateText.textContent = isSingle ? @json(__('日付')) : @json(__('開始日'));
                }
                if (modalEndDateLabel) modalEndDateLabel.classList.toggle('date-panel-hidden', isSingle);
                if (isSingle) {
                  const start = editForm?.querySelector('#modal-start-date')?.value;
                  const endInput = editForm?.querySelector('#modal-end-date');
                  if (endInput && start) endInput.value = start;
                }
              }

              function fillTodoModalFields(todo) {
                if (!editForm) return;
                const titleEl = editForm.querySelector('[name=title]');
                if (titleEl) titleEl.value = todo.title || '';
                const startDateEl = editForm.querySelector('#modal-start-date');
                const endDateEl = editForm.querySelector('#modal-end-date');
                if (startDateEl) startDateEl.value = todo.startDate || '';
                if (endDateEl) endDateEl.value = todo.endDate || todo.startDate || '';
                const enableTimeRange = editForm.querySelector('#modal-enable-time-range');
                const timeRangePanel = editForm.querySelector('#modal-time-range-panel');
                const startTimeInput = editForm.querySelector('#modal-start-time');
                const endTimeInput = editForm.querySelector('#modal-end-time');
                const hasTime = Boolean(todo.startTime);
                if (enableTimeRange) enableTimeRange.checked = hasTime;
                timeRangePanel?.classList.toggle('date-panel-hidden', !hasTime);
                if (startTimeInput) {
                  startTimeInput.disabled = !hasTime;
                  startTimeInput.value = todo.startTime || '';
                }
                if (endTimeInput) {
                  endTimeInput.disabled = !hasTime;
                  endTimeInput.value = todo.endTime || '';
                }
                const importanceEl = editForm.querySelector('#modal-importance');
                const categoryEl = editForm.querySelector('#modal-category');
                const completedEl = editForm.querySelector('[name=completed], [data-completed-input]');
                if (importanceEl) importanceEl.value = todo.importance || 'medium';
                if (categoryEl) categoryEl.value = todo.category || 'task';
                if (completedEl) completedEl.checked = !!todo.completed;
                const isSingle = !todo.startDate || !todo.endDate || todo.startDate === todo.endDate;
                syncModalDateMode(isSingle ? 'single' : 'range');

                const meetEl = document.getElementById('modal-meet-link');
                if (meetEl) {
                  if (todo.googleMeetLink) {
                    meetEl.hidden = false;
                    const label = @json(__('Google Meet'));
                    const url = escapeHtml(todo.googleMeetLink);
                    meetEl.innerHTML = label + ': <a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
                  } else {
                    meetEl.hidden = true;
                    meetEl.textContent = '';
                  }
                }
                const htmlEl = document.getElementById('modal-html-link');
                if (htmlEl) {
                  if (todo.htmlLink) {
                    htmlEl.hidden = false;
                    const label = @json(__('Google カレンダー'));
                    const url = escapeHtml(todo.htmlLink);
                    htmlEl.innerHTML = label + ': <a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
                  } else {
                    htmlEl.hidden = true;
                    htmlEl.textContent = '';
                  }
                }
              }

              function setTodoModalMode(mode) {
                todoModalMode = mode;
                const isCopy = mode === 'copy';
                if (todoModalTitle) {
                  todoModalTitle.textContent = isCopy ? @json(__('ToDo をコピーして追加')) : @json(__('ToDo 編集'));
                }
                if (todoModalDeleteBtn) todoModalDeleteBtn.hidden = isCopy;
                if (todoModalCopyBtn) todoModalCopyBtn.hidden = isCopy;
                if (modalCompletedLabel) modalCompletedLabel.classList.toggle('date-panel-hidden', isCopy);
                if (isCopy) {
                  editForm.action = '/todos';
                  editForm.querySelector('[name=completed]')?.removeAttribute('name');
                  editForm.querySelector('[name=completed]')?.setAttribute('data-completed-input', '1');
                } else {
                  const completed = editForm.querySelector('[data-completed-input], [name=completed]');
                  if (completed) {
                    completed.setAttribute('name', 'completed');
                    completed.removeAttribute('data-completed-input');
                  }
                }
              }

              function openTodoModal(id) {
                const todo = findTodo(id);
                if (!todo || !editForm || !todoModal) return;
                const isRemoteOnly = typeof todo.id === 'string' && String(todo.id).startsWith('gcal:');
                editingTodoId = todo.id;
                try {
                  if (isRemoteOnly) {
                    editForm.action = '#';
                    if (deleteForm) deleteForm.action = '#';
                    fillTodoModalFields(todo);
                    setTodoModalMode('edit');
                    if (todoModalTitle) todoModalTitle.textContent = @json(__('Google 予定（表示のみ）'));
                    if (todoModalDeleteBtn) todoModalDeleteBtn.hidden = true;
                    if (todoModalSaveBtn) todoModalSaveBtn.hidden = true;
                    if (todoModalCopyBtn) todoModalCopyBtn.hidden = true;
                    Array.from(editForm.elements).forEach((el) => {
                      if (el.name === 'returnTo' || el.name === '_token') return;
                      el.disabled = true;
                    });
                  } else {
                    editForm.action = '/todos/' + todo.id + '/update';
                    if (deleteForm) deleteForm.action = '/todos/' + todo.id + '/delete';
                    Array.from(editForm.elements).forEach((el) => { el.disabled = false; });
                    if (todoModalSaveBtn) todoModalSaveBtn.hidden = false;
                    if (todoModalCopyBtn) todoModalCopyBtn.hidden = false;
                    fillTodoModalFields(todo);
                    setTodoModalMode('edit');
                  }
                  todoModal.hidden = false;
                } catch (err) {
                  console.error('openTodoModal failed', err);
                }
              }

              modalDateModeSingle?.addEventListener('change', () => {
                if (modalDateModeSingle.checked) syncModalDateMode('single');
              });
              modalDateModeRange?.addEventListener('change', () => {
                if (modalDateModeRange.checked) syncModalDateMode('range');
              });
              editForm?.querySelector('#modal-start-date')?.addEventListener('change', () => {
                if (modalDateModeSingle?.checked) {
                  const endInput = editForm.querySelector('#modal-end-date');
                  if (endInput) endInput.value = editForm.querySelector('#modal-start-date').value;
                }
              });
              editForm?.addEventListener('submit', () => {
                if (modalDateModeSingle?.checked) {
                  const endInput = editForm.querySelector('#modal-end-date');
                  const start = editForm.querySelector('#modal-start-date')?.value;
                  if (endInput && start) endInput.value = start;
                }
              });
              todoModalCopyBtn?.addEventListener('click', () => {
                if (!editingTodoId) return;
                const source = findTodo(editingTodoId);
                if (!source) return;
                fillTodoModalFields({ ...source, completed: false });
                setTodoModalMode('copy');
                editingTodoId = null;
                todoModal.hidden = false;
                editForm.querySelector('[name=title]')?.focus();
              });

              const modalEnableTimeRange = editForm?.querySelector('#modal-enable-time-range');
              modalEnableTimeRange?.addEventListener('change', () => {
                const on = modalEnableTimeRange.checked;
                const panel = editForm.querySelector('#modal-time-range-panel');
                const startTime = editForm.querySelector('#modal-start-time');
                const endTime = editForm.querySelector('#modal-end-time');
                panel?.classList.toggle('date-panel-hidden', !on);
                if (startTime) startTime.disabled = !on;
                if (endTime) endTime.disabled = !on;
                if (!on) {
                  if (startTime) startTime.value = '';
                  if (endTime) endTime.value = '';
                }
              });

              document.querySelectorAll('[data-close-todo-modal]').forEach((el) => {
                el.addEventListener('click', closeTodoModal);
              });
              document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && todoModal && !todoModal.hidden) closeTodoModal();
              });

              function setupDraggable(el) {
                const todoId = el.getAttribute('data-todo-id');
                if (!todoId || !/^\d+$/.test(todoId)) {
                  el.setAttribute('draggable', 'false');
                  return;
                }
                el.setAttribute('draggable', 'true');
                let startX = 0;
                let startY = 0;
                el.addEventListener('dragstart', (e) => {
                  startX = e.clientX;
                  startY = e.clientY;
                  el.dataset.dragging = '1';
                  el.dataset.dragMoved = '0';
                  e.dataTransfer.setData('application/json', JSON.stringify({
                    type: 'todo',
                    id: Number(el.dataset.todoId),
                  }));
                  e.dataTransfer.effectAllowed = 'move';
                  el.classList.add('is-dragging');
                });
                el.addEventListener('drag', (e) => {
                  if (e.clientX === 0 && e.clientY === 0) return;
                  if (Math.abs(e.clientX - startX) > 4 || Math.abs(e.clientY - startY) > 4) {
                    el.dataset.dragMoved = '1';
                  }
                });
                el.addEventListener('dragend', () => {
                  el.classList.remove('is-dragging');
                  el.dataset.dragging = '0';
                  if (el.dataset.dragMoved === '1') {
                    setTimeout(() => { el.dataset.dragMoved = '0'; }, 50);
                  }
                });
              }

              document.querySelectorAll('.todos-calendar-panel .event-chip[data-todo-id]').forEach((el) => {
                setupDraggable(el);
                el.addEventListener('click', (e) => {
                  if (el.dataset.dragMoved === '1') {
                    el.dataset.dragMoved = '0';
                    e.preventDefault();
                    e.stopPropagation();
                    return;
                  }
                  e.preventDefault();
                  e.stopPropagation();
                  openTodoModal(el.dataset.todoId);
                });
              });

              document.querySelectorAll('.todos-calendar-panel .day-add-btn[data-date]').forEach((btn) => {
                btn.addEventListener('click', (e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  const date = btn.getAttribute('data-date');
                  if (!date) return;
                  window.location.href = '/todos?due=' + encodeURIComponent(date) + '#add-form';
                });
              });

              document.querySelectorAll('.todos-calendar-panel .event-chip.note-chip[data-note-id]').forEach((el) => {
                el.addEventListener('click', (e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  window.location.href = '/notes?edit=' + encodeURIComponent(el.getAttribute('data-note-id') || '');
                });
              });

              document.querySelectorAll('.todos-mobile-agenda .mobile-agenda-item[data-todo-id]').forEach((el) => {
                el.addEventListener('click', (e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  openTodoModal(el.dataset.todoId);
                });
              });

              document.querySelectorAll('.todos-calendar-panel .calendar-day[data-date]').forEach((day) => {
                day.addEventListener('dragover', (e) => {
                  e.preventDefault();
                  day.classList.add('is-drop-target');
                });
                day.addEventListener('dragleave', () => day.classList.remove('is-drop-target'));
                day.addEventListener('drop', async (e) => {
                  e.preventDefault();
                  day.classList.remove('is-drop-target');
                  let payload;
                  try { payload = JSON.parse(e.dataTransfer.getData('application/json') || '{}'); } catch (_) { return; }
                  if (payload.type !== 'todo' || !payload.id || !day.dataset.date) return;
                  const res = await fetch('/todos/' + payload.id + '/reschedule', {
                    method: 'POST',
                    headers: {
                      'Content-Type': 'application/json',
                      Accept: 'application/json',
                      'X-CSRF-TOKEN': csrf,
                      'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ date: day.dataset.date }),
                  });
                  const data = await res.json().catch(() => ({}));
                  if (!res.ok || !data.ok) {
                    alert(data.message || @json(__('移動に失敗しました')));
                    return;
                  }
                  location.reload();
                });
              });

              if (OPEN_EDIT_ID) openTodoModal(OPEN_EDIT_ID);
            })()
          </script>
        @else
        <div class="todo-table-wrap">
          <table class="todo-table">
            @include('partials.todo-table-colgroup')
            @include('partials.todo-table-header')
            <tbody>
          @foreach($todos as $row)
            @php $editUrl = '/todos'.$listQuery.(str_contains($listQuery, '?') ? '&' : '?').'edit='.$row['id'].'#todo-list-panel'; @endphp
            @if($editId === $row['id'])
              @include('partials.todo-edit-row', ['todo' => $row, 'listReturnTo' => $listReturnTo])
            @else
              @include('partials.todo-table-row', ['row' => $row, 'editUrl' => $editUrl])
            @endif
          @endforeach
            </tbody>
          </table>
        </div>
        @endif
        @if(($displayMode ?? 'list') === 'list')
        @if(($pagination['totalPages'] ?? 1) > 1)
          <nav class="todo-pagination" aria-label="{{ __('ToDo 一覧のページ') }}">
            @if($pagination['page'] > 1)
              <a class="button-link secondary" href="{{ $buildTodosQuery(['page' => $pagination['page'] - 1]) }}#todo-list-panel">{{ __('‹ 前へ') }}</a>
            @endif
            <span class="todo-pagination-label">{{ $pagination['page'] }} / {{ $pagination['totalPages'] }}</span>
            @if($pagination['page'] < $pagination['totalPages'])
              <a class="button-link secondary" href="{{ $buildTodosQuery(['page' => $pagination['page'] + 1]) }}#todo-list-panel">{{ __('次へ ›') }}</a>
            @endif
          </nav>
        @endif
        @if(count($todos) === 0)
          <p class="empty-msg">{{ __('条件に一致する ToDo がありません。') }}</p>
        @endif

        @if(count($undatedTodos) > 0)
          <h3 class="undated-heading">{{ __('期間未設定') }}（{{ count($undatedTodos) }}{{ __('件') }}）</h3>
          <div class="todo-table-wrap undated-section">
            <table class="todo-table">
              @include('partials.todo-table-colgroup')
              @include('partials.todo-table-header')
              <tbody>
            @foreach($undatedTodos as $row)
              @php $editUrl = '/todos'.$listQuery.(str_contains($listQuery, '?') ? '&' : '?').'edit='.$row['id'].'#todo-list-panel'; @endphp
              @if($editId === $row['id'])
                @include('partials.todo-edit-row', ['todo' => $row, 'listReturnTo' => $listReturnTo])
              @else
                @include('partials.todo-table-row', ['row' => $row, 'editUrl' => $editUrl])
              @endif
            @endforeach
              </tbody>
            </table>
          </div>
        @endif
        @endif
      </div>
    </main>

    <div class="modal modal-centered" id="todo-voice-confirm-modal" hidden>
      <div class="modal-backdrop" data-close-todo-voice-modal></div>
      <div class="modal-dialog finance-modal-dialog" role="dialog" aria-labelledby="todo-voice-confirm-title">
        <div class="modal-header">
          <h2 id="todo-voice-confirm-title">{{ __('音声入力の確認') }}</h2>
          <button type="button" class="modal-close" data-close-todo-voice-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" action="/todos" id="todo-voice-confirm-form" class="modal-form finance-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $listReturnTo }}" />
          <p class="hint" id="todo-voice-confirm-transcript"></p>
          <p class="hint" id="todo-voice-confirm-meta"></p>

          <label>
            {{ __('内容') }}
            <textarea name="titles" id="todo-voice-titles" rows="4" required></textarea>
          </label>

          <fieldset class="finance-type-fieldset">
            <legend>{{ __('指定方法') }}</legend>
            <label class="inline-check"><input type="radio" name="dateMode" value="single" checked /> {{ __('単日') }}</label>
            <label class="inline-check"><input type="radio" name="dateMode" value="range" /> {{ __('期間') }}</label>
          </fieldset>

          <label>
            {{ __('開始日') }}
            <input type="date" name="startDate" id="todo-voice-start-date" value="{{ $defaultStartDate }}" />
          </label>
          <label id="todo-voice-end-wrap">
            {{ __('終了日') }}
            <input type="date" name="endDate" id="todo-voice-end-date" value="{{ $defaultEndDate }}" />
          </label>

          <label>
            {{ __('重要度') }}
            <select name="importance" id="todo-voice-importance">
              @foreach($importanceLabels as $value => $label)
                <option value="{{ $value }}" @selected($value === 'medium')>{{ $label }}</option>
              @endforeach
            </select>
          </label>
          <label>
            {{ __('ステータス') }}
            <select name="category" id="todo-voice-category">
              @foreach($categoryLabels as $value => $label)
                <option value="{{ $value }}" @selected($value === 'task')>{{ $label }}</option>
              @endforeach
            </select>
          </label>

          <label>
            {{ __('開始時刻') }}
            <input type="time" name="startTime" id="todo-voice-start-time" />
          </label>
          <label>
            {{ __('終了時刻') }}
            <input type="time" name="endTime" id="todo-voice-end-time" />
          </label>

          <label @if(!empty($appContextIsWork)) hidden @endif>
            {{ __('共有先') }}
            <select name="groupId" id="todo-voice-group-id">
              <option value="">{{ __('個人（自分のみ）') }}</option>
              @foreach($approvedGroups ?? [] as $group)
                <option value="{{ $group['id'] }}">{{ $group['name'] }}</option>
              @endforeach
            </select>
          </label>

          <label class="inline-check">
            <input type="checkbox" name="splitByLine" id="todo-voice-split" value="1" checked />
            {{ __('改行ごとに別の ToDo として登録する') }}
          </label>

          <div class="finance-form-actions">
            <button type="button" class="secondary" data-close-todo-voice-modal>{{ __('キャンセル') }}</button>
            <button type="submit" class="button-link">{{ __('登録') }}</button>
          </div>
        </form>
      </div>
    </div>

    <form id="row-action-form" method="post" action="/todos/0/toggle" hidden aria-hidden="true">
      @csrf
      <input type="hidden" name="returnTo" value="{{ $listReturnTo }}" />
    </form>

    <script>
      const input = document.getElementById('titles-input')
      const splitByLine = document.getElementById('split-by-line')
      const preview = document.getElementById('line-preview')
      const previewCount = document.getElementById('preview-count')
      const previewList = document.getElementById('preview-list')
      const addForm = document.getElementById('add-form')
      const startDateInput = document.getElementById('start-date')
      const endDateInput = document.getElementById('end-date')
      const cellEnd = document.getElementById('cell-end')
      const labelStart = document.getElementById('label-start')
      const labelEnd = document.getElementById('label-end')
      const dateModeRadios = document.querySelectorAll('#add-form input[name="dateMode"]')
      const addWeekdayPanel = document.getElementById('add-weekday-panel')
      const addWeekdayInputs = document.querySelectorAll('.add-weekday-input')
      const excludeHolidaysInput = document.getElementById('exclude-holidays')
      const excludeClosuresInput = document.getElementById('exclude-closures')
      const excludeHolidaysValue = document.getElementById('exclude-holidays-value')
      const excludeClosuresValue = document.getElementById('exclude-closures-value')
      let nationalHolidayDatesCache = new Set(@json($nationalHolidayDates ?? []));
      let closureDatesCache = new Set(@json($closureDates ?? []));

      function syncExcludeFields() {
        if (excludeHolidaysValue) excludeHolidaysValue.value = excludeHolidaysInput?.checked ? '1' : '0'
        if (excludeClosuresValue) excludeClosuresValue.value = excludeClosuresInput?.checked ? '1' : '0'
      }
      const checks = () => Array.from(document.querySelectorAll('.todo-check'))
      const selectAllBtn = document.getElementById('select-all-btn')

      function todayIso() {
        const today = new Date()
        return [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-')
      }

      function syncTodoDateMode() {
        const mode = document.querySelector('#add-form input[name="dateMode"]:checked')?.value || 'single'
        const isRange = mode === 'range'
        cellEnd?.classList.toggle('date-panel-hidden', !isRange)
        labelEnd?.classList.toggle('date-panel-hidden', !isRange)
        if (labelStart) labelStart.textContent = isRange ? @json(__('開始日')) : @json(__('日付'));
        addWeekdayPanel?.classList.toggle('date-panel-hidden', !isRange)
        addWeekdayInputs.forEach((input) => {
          if (!isRange) input.checked = false
        })
        if (!isRange) {
          if (excludeHolidaysInput) excludeHolidaysInput.checked = false
          if (excludeClosuresInput) excludeClosuresInput.checked = false
        } else {
          if (excludeHolidaysInput && excludeHolidaysInput.dataset.userSet !== '1') {
            excludeHolidaysInput.checked = true
          }
          if (excludeClosuresInput && excludeClosuresInput.dataset.userSet !== '1') {
            excludeClosuresInput.checked = true
          }
        }
        syncExcludeFields()
      }

      dateModeRadios.forEach((radio) => radio.addEventListener('change', () => {
        syncTodoDateMode()
        updatePreview()
      }))

      const iso = todayIso()
      if (!startDateInput.value) startDateInput.value = iso
      if (!endDateInput.value) endDateInput.value = iso

      startDateInput?.addEventListener('change', () => {
        if (!endDateInput.value || endDateInput.value < startDateInput.value) {
          endDateInput.value = startDateInput.value
        }
      })

      const todoStartTime = document.getElementById('todo-start-time')
      const todoEndTime = document.getElementById('todo-end-time')
      const enableTimeRange = document.getElementById('enable-time-range')
      const timeRangePanel = document.getElementById('time-range-panel')

      // 現在時刻を30分単位で切り上げた開始時刻と、その1時間後の終了時刻を返す
      function defaultTimeRange() {
        const now = new Date()
        let hours = now.getHours()
        let minutes = Math.ceil(now.getMinutes() / 30) * 30
        if (minutes >= 60) {
          minutes = 0
          hours += 1
        }
        const pad = (n) => String(n).padStart(2, '0')
        const start = `${pad(hours % 24)}:${pad(minutes)}`
        const end = `${pad((hours + 1) % 24)}:${pad(minutes)}`
        return { start, end }
      }

      function syncTimeRangePanel() {
        const on = enableTimeRange?.checked
        timeRangePanel?.classList.toggle('date-panel-hidden', !on)
        if (todoStartTime) todoStartTime.disabled = !on
        if (todoEndTime) todoEndTime.disabled = !on
        if (on) {
          if (todoStartTime && !todoStartTime.value) {
            const range = defaultTimeRange()
            todoStartTime.value = range.start
            if (todoEndTime && !todoEndTime.value) todoEndTime.value = range.end
          }
        } else {
          if (todoStartTime) todoStartTime.value = ''
          if (todoEndTime) todoEndTime.value = ''
        }
      }

      enableTimeRange?.addEventListener('change', syncTimeRangePanel)

      todoStartTime?.addEventListener('change', () => {
        if (!todoEndTime.value || todoEndTime.value < todoStartTime.value) {
          todoEndTime.value = todoStartTime.value
        }
      })

      document.querySelectorAll('.edit-form').forEach((form) => {
        const cb = form.querySelector('.edit-enable-time-range')
        const panel = form.querySelector('.edit-time-range-panel')
        const start = form.querySelector('input[name="startTime"]')
        const end = form.querySelector('input[name="endTime"]')
        if (!cb || !panel || !start || !end) return

        function syncEditTimeRange() {
          const on = cb.checked
          panel.classList.toggle('date-panel-hidden', !on)
          start.disabled = !on
          end.disabled = !on
          if (on) {
            if (!start.value) {
              const range = defaultTimeRange()
              start.value = range.start
              if (!end.value) end.value = range.end
            }
          } else {
            start.value = ''
            end.value = ''
          }
        }

        cb.addEventListener('change', syncEditTimeRange)
        start.addEventListener('change', () => {
          if (!end.value || end.value < start.value) end.value = start.value
        })
      })

      function stripLine(line) {
        return line.trim().replace(/^[-*・•]\s*/, '').replace(/^\d+[.)．]\s*/, '')
      }

      function parseLines(raw, split) {
        const lines = String(raw || '').split(/\r\n|\r|\n|\u2028|\u2029/)
        if (!split) {
          const single = lines.map((l) => l.trim()).filter(Boolean).join('\n').trim()
          return single ? [single] : []
        }
        return lines.map(stripLine).filter((line) => line && !/^\[\/?(info|title|hr)\]$/i.test(line))
      }

      function getSelectedWeekdays() {
        return [...addWeekdayInputs].filter((input) => input.checked).map((input) => Number(input.value))
      }

      function nextIsoDate(dateStr) {
        const [y, m, d] = dateStr.split('-').map(Number)
        const dt = new Date(y, m - 1, d + 1)
        return [dt.getFullYear(), String(dt.getMonth() + 1).padStart(2, '0'), String(dt.getDate()).padStart(2, '0')].join('-')
      }

      function timeToMinutes(value) {
        if (!value || typeof value !== 'string') return null
        const match = value.trim().match(/^(\d{1,2}):(\d{2})$/)
        if (!match) return null
        const hours = Number(match[1])
        const minutes = Number(match[2])
        if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return null
        return hours * 60 + minutes
      }

      function shouldSkipExpandedDate(cur, excludeHolidays, excludeClosures) {
        if (excludeHolidays && nationalHolidayDatesCache.has(cur)) return true
        if (excludeClosures && closureDatesCache.has(cur)) return true
        return false
      }

      async function refreshHolidayDatesForPreview() {
        const start = startDateInput?.value
        const end = endDateInput?.value
        if (!start || !end) return
        try {
          const res = await fetch(`/api/holiday-dates?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`)
          if (!res.ok) return
          const data = await res.json()
          nationalHolidayDatesCache = new Set(data.national || [])
          closureDatesCache = new Set(data.closure || [])
        } catch {
          // 初期読み込み分をそのまま使う
        }
      }

      function expandDatesByWeekdays(start, end, weekdays, excludeHolidays, excludeClosures) {
        if (!start || !end || weekdays.length === 0) return []
        let from = start
        let to = end
        if (from > to) [from, to] = [to, from]
        const weekdaySet = new Set(weekdays)
        const dates = []
        let cur = from
        while (cur <= to) {
          const [y, m, d] = cur.split('-').map(Number)
          if (weekdaySet.has(new Date(y, m - 1, d).getDay())) {
            if (!shouldSkipExpandedDate(cur, excludeHolidays, excludeClosures)) dates.push(cur)
          }
          if (cur === to) break
          cur = nextIsoDate(cur)
        }
        return dates
      }

      async function updatePreview() {
        const titles = parseLines(input.value, splitByLine.checked)
        const mode = document.querySelector('#add-form input[name="dateMode"]:checked')?.value || 'single'
        const weekdays = getSelectedWeekdays()
        const excludeHolidays = Boolean(excludeHolidaysInput?.checked)
        const excludeClosures = Boolean(excludeClosuresInput?.checked)
        if (mode === 'range' && weekdays.length > 0 && (excludeHolidays || excludeClosures)) {
          await refreshHolidayDatesForPreview()
        }
        const dates =
          mode === 'range' && weekdays.length > 0
            ? expandDatesByWeekdays(startDateInput.value, endDateInput.value, weekdays, excludeHolidays, excludeClosures)
            : []
        const items =
          dates.length > 0
            ? titles.flatMap((title) => dates.map((date) => `${title}（${date}）`))
            : titles
        previewCount.textContent = String(items.length)
        previewList.innerHTML = ''
        preview.hidden = items.length === 0
        items.forEach((item) => {
          const li = document.createElement('li')
          li.textContent = item
          previewList.appendChild(li)
        })
      }

      input?.addEventListener('input', updatePreview)
      splitByLine?.addEventListener('change', updatePreview)
      startDateInput?.addEventListener('change', updatePreview)
      endDateInput?.addEventListener('change', updatePreview)
      addWeekdayInputs.forEach((input) => input.addEventListener('change', updatePreview))
      excludeHolidaysInput?.addEventListener('change', () => {
        excludeHolidaysInput.dataset.userSet = '1'
        syncExcludeFields()
        updatePreview()
      })
      excludeClosuresInput?.addEventListener('change', () => {
        excludeClosuresInput.dataset.userSet = '1'
        syncExcludeFields()
        updatePreview()
      })
      addForm?.addEventListener('submit', (e) => {
        syncExcludeFields()
        const mode = document.querySelector('#add-form input[name="dateMode"]:checked')?.value || 'single'
        if (mode === 'single') {
          endDateInput.value = startDateInput.value
          if (excludeHolidaysValue) excludeHolidaysValue.value = '0'
          if (excludeClosuresValue) excludeClosuresValue.value = '0'
        } else {
          const weekdays = getSelectedWeekdays()
          if (weekdays.length > 0) {
            if (excludeHolidaysValue) excludeHolidaysValue.value = '1'
            if (excludeClosuresValue) excludeClosuresValue.value = '1'
          }
          const excludeHolidays = Boolean(excludeHolidaysInput?.checked)
          const excludeClosures = Boolean(excludeClosuresInput?.checked)
          if (excludeHolidays || excludeClosures) {
            if (weekdays.length === 0) {
              e.preventDefault()
              window.alert(@json(__('祝日または休業日を除く場合は曜日を選択してください')));
              return
            }
          }
        }
        if (!splitByLine.checked) {
          splitByLine.disabled = true
          const hidden = document.createElement('input')
          hidden.type = 'hidden'
          hidden.name = 'splitByLine'
          hidden.value = '0'
          addForm.appendChild(hidden)
        }
      })

      syncTodoDateMode()
      syncTimeRangePanel()
      updatePreview()

      selectAllBtn?.addEventListener('click', () => {
        const all = checks()
        const shouldCheck = all.some((c) => !c.checked)
        all.forEach((c) => { c.checked = shouldCheck })
        selectAllBtn.textContent = shouldCheck ? @json(__('全解除')) : @json(__('全選択'));
      })

      ;(function () {
        const SCROLL_KEY = 'todosListScrollY'
        const listPanel = document.getElementById('todo-list-panel')
        const filterForm = document.getElementById('filter-form')
        const periodMonthInput = document.getElementById('filter-period-month')
        const periodYearInput = document.getElementById('filter-period-year')
        const periodModeRadios = document.querySelectorAll('#filter-form input[name="periodMode"]')
        const rowActionForm = document.getElementById('row-action-form')
        const bulkReturnTo = document.getElementById('bulk-return-to')

        function syncFilterPeriodMode() {
          const mode = document.querySelector('#filter-form input[name="periodMode"]:checked')?.value || 'month'
          const isYear = mode === 'year'
          periodMonthInput?.classList.toggle('date-panel-hidden', isYear)
          periodYearInput?.classList.toggle('date-panel-hidden', !isYear)
          if (periodMonthInput) periodMonthInput.disabled = isYear
          if (periodYearInput) periodYearInput.disabled = !isYear
        }

        function submitFilterForm() {
          if (!filterForm) return
          saveScroll()
          syncFilterPeriodMode()
          filterForm.requestSubmit()
        }

        periodModeRadios.forEach((radio) => {
          radio.addEventListener('change', submitFilterForm)
        })
        syncFilterPeriodMode()

        periodMonthInput?.addEventListener('change', submitFilterForm)
        periodYearInput?.addEventListener('change', submitFilterForm)

        const categoryDropdown = document.getElementById('filter-category-dropdown')
        const categoryTrigger = document.getElementById('filter-category-trigger')
        const categoryPanel = document.getElementById('filter-category-panel')
        const categoryLabel = document.getElementById('filter-category-label')
        const categoryCheckboxes = document.querySelectorAll('.filter-category-cb')

        function updateCategoryFilterLabel() {
          if (!categoryLabel) return
          const checked = Array.from(categoryCheckboxes).filter((cb) => cb.checked)
          categoryLabel.textContent = checked.length === 0
            ? @json(__('すべて'))
            : checked.map((cb) => cb.dataset.label || cb.value).join('、');
        }

        categoryTrigger?.addEventListener('click', (e) => {
          e.stopPropagation()
          const willOpen = categoryPanel?.hidden
          categoryPanel.hidden = !willOpen
          categoryTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false')
        })

        categoryPanel?.addEventListener('click', (e) => e.stopPropagation())

        document.addEventListener('click', () => {
          if (!categoryPanel || categoryPanel.hidden) return
          categoryPanel.hidden = true
          categoryTrigger?.setAttribute('aria-expanded', 'false')
        })

        categoryCheckboxes.forEach((cb) => {
          cb.addEventListener('change', updateCategoryFilterLabel)
        })

        if (categoryPanel) categoryPanel.hidden = true

        function submitBulkAction(url, confirmMsg) {
          const checked = document.querySelectorAll('.todo-check:checked')
          if (checked.length === 0) {
            window.alert(@json(__('対象が選択されていません')));
            return
          }
          if (confirmMsg && !window.confirm(confirmMsg)) return

          saveScroll()
          const form = document.createElement('form')
          form.method = 'POST'
          form.action = url
          form.style.display = 'none'

          const returnTo = document.createElement('input')
          returnTo.type = 'hidden'
          returnTo.name = 'returnTo'
          returnTo.value = bulkReturnTo?.value || '/todos#todo-list-panel'
          form.appendChild(returnTo)

          const csrf = document.createElement('input')
          csrf.type = 'hidden'
          csrf.name = '_token'
          csrf.value = @json(csrf_token());
          form.appendChild(csrf)

          checked.forEach((cb) => {
            const idInput = document.createElement('input')
            idInput.type = 'hidden'
            idInput.name = 'ids[]'
            idInput.value = cb.value
            form.appendChild(idInput)
          })

          document.body.appendChild(form)
          form.submit()
        }

        function saveScroll() {
          sessionStorage.setItem(SCROLL_KEY, String(window.scrollY))
        }

        const saved = sessionStorage.getItem(SCROLL_KEY)
        if (saved != null) {
          requestAnimationFrame(() => {
            requestAnimationFrame(() => {
              window.scrollTo(0, Number(saved))
              sessionStorage.removeItem(SCROLL_KEY)
            })
          })
        }

        listPanel?.addEventListener('click', (e) => {
          const btn = e.target.closest('.todo-row-action')
          if (!btn || !rowActionForm) return
          const confirmMsg = btn.dataset.confirm
          if (confirmMsg && !window.confirm(confirmMsg)) return
          saveScroll()
          rowActionForm.action = `/todos/${btn.dataset.todoId}/${btn.dataset.action}`
          rowActionForm.submit()
        })

        listPanel?.querySelectorAll('.todo-row-edit').forEach((link) => {
          link.addEventListener('click', saveScroll)
        })

        listPanel?.querySelectorAll('.edit-form').forEach((form) => {
          form.addEventListener('submit', saveScroll)
        })

        document.getElementById('filter-today-btn')?.addEventListener('click', saveScroll)
        document.getElementById('filter-clear-btn')?.addEventListener('click', saveScroll)

        document.querySelectorAll('.bulk-btn').forEach((btn) => {
          btn.addEventListener('click', () => {
            submitBulkAction(btn.dataset.bulkUrl, btn.dataset.confirm || '')
          })
        })

        filterForm?.addEventListener('submit', () => {
          saveScroll()
          syncFilterPeriodMode()
        })
      })()

      document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('todo-voice-confirm-modal')
        const form = document.getElementById('todo-voice-confirm-form')
        const endWrap = document.getElementById('todo-voice-end-wrap')
        function syncVoiceDateMode() {
          const mode = form?.querySelector('input[name="dateMode"]:checked')?.value || 'single'
          if (endWrap) endWrap.hidden = mode === 'single'
        }
        form?.querySelectorAll('input[name="dateMode"]').forEach((radio) => {
          radio.addEventListener('change', syncVoiceDateMode)
        })
        document.querySelectorAll('[data-close-todo-voice-modal]').forEach((el) => {
          el.addEventListener('click', () => modal?.setAttribute('hidden', ''))
        })

        window.Sa2VoiceEntry?.init({
          prefix: 'todo',
          parseUrl: '/todos/voice/parse',
          ready: @json(!empty($voiceAiReady)),
          strings: {
            speak: @json(__('話す')),
            stop: @json(__('停止')),
            empty: @json(__('音声テキストを入力するか、マイクで話してください。')),
            notReady: @json(__('AI（ChatGPT / Gemini）が未設定です。設定画面で有効化してください。')),
            parsing: @json(__('AIで解析中…')),
            parsed: @json(__('解析結果を確認して登録してください。')),
            parseFailed: @json(__('音声の解析に失敗しました。')),
            unsupported: @json(__('このブラウザは音声認識に対応していません。テキスト入力をご利用ください。')),
            listening: @json(__('聞いています…')),
            transcribed: @json(__('文字起こし完了。解析します…')),
            micDenied: @json(__('マイクの使用が許可されていません。')),
            recognizeFailed: @json(__('音声認識に失敗しました。')),
            startFailed: @json(__('音声認識を開始できませんでした。')),
          },
          onParsed(parsed, transcript) {
            document.getElementById('todo-voice-confirm-transcript').textContent =
              @json(__('認識テキスト:')) + ' ' + transcript
            const provider = parsed.provider === 'gemini' ? 'Gemini' : (parsed.provider === 'openai' ? 'ChatGPT' : '')
            const confidenceLabel = {
              high: @json(__('確信度: 高')),
              medium: @json(__('確信度: 中')),
              low: @json(__('確信度: 低')),
            }[parsed.confidence] || ''
            document.getElementById('todo-voice-confirm-meta').textContent = [provider, confidenceLabel].filter(Boolean).join(' / ')

            const titles = Array.isArray(parsed.titles) ? parsed.titles.join('\n') : ''
            document.getElementById('todo-voice-titles').value = titles
            const mode = parsed.dateMode === 'range' ? 'range' : 'single'
            const modeRadio = form.querySelector(`input[name="dateMode"][value="${mode}"]`)
            if (modeRadio) modeRadio.checked = true
            document.getElementById('todo-voice-start-date').value = parsed.startDate || ''
            document.getElementById('todo-voice-end-date').value = parsed.endDate || parsed.startDate || ''
            document.getElementById('todo-voice-importance').value = parsed.importance || 'medium'
            document.getElementById('todo-voice-category').value = parsed.category || 'task'
            document.getElementById('todo-voice-start-time').value = parsed.startTime || ''
            document.getElementById('todo-voice-end-time').value = parsed.endTime || ''
            document.getElementById('todo-voice-group-id').value = parsed.groupId ? String(parsed.groupId) : ''
            document.getElementById('todo-voice-split').checked = parsed.splitByLine !== false
            syncVoiceDateMode()
            modal?.removeAttribute('hidden')
          },
        })
      })
    </script>
    @include('partials.calendar-height-resizer-script')
    @include('partials.accordion-state')
  </body>
</html>
