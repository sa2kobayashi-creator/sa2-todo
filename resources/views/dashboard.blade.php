<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <title>{{ __('ダッシュボード') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'dashboard'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="calendar-shell" data-calendar-view="{{ $view }}">
        <div class="calendar-toolbar">
          <div class="nav-group">
            <a class="button-link secondary icon-btn" href="{{ $prevUrl }}">‹</a>
            <a class="button-link secondary" href="{{ $todayUrl }}">{{ __('今日') }}</a>
            <a class="button-link secondary icon-btn" href="{{ $nextUrl }}">›</a>
          </div>
          <div class="month-label">{{ $periodLabel }}</div>
          <div class="calendar-toolbar-links">
            <div class="calendar-view-switch" role="group" aria-label="{{ __('表示切替') }}">
              @foreach($viewLabels as $viewKey => $viewLabel)
                <a
                  href="{{ $buildViewUrl($viewKey) }}"
                  class="calendar-view-btn @if($view === $viewKey) is-active @endif"
                  aria-current="{{ $view === $viewKey ? 'page' : 'false' }}"
                >{{ $viewLabel }}</a>
              @endforeach
            </div>
            <a class="button-link secondary" href="/notes">{{ __('メモ') }}</a>
            <a class="button-link" href="/todos">{{ __('Todo 管理へ') }}</a>
          </div>
        </div>

        @if($view === 'day')
          @include('dashboard.partials.calendar-day')
        @elseif($view === 'week')
          @include('dashboard.partials.calendar-week')
        @elseif($view === 'year')
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
                  $cellData = $limitTodosForCell($cell['todos'] ?? [], 4);
                  $holidayClass = !empty($cell['isHoliday']) ? 'is-holiday is-holiday-'.($cell['holidaySource'] ?? 'national') : '';
                @endphp
                <div
                  class="calendar-day {{ !empty($cell['inMonth']) ? '' : 'other-month' }} {{ !empty($cell['isToday']) ? 'today' : '' }} {{ count($cellNotes) ? 'has-notes' : '' }} {{ $holidayClass }}"
                  data-date="{{ $cell['date'] }}"
                  data-in-month="{{ !empty($cell['inMonth']) ? '1' : '0' }}"
                >
                  <div class="day-header">
                    <span class="day-num" title="{{ $cell['holidayName'] ?? '' }}">{{ $cell['day'] }}</span>
                    <div class="day-header-actions">
                      @if(count($cellNotes) > 0)
                        <button
                          type="button"
                          class="day-note-badge"
                          data-date="{{ $cell['date'] }}"
                          title="{{ __('メモ') }} {{ count($cellNotes) }}{{ __('件') }}"
                          aria-label="{{ $cell['date'] }} {{ __('のメモ') }} {{ count($cellNotes) }}{{ __('件') }}"
                        >
                          <span class="day-note-badge-icon" aria-hidden="true">📝</span>
                          @if(count($cellNotes) > 1)
                            <span class="day-note-badge-count">{{ count($cellNotes) }}</span>
                          @endif
                        </button>
                      @endif
                      <button type="button" class="day-add-btn" data-date="{{ $cell['date'] }}" title="{{ __('ToDo を追加') }}">+</button>
                    </div>
                  </div>
                  @if(!empty($cell['holidayName']))
                    <div class="holiday-label" title="{{ $cell['holidayName'] }}">{{ $cell['holidayName'] }}</div>
                  @endif
                  <div class="day-events day-events-desktop">
                    @foreach($cellData['visible'] as $todo)
                      @php
                        $chipTimeLabel = '—';
                        if (!empty($todo['startTime'])) {
                          $chipTimeLabel = (!empty($todo['endTime']) && $todo['endTime'] !== $todo['startTime'])
                            ? $todo['startTime'].'～'.$todo['endTime']
                            : $todo['startTime'];
                        }
                      @endphp
                      <button
                        type="button"
                        @class([
                          'event-chip',
                          'category-'.($todo['category'] ?? 'task'),
                          'importance-'.($todo['importance'] ?? 'medium'),
                          'done' => ! empty($todo['completed']),
                          'is-range' => ($todo['startDate'] ?? null) !== ($todo['endDate'] ?? null),
                        ])
                        data-todo-id="{{ $todo['id'] }}"
                        data-tip-title="{{ $todo['title'] }}"
                        data-tip-date="{{ $formatPeriodLabel($todo) }}"
                        data-tip-time="{{ $chipTimeLabel }}"
                      >
                        <span class="event-title">{{ $truncateTitle($todo['title']) }}</span>
                      </button>
                    @endforeach
                    @if(($cellData['hiddenCount'] ?? 0) > 0)
                      <button type="button" class="event-more" data-date="{{ $cell['date'] }}">
                        {{ __('他') }} {{ $cellData['hiddenCount'] }} {{ __('件') }}
                      </button>
                    @endif
                  </div>
                  <div class="day-events day-events-mobile">
                    @foreach($cellData['visible'] as $todo)
                      <button
                        type="button"
                        @class([
                          'event-chip',
                          'category-'.($todo['category'] ?? 'task'),
                          'importance-'.($todo['importance'] ?? 'medium'),
                          'done' => ! empty($todo['completed']),
                          'is-range' => ($todo['startDate'] ?? null) !== ($todo['endDate'] ?? null),
                        ])
                        data-todo-id="{{ $todo['id'] }}"
                      >
                        <span class="event-title">{{ $truncateTitle($todo['title']) }}</span>
                      </button>
                    @endforeach
                    @if(($cellData['hiddenCount'] ?? 0) > 0)
                      <button type="button" class="event-more" data-date="{{ $cell['date'] }}">
                        {{ __('他') }} {{ $cellData['hiddenCount'] }} {{ __('件') }}
                      </button>
                    @endif
                  </div>
                </div>
              @endforeach
            </div>
          @endforeach
        </div>
        @endif
      </div>

      @if($view === 'month')
      <section class="mobile-month-agenda panel" aria-label="{{ $month }}{{ __('月の予定一覧') }}">
        <h3>{{ $month }}{{ __('月の予定') }}（{{ count($monthAgenda) }}{{ __('件') }}）</h3>
        @if(count($monthAgenda) === 0)
          <p class="hint">{{ __('今月の予定はありません。カレンダーの + から ToDo を追加するか、') }}<a href="/notes">{{ __('メモ') }}</a>{{ __('を作成できます。') }}</p>
        @else
          <ul class="mobile-agenda-list">
            @foreach($monthAgenda as $item)
              @if(($item['kind'] ?? '') === 'note')
                <li>
                  <button type="button" class="mobile-agenda-item mobile-agenda-note" data-note-id="{{ $item['note']['id'] }}">
                    <span class="mobile-agenda-date">{{ $getNoteRegisteredDate($item['note']) }}</span>
                    <span class="mobile-agenda-title">📝 {{ $getNoteDisplayTitle($item['note']) }}</span>
                    <span class="mobile-agenda-meta">{{ __('メモ') }}</span>
                  </button>
                </li>
              @elseif(($item['kind'] ?? '') === 'todo')
                @php $todo = $item['todo']; @endphp
                <li @class(['done' => !empty($todo['completed'])])>
                  <button type="button" class="mobile-agenda-item" data-todo-id="{{ $todo['id'] }}">
                    <span class="mobile-agenda-date">{{ $formatPeriodLabel($todo) }}</span>
                    <span class="mobile-agenda-title">{{ $todo['title'] }}</span>
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

      @if(count($undated) > 0)
        <div class="panel calendar-sidebar">
          <h3>{{ __('期間未設定') }}（{{ count($undated) }}{{ __('件') }}）</h3>
          <ul class="undated-list">
            @foreach($undated as $todo)
              <li @class(['done' => !empty($todo['completed'])])>
                <button type="button" class="undated-open" data-todo-id="{{ $todo['id'] }}">
                  {{ $truncateTitle($todo['title'], 40) }}
                </button>
              </li>
            @endforeach
          </ul>
        </div>
      @endif
    </main>

    <div id="event-tooltip" class="event-hover-tooltip" hidden aria-hidden="true"></div>

    <div class="modal" id="day-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="day-modal-title">
        <div class="modal-header">
          <h2 id="day-modal-title">{{ __('この日の予定') }}</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <ul class="modal-day-list" id="day-modal-list"></ul>
      </div>
    </div>

    <div class="modal" id="todo-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="todo-modal-title">
        <div class="modal-header">
          <h2 id="todo-modal-title">{{ __('ToDo 編集') }}</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" id="todo-edit-form" class="modal-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <label>
            {{ __('タイトル') }}
            <textarea name="title" rows="4" required></textarea>
          </label>
          <div class="modal-date-mode" role="group" aria-label="{{ __('指定方法') }}">
            <span class="field-label">{{ __('指定方法') }}</span>
            <label class="radio-inline">
              <input type="radio" name="dateMode" id="modal-date-mode-single" value="single" />
              {{ __('単日') }}
            </label>
            <label class="radio-inline">
              <input type="radio" name="dateMode" id="modal-date-mode-range" value="range" />
              {{ __('期間') }}
            </label>
          </div>
          <label id="modal-start-date-label">
            <span id="modal-start-date-text">{{ __('開始日') }}</span>
            <input type="date" name="startDate" id="modal-start-date" />
          </label>
          <label id="modal-end-date-label">
            {{ __('終了日') }}
            <input type="date" name="endDate" id="modal-end-date" />
          </label>
          <div class="schedule-option">
            <label class="schedule-toggle">
              <input type="checkbox" id="modal-enable-time-range" />
              {{ __('時間帯を追加') }}
            </label>
            <div class="time-range-panel date-panel-hidden" id="modal-time-range-panel">
              <div class="time-range-inputs">
                <input type="time" name="startTime" id="modal-start-time" aria-label="{{ __('開始時刻') }}" disabled />
                <span class="time-range-separator" aria-hidden="true">～</span>
                <input type="time" name="endTime" id="modal-end-time" aria-label="{{ __('終了時刻') }}" disabled />
              </div>
            </div>
          </div>
          <label>
            {{ __('重要度') }}
            <select name="importance" id="modal-importance">
              @foreach($importanceLabels as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
              @endforeach
            </select>
          </label>
          <label>
            {{ __('ステータス') }}
            <select name="category" id="modal-category">
              @foreach($categoryLabels as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
              @endforeach
            </select>
          </label>
          <label class="inline-check" id="modal-completed-label">
            <input type="checkbox" name="completed" value="1" />
            {{ __('完了') }}
          </label>
        </form>
        <form method="post" id="todo-delete-form" class="modal-delete-form" onsubmit='return confirm(@json(__('この ToDo を削除しますか？')))'>
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
        </form>
        <div class="modal-actions">
          <button type="button" class="secondary" data-close-modal>{{ __('キャンセル') }}</button>
          <button type="submit" form="todo-edit-form" id="todo-modal-save">{{ __('保存') }}</button>
          <button type="button" class="secondary" id="todo-modal-copy" title="{{ __('コピーして新規作成') }}">{{ __('コピー') }}</button>
          <button type="submit" form="todo-delete-form" class="danger" id="todo-modal-delete">{{ __('削除') }}</button>
        </div>
      </div>
    </div>

    <div class="modal" id="note-day-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="note-day-modal-title">
        <div class="modal-header">
          <h2 id="note-day-modal-title">{{ __('この日のメモ') }}</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <ul class="modal-day-list note-day-list" id="note-day-modal-list"></ul>
      </div>
    </div>

    <div class="modal" id="note-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <div class="modal-dialog note-modal-dialog" role="dialog" aria-labelledby="note-modal-title">
        <div class="modal-header">
          <h2 id="note-modal-title">{{ __('メモ') }}</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <p class="note-modal-meta" id="note-modal-meta"></p>
        <div class="note-modal-content" id="note-modal-content"></div>
        <div class="modal-actions">
          <button type="button" class="secondary" data-close-modal>{{ __('閉じる') }}</button>
        </div>
      </div>
    </div>

    <div class="modal modal-centered" id="note-compose-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <div class="modal-dialog note-modal-dialog" role="dialog" aria-labelledby="note-compose-modal-title">
        <div class="modal-header">
          <h2 id="note-compose-modal-title">{{ __('メモを追加') }}</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" action="/notes" id="note-compose-form" class="modal-form note-compose-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <input type="hidden" name="type" value="text" />
          <input type="hidden" name="color" id="note-compose-color" value="default" />
          <label class="note-date-field">
            <span class="field-label">{{ __('登録日') }}</span>
            <input type="date" name="registeredDate" id="note-compose-date" required />
          </label>
          <label>
            {{ __('タイトル') }}
            <input type="text" name="title" id="note-compose-title" placeholder="{{ __('タイトル') }}" autocomplete="off" />
          </label>
          <label>
            {{ __('本文') }}
            <textarea name="body" id="note-compose-body" rows="6" placeholder="{{ __('メモを入力...') }}"></textarea>
          </label>
          <div class="note-composer-footer">
            <div class="note-color-picker" role="group" aria-label="{{ __('色') }}">
              @foreach($colorKeys as $key)
                <button
                  type="button"
                  class="note-color-dot note-compose-color-dot @class(['is-selected' => $key === 'default'])"
                  data-color="{{ $key }}"
                  style="--note-color: {{ $noteColors[$key]['bg'] }}; --note-border: {{ $noteColors[$key]['border'] }}"
                  title="{{ $noteColors[$key]['label'] }}"
                  aria-label="{{ $noteColors[$key]['label'] }}"
                ></button>
              @endforeach
            </div>
            <div class="note-composer-actions">
              <button type="button" class="secondary" data-close-modal>{{ __('キャンセル') }}</button>
              <button type="submit" class="button-link">{{ __('保存') }}</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="modal modal-centered" id="quick-add-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="quick-add-modal-title">
        <div class="modal-header">
          <h2 id="quick-add-modal-title">{{ __('ToDo を追加') }}</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" action="/todos" id="quick-add-modal-form" class="modal-form quick-add-modal-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <input type="hidden" name="startDate" id="quick-add-start" />
          <input type="hidden" name="endDate" id="quick-add-end" />
          <input type="hidden" name="splitByLine" value="1" />
          <label>
            {{ __('タイトル（1行1件）') }}
            <textarea name="titles" id="quick-add-titles" rows="6" placeholder="{{ __('1行1件で入力') }}" required></textarea>
          </label>
          <div class="modal-actions quick-add-modal-actions">
            <button type="button" class="secondary" id="quick-add-memo-btn">{{ __('メモを追加') }}</button>
            <button type="button" class="secondary" data-close-modal>{{ __('閉じる') }}</button>
            <button type="submit">{{ __('追加') }}</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      const TODO_ITEMS = @json($todosForJs ?? []);
      const NOTE_ITEMS = @json($notesForJs ?? []);
      const NOTE_COLORS = @json($noteColors);
      const RETURN_TO = @json($returnTo);
      const CALENDAR_VIEW = @json($view);

      const timedScroll = document.querySelector('.cal-timed-scroll')
      if (timedScroll) {
        const host = timedScroll.closest('.calendar-day-view, .calendar-week-view') || timedScroll
        const hourHeight = parseFloat(getComputedStyle(host).getPropertyValue('--cal-hour-height')) || 48
        timedScroll.scrollTop = hourHeight * 8
      }

      let quickAddDate = null
      const todoModal = document.getElementById('todo-modal')
      const dayModal = document.getElementById('day-modal')
      const noteDayModal = document.getElementById('note-day-modal')
      const noteModal = document.getElementById('note-modal')
      const noteComposeModal = document.getElementById('note-compose-modal')
      const noteComposeForm = document.getElementById('note-compose-form')
      const noteComposeDate = document.getElementById('note-compose-date')
      const noteComposeTitle = document.getElementById('note-compose-title')
      const noteComposeBody = document.getElementById('note-compose-body')
      const noteComposeColor = document.getElementById('note-compose-color')
      const quickAddModal = document.getElementById('quick-add-modal')
      const quickAddTitles = document.getElementById('quick-add-titles')
      const quickAddStart = document.getElementById('quick-add-start')
      const quickAddEnd = document.getElementById('quick-add-end')
      const quickAddMemoBtn = document.getElementById('quick-add-memo-btn')
      const editForm = document.getElementById('todo-edit-form')
      const deleteForm = document.getElementById('todo-delete-form')
      const todoModalCopyBtn = document.getElementById('todo-modal-copy')
      let editingTodoId = null

      function findTodo(id) {
        return TODO_ITEMS.find((item) => item.id === Number(id))
      }

      function findNote(id) {
        return NOTE_ITEMS.find((item) => item.id === Number(id))
      }

      function noteRegisteredDate(note) {
        return note.registeredDate || (note.createdAt ? note.createdAt.slice(0, 10) : '')
      }

      function noteDisplayTitle(note) {
        if (note.title && note.title.trim()) return note.title.trim()
        if (note.type === 'checklist' && note.items && note.items.length) {
          const first = note.items.find((item) => !item.checked) || note.items[0]
          return first?.text || @json(__('（無題）'));
        }
        const line = String(note.body || '').split(/\r\n|\r|\n/).find((row) => row.trim())
        return line?.trim() || @json(__('（無題）'));
      }

      function notesForDate(date) {
        return NOTE_ITEMS.filter((note) => noteRegisteredDate(note) === date)
      }

      function closeModals() {
        todoModal.hidden = true
        dayModal.hidden = true
        noteDayModal.hidden = true
        noteModal.hidden = true
        if (noteComposeModal) noteComposeModal.hidden = true
        if (quickAddModal) quickAddModal.hidden = true
      }

      function applyNoteComposePalette(colorKey) {
        const palette = NOTE_COLORS[colorKey] || NOTE_COLORS.default
        const dialog = noteComposeModal?.querySelector('.note-modal-dialog')
        if (dialog) {
          dialog.style.setProperty('--note-bg', palette.bg)
          dialog.style.setProperty('--note-border', palette.border)
        }
      }

      function openNoteComposeModal(date) {
        if (!noteComposeModal || !noteComposeForm) return
        closeModals()
        document.getElementById('note-compose-modal-title').textContent = @json(__(':date にメモを追加')).replace(':date', date);
        if (noteComposeDate) noteComposeDate.value = date
        if (noteComposeTitle) noteComposeTitle.value = ''
        if (noteComposeBody) noteComposeBody.value = ''
        if (noteComposeColor) noteComposeColor.value = 'default'
        noteComposeModal.querySelectorAll('.note-compose-color-dot').forEach((dot) => {
          dot.classList.toggle('is-selected', dot.dataset.color === 'default')
        })
        applyNoteComposePalette('default')
        noteComposeModal.hidden = false
        noteComposeTitle?.focus()
      }

      function openQuickAddForDate(date) {
        if (!quickAddModal) return
        quickAddDate = date
        closeModals()
        document.getElementById('quick-add-modal-title').textContent = @json(__(':date に ToDo を追加')).replace(':date', date);
        if (quickAddStart) quickAddStart.value = date
        if (quickAddEnd) quickAddEnd.value = date
        if (quickAddTitles) quickAddTitles.value = ''
        quickAddModal.hidden = false
        quickAddTitles?.focus()
      }

      function inRange(date, todo) {
        if (!todo.startDate && !todo.endDate) return false
        const start = todo.startDate || todo.endDate
        const end = todo.endDate || todo.startDate
        return date >= start && date <= end
      }

      const IMPORTANCE_LABELS = @json($importanceLabels);
      const CATEGORY_LABELS = @json($categoryLabels);

      function timeToMinutes(value) {
        if (!value || typeof value !== 'string') return null
        const match = value.trim().match(/^(\d{1,2}):(\d{2})/)
        if (!match) return null
        const hours = Number(match[1])
        const minutes = Number(match[2])
        if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return null
        return hours * 60 + minutes
      }

      function todoPrefix(todo) {
        const parts = []
        if (todo.category && todo.category !== 'task' && CATEGORY_LABELS[todo.category]) {
          parts.push(`[${CATEGORY_LABELS[todo.category]}]`)
        }
        if (todo.importance && todo.importance !== 'medium' && IMPORTANCE_LABELS[todo.importance]) {
          parts.push(`[${IMPORTANCE_LABELS[todo.importance]}]`)
        }
        return parts.length ? `${parts.join('')} ` : ''
      }

      function escapeHtml(text) {
        return String(text)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/"/g, '&quot;')
      }

      const todoModalTitle = document.getElementById('todo-modal-title')
      const todoModalDeleteBtn = document.getElementById('todo-modal-delete')
      const modalCompletedLabel = document.getElementById('modal-completed-label')
      const modalStartDateText = document.getElementById('modal-start-date-text')
      const modalEndDateLabel = document.getElementById('modal-end-date-label')
      const modalDateModeSingle = document.getElementById('modal-date-mode-single')
      const modalDateModeRange = document.getElementById('modal-date-mode-range')
      let todoModalMode = 'edit' // edit | copy

      function syncModalDateMode(mode) {
        const isSingle = mode === 'single'
        if (modalDateModeSingle) modalDateModeSingle.checked = isSingle
        if (modalDateModeRange) modalDateModeRange.checked = !isSingle
        if (modalStartDateText) modalStartDateText.textContent = isSingle ? @json(__('日付')) : @json(__('開始日'));
        if (modalEndDateLabel) modalEndDateLabel.classList.toggle('date-panel-hidden', isSingle)
        if (isSingle) {
          const start = editForm?.querySelector('#modal-start-date')?.value
          const endInput = editForm?.querySelector('#modal-end-date')
          if (endInput && start) endInput.value = start
        }
      }

      function fillTodoModalFields(todo) {
        editForm.querySelector('[name=title]').value = todo.title || ''
        editForm.querySelector('#modal-start-date').value = todo.startDate || ''
        editForm.querySelector('#modal-end-date').value = todo.endDate || todo.startDate || ''
        const enableTimeRange = editForm.querySelector('#modal-enable-time-range')
        const timeRangePanel = editForm.querySelector('#modal-time-range-panel')
        const startTimeInput = editForm.querySelector('#modal-start-time')
        const endTimeInput = editForm.querySelector('#modal-end-time')
        const hasTime = Boolean(todo.startTime)
        enableTimeRange.checked = hasTime
        timeRangePanel.classList.toggle('date-panel-hidden', !hasTime)
        startTimeInput.disabled = !hasTime
        endTimeInput.disabled = !hasTime
        startTimeInput.value = todo.startTime || ''
        endTimeInput.value = todo.endTime || ''
        editForm.querySelector('#modal-importance').value = todo.importance || 'medium'
        editForm.querySelector('#modal-category').value = todo.category || 'task'
        editForm.querySelector('[name=completed]').checked = !!todo.completed
        const isSingle = !todo.startDate || !todo.endDate || todo.startDate === todo.endDate
        syncModalDateMode(isSingle ? 'single' : 'range')
      }

      function setTodoModalMode(mode) {
        todoModalMode = mode
        const isCopy = mode === 'copy'
        if (todoModalTitle) todoModalTitle.textContent = isCopy ? @json(__('ToDo をコピーして追加')) : @json(__('ToDo 編集'));
        if (todoModalDeleteBtn) todoModalDeleteBtn.hidden = isCopy
        if (todoModalCopyBtn) todoModalCopyBtn.hidden = isCopy
        if (modalCompletedLabel) modalCompletedLabel.classList.toggle('date-panel-hidden', isCopy)
        if (isCopy) {
          editForm.action = '/todos'
          // store 側で title も受付。完了は新規なので送らない
          editForm.querySelector('[name=completed]')?.removeAttribute('name')
          editForm.querySelector('[name=completed]')?.setAttribute('data-completed-input', '1')
        } else {
          const completed = editForm.querySelector('[data-completed-input], [name=completed]')
          if (completed) {
            completed.setAttribute('name', 'completed')
            completed.removeAttribute('data-completed-input')
          }
        }
      }

      function openTodoModal(id) {
        const todo = findTodo(id)
        if (!todo) return
        editingTodoId = todo.id
        dayModal.hidden = true
        noteModal.hidden = true
        noteDayModal.hidden = true
        editForm.action = `/todos/${todo.id}/update`
        deleteForm.action = `/todos/${todo.id}/delete`
        fillTodoModalFields(todo)
        setTodoModalMode('edit')
        todoModal.hidden = false
      }

      modalDateModeSingle?.addEventListener('change', () => {
        if (modalDateModeSingle.checked) syncModalDateMode('single')
      })
      modalDateModeRange?.addEventListener('change', () => {
        if (modalDateModeRange.checked) syncModalDateMode('range')
      })
      editForm?.querySelector('#modal-start-date')?.addEventListener('change', () => {
        if (modalDateModeSingle?.checked) {
          const endInput = editForm.querySelector('#modal-end-date')
          if (endInput) endInput.value = editForm.querySelector('#modal-start-date').value
        }
      })

      editForm?.addEventListener('submit', () => {
        if (modalDateModeSingle?.checked) {
          const endInput = editForm.querySelector('#modal-end-date')
          const start = editForm.querySelector('#modal-start-date')?.value
          if (endInput && start) endInput.value = start
        }
      })

      todoModalCopyBtn?.addEventListener('click', () => {
        if (!editingTodoId) return
        const source = findTodo(editingTodoId)
        if (!source) return
        fillTodoModalFields({
          ...source,
          completed: false,
        })
        setTodoModalMode('copy')
        editingTodoId = null
        todoModal.hidden = false
        editForm.querySelector('[name=title]')?.focus()
      })

      function openNoteModal(id) {
        const note = findNote(id)
        if (!note) return
        dayModal.hidden = true
        noteDayModal.hidden = true
        todoModal.hidden = true
        const palette = NOTE_COLORS[note.color] || NOTE_COLORS.default
        const dialog = noteModal.querySelector('.note-modal-dialog')
        if (dialog) {
          dialog.style.setProperty('--note-bg', palette.bg)
          dialog.style.setProperty('--note-border', palette.border)
        }
        const title = note.title && note.title.trim() ? note.title.trim() : noteDisplayTitle(note)
        document.getElementById('note-modal-title').textContent = title
        const meta = []
        meta.push(`${@json(__('登録日:'))} ${noteRegisteredDate(note)}`);
        if (note.pinned) meta.push(@json(__('ピン留め')));
        if (note.type === 'checklist') meta.push(@json(__('チェックリスト')));
        document.getElementById('note-modal-meta').textContent = meta.join(' / ')
        const content = document.getElementById('note-modal-content')
        if (note.type === 'checklist' && note.items && note.items.length) {
          content.innerHTML = `<ul class="note-modal-checklist">${note.items
            .map(
              (item) =>
                `<li class="${item.checked ? 'is-done' : ''}"><span class="check-icon" aria-hidden="true">${item.checked ? '☑' : '☐'}</span><span>${escapeHtml(item.text)}</span></li>`
            )
            .join('')}</ul>`
        } else {
          const body = String(note.body || '').trim()
          content.innerHTML = body
            ? `<div class="note-modal-body">${escapeHtml(body).replace(/\r\n|\r|\n/g, '<br>')}</div>`
            : `<p class="hint">${@json(__('本文はありません。'))}</p>`
        }
        noteModal.hidden = false
      }

      function openNoteDayModal(date) {
        const dayNotes = notesForDate(date)
        if (dayNotes.length === 0) return
        if (dayNotes.length === 1) {
          openNoteModal(dayNotes[0].id)
          return
        }
        dayModal.hidden = true
        todoModal.hidden = true
        noteModal.hidden = true
        document.getElementById('note-day-modal-title').textContent = @json(__(':date のメモ')).replace(':date', date);
        const list = document.getElementById('note-day-modal-list')
        list.innerHTML = ''
        dayNotes.forEach((note) => {
          const li = document.createElement('li')
          const btn = document.createElement('button')
          btn.type = 'button'
          btn.className = 'day-modal-note-link'
          btn.textContent = noteDisplayTitle(note)
          btn.addEventListener('click', () => openNoteModal(note.id))
          li.appendChild(btn)
          list.appendChild(li)
        })
        noteDayModal.hidden = false
      }

      function compareTodosByDayTime(a, b) {
        const aHasTime = Boolean(a.startTime)
        const bHasTime = Boolean(b.startTime)
        if (aHasTime !== bHasTime) return aHasTime ? 1 : -1
        if (aHasTime && bHasTime) {
          const aMin = timeToMinutes(a.startTime)
          const bMin = timeToMinutes(b.startTime)
          if (aMin !== null && bMin !== null && aMin !== bMin) return aMin - bMin
          const timeCmp = String(a.startTime).localeCompare(String(b.startTime))
          if (timeCmp !== 0) return timeCmp
        }
        const titleCmp = String(a.title || '').localeCompare(String(b.title || ''), 'ja')
        if (titleCmp !== 0) return titleCmp
        return (Number(a.id) || 0) - (Number(b.id) || 0)
      }

      function openDayModal(date) {
        const todos = TODO_ITEMS.filter((item) => inRange(date, item)).sort(compareTodosByDayTime)
        const dayNotes = notesForDate(date)
        if (todos.length === 0 && dayNotes.length === 0) return
        const list = document.getElementById('day-modal-list')
        list.innerHTML = ''
        document.getElementById('day-modal-title').textContent = @json(__(':date の予定')).replace(':date', date);
        todos.forEach((todo) => {
          const li = document.createElement('li')
          const btn = document.createElement('button')
          btn.type = 'button'
          btn.className = todo.completed ? 'done' : ''
          btn.textContent = `${todoPrefix(todo)}${todo.title}`
          btn.addEventListener('click', () => openTodoModal(todo.id))
          li.appendChild(btn)
          list.appendChild(li)
        })
        dayNotes.forEach((note) => {
          const li = document.createElement('li')
          const btn = document.createElement('button')
          btn.type = 'button'
          btn.className = 'day-modal-note-link'
          btn.textContent = `📝 ${noteDisplayTitle(note)}`
          btn.addEventListener('click', () => openNoteModal(note.id))
          li.appendChild(btn)
          list.appendChild(li)
        })
        todoModal.hidden = true
        noteDayModal.hidden = true
        noteModal.hidden = true
        dayModal.hidden = false
      }

      document.querySelectorAll('[data-todo-id]').forEach((el) => {
        el.addEventListener('click', (e) => {
          if (el.dataset.dragging === '1') {
            e.preventDefault()
            e.stopPropagation()
            return
          }
          e.stopPropagation()
          openTodoModal(el.dataset.todoId)
        })
      })

      document.querySelectorAll('[data-note-id]').forEach((el) => {
        el.addEventListener('click', (e) => {
          if (el.dataset.dragging === '1') {
            e.preventDefault()
            e.stopPropagation()
            return
          }
          e.stopPropagation()
          openNoteModal(el.dataset.noteId)
        })
      })

      // --- Drag & drop: ToDo を別日へ移動（メモは対象外） ---
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || editForm?.querySelector('input[name="_token"]')?.value
        || ''

      function setupDraggable(el) {
        el.setAttribute('draggable', 'true')
        el.addEventListener('dragstart', (e) => {
          el.dataset.dragging = '1'
          e.dataTransfer.setData('application/json', JSON.stringify({
            type: 'todo',
            id: Number(el.dataset.todoId),
          }))
          e.dataTransfer.effectAllowed = 'move'
          el.classList.add('is-dragging')
        })
        el.addEventListener('dragend', () => {
          el.classList.remove('is-dragging')
          setTimeout(() => {
            el.dataset.dragging = '0'
          }, 0)
        })
      }

      document.querySelectorAll('.event-chip[data-todo-id]').forEach((el) => setupDraggable(el))

      document.querySelectorAll('.calendar-day[data-date]').forEach((day) => {
        day.addEventListener('dragover', (e) => {
          e.preventDefault()
          e.dataTransfer.dropEffect = 'move'
          day.classList.add('is-drop-target')
        })
        day.addEventListener('dragleave', () => day.classList.remove('is-drop-target'))
        day.addEventListener('drop', async (e) => {
          e.preventDefault()
          day.classList.remove('is-drop-target')
          let payload = null
          try {
            payload = JSON.parse(e.dataTransfer.getData('application/json') || '{}')
          } catch (_) {
            return
          }
          const targetDate = day.dataset.date
          if (!targetDate || payload?.type !== 'todo' || !payload?.id) return

          try {
            const res = await fetch(`/todos/${payload.id}/reschedule`, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
              },
              body: JSON.stringify({ date: targetDate }),
            })
            const data = await res.json()
            if (!res.ok || !data.ok) {
              window.alert(data.message || @json(__('移動に失敗しました')));
              return
            }
            window.location.reload()
          } catch (_) {
            window.alert(@json(__('移動中に通信エラーが発生しました')));
          }
        })
      })

      document.querySelectorAll('.day-note-badge').forEach((el) => {
        el.addEventListener('click', (e) => {
          e.stopPropagation()
          openNoteDayModal(el.dataset.date)
        })
      })

      const eventTooltip = document.getElementById('event-tooltip')
      let tooltipHideTimer = null
      let currentTooltipSource = null
      let tooltipCopiedTimer = null

      function buildEventTooltipText(el) {
        const lines = [el.dataset.tipTitle]
        if (el.dataset.tipDate) lines.push(el.dataset.tipDate)
        if (el.dataset.tipTime) lines.push(el.dataset.tipTime)
        return lines.join('\n')
      }

      async function copyEventTooltipText(el) {
        const text = buildEventTooltipText(el)
        try {
          await navigator.clipboard.writeText(text)
        } catch {
          const ta = document.createElement('textarea')
          ta.value = text
          ta.setAttribute('readonly', '')
          ta.style.position = 'fixed'
          ta.style.left = '-9999px'
          document.body.appendChild(ta)
          ta.select()
          document.execCommand('copy')
          document.body.removeChild(ta)
        }
        const copyBtn = eventTooltip?.querySelector('.event-hover-tooltip-copy')
        if (copyBtn) copyBtn.textContent = @json(__('コピーしました'));
        if (eventTooltip) eventTooltip.classList.add('is-copied')
        clearTimeout(tooltipCopiedTimer)
        tooltipCopiedTimer = setTimeout(() => {
          if (copyBtn) copyBtn.textContent = @json(__('クリップボードにコピー'));
          eventTooltip?.classList.remove('is-copied')
        }, 1500)
      }

      function hideEventTooltip() {
        if (eventTooltip) {
          eventTooltip.hidden = true
          eventTooltip.classList.remove('is-copied')
          const copyBtn = eventTooltip.querySelector('.event-hover-tooltip-copy')
          if (copyBtn) copyBtn.textContent = @json(__('クリップボードにコピー'));
        }
        currentTooltipSource = null
      }

      function scheduleHideEventTooltip() {
        clearTimeout(tooltipHideTimer)
        tooltipHideTimer = setTimeout(hideEventTooltip, 150)
      }

      function cancelHideEventTooltip() {
        clearTimeout(tooltipHideTimer)
      }

      function moveEventTooltip(e) {
        if (!eventTooltip || eventTooltip.hidden) return
        const pad = 14
        const rect = eventTooltip.getBoundingClientRect()
        let left = e.clientX + pad
        let top = e.clientY + pad
        if (left + rect.width > window.innerWidth - 8) left = e.clientX - rect.width - pad
        if (top + rect.height > window.innerHeight - 8) top = e.clientY - rect.height - pad
        eventTooltip.style.left = `${Math.max(8, left)}px`
        eventTooltip.style.top = `${Math.max(8, top)}px`
      }

      function showEventTooltip(el, e) {
        if (!eventTooltip || !el.dataset.tipTitle) return
        currentTooltipSource = el
        eventTooltip.innerHTML = [
          `<span class="event-hover-tooltip-title">${escapeHtml(el.dataset.tipTitle)}</span>`,
          `<span class="event-hover-tooltip-date">${escapeHtml(el.dataset.tipDate || '')}</span>`,
          `<span class="event-hover-tooltip-time">${escapeHtml(el.dataset.tipTime || '—')}</span>`,
          `<button type="button" class="event-hover-tooltip-copy">${@json(__('クリップボードにコピー'))}</button>`
        ].join('')
        eventTooltip.hidden = false
        eventTooltip.classList.remove('is-copied')
        moveEventTooltip(e)
      }

      eventTooltip?.addEventListener('mouseenter', cancelHideEventTooltip)
      eventTooltip?.addEventListener('mouseleave', scheduleHideEventTooltip)
      eventTooltip?.addEventListener('click', (e) => {
        const copyBtn = e.target.closest('.event-hover-tooltip-copy')
        if (!copyBtn || !currentTooltipSource) return
        e.preventDefault()
        e.stopPropagation()
        copyEventTooltipText(currentTooltipSource)
      })

      document.querySelectorAll('.event-chip[data-tip-title]').forEach((el) => {
        el.addEventListener('mouseenter', (e) => {
          cancelHideEventTooltip()
          showEventTooltip(el, e)
        })
        el.addEventListener('mousemove', moveEventTooltip)
        el.addEventListener('mouseleave', scheduleHideEventTooltip)
      })

      document.querySelectorAll('.event-more').forEach((el) => {
        el.addEventListener('click', (e) => {
          e.stopPropagation()
          openDayModal(el.dataset.date)
        })
      })

      document.querySelectorAll('.undated-open').forEach((el) => {
        el.addEventListener('click', () => openTodoModal(el.dataset.todoId))
      })

      document.querySelectorAll('.mobile-agenda-item[data-todo-id]').forEach((el) => {
        el.addEventListener('click', (e) => {
          e.stopPropagation()
          openTodoModal(el.dataset.todoId)
        })
      })

      document.querySelectorAll('.calendar-day[data-date]').forEach((cell) => {
        cell.addEventListener('click', (e) => {
          if (e.target.closest('button, a, form, textarea, input, select')) return
          if (cell.dataset.inMonth !== '1') return
          const date = cell.dataset.date
          const todos = TODO_ITEMS.filter((item) => inRange(date, item))
          const dayNotes = notesForDate(date)
          if (todos.length > 0 || dayNotes.length > 0) {
            openDayModal(date)
            return
          }
          openQuickAddForDate(date)
        })
      })

      quickAddMemoBtn?.addEventListener('click', (e) => {
        e.preventDefault()
        e.stopPropagation()
        const date = quickAddStart?.value || quickAddDate
        if (date) openNoteComposeModal(date)
      })

      noteComposeModal?.querySelectorAll('.note-compose-color-dot').forEach((dot) => {
        dot.addEventListener('click', () => {
          const color = dot.dataset.color || 'default'
          if (noteComposeColor) noteComposeColor.value = color
          noteComposeModal.querySelectorAll('.note-compose-color-dot').forEach((el) => {
            el.classList.toggle('is-selected', el === dot)
          })
          applyNoteComposePalette(color)
        })
      })

      document.querySelectorAll('[data-close-modal]').forEach((el) => {
        el.addEventListener('click', closeModals)
      })

      const modalEnableTimeRange = editForm.querySelector('#modal-enable-time-range')
      const modalTimeRangePanel = editForm.querySelector('#modal-time-range-panel')
      const modalStartTime = editForm.querySelector('#modal-start-time')
      const modalEndTime = editForm.querySelector('#modal-end-time')

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

      function syncModalTimeRange() {
        const on = modalEnableTimeRange.checked
        modalTimeRangePanel.classList.toggle('date-panel-hidden', !on)
        modalStartTime.disabled = !on
        modalEndTime.disabled = !on
        if (on) {
          if (!modalStartTime.value) {
            const range = defaultTimeRange()
            modalStartTime.value = range.start
            if (!modalEndTime.value) modalEndTime.value = range.end
          }
        } else {
          modalStartTime.value = ''
          modalEndTime.value = ''
        }
      }

      modalEnableTimeRange.addEventListener('change', syncModalTimeRange)
      modalStartTime.addEventListener('change', () => {
        if (!modalEndTime.value || modalEndTime.value < modalStartTime.value) {
          modalEndTime.value = modalStartTime.value
        }
      })

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeModals()
      })

      document.querySelectorAll('.day-add-btn').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation()
          openQuickAddForDate(btn.dataset.date)
        })
      })
    </script>
  </body>
</html>
