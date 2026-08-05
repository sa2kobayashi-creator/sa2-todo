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
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ @filemtime(public_path('app.css')) ?: time() }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'dashboard'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
      @if(!empty($appContextIsWork))
        <div class="banner notice">
          {{ __('仕事モード: プライベートの ToDo／メモは表示されません。') }}
          @if(empty($googleCalendarConnected))
            <a href="/settings?section=integration#google-calendar">{{ __('Googleカレンダーを連携') }}</a>
          @else
            <a href="/settings?section=integration#google-calendar">{{ __('カレンダー選択・取込') }}</a>
          @endif
        </div>
      @endif

      @include('dashboard.partials.ai-usage')
      @include('dashboard.partials.travel')

      <div class="calendar-shell" data-calendar-view="{{ $view }}">
        @include('partials.calendar-nav-toolbar', ['showMemoTodoActions' => true])

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
                  $cellData = $limitTodosForCell($cell['todos'] ?? [], 6);
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
                        @if(!empty($todo['googleMeetLink'])) data-tip-meet="{{ $todo['googleMeetLink'] }}" @endif
                      >
                        <span class="event-title">{{ $truncateTitle($todo['title']) }}</span>
                        @if(!empty($todo['googleMeetLink']))
                          <span class="event-meet-badge" aria-label="Google Meet">Meet</span>
                        @endif
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
                      @php
                        $chipTimeLabelMobile = '—';
                        if (!empty($todo['startTime'])) {
                          $chipTimeLabelMobile = (!empty($todo['endTime']) && $todo['endTime'] !== $todo['startTime'])
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
                        data-tip-time="{{ $chipTimeLabelMobile }}"
                        @if(!empty($todo['googleMeetLink'])) data-tip-meet="{{ $todo['googleMeetLink'] }}" @endif
                      >
                        <span class="event-title">{{ $truncateTitle($todo['title']) }}</span>
                        @if(!empty($todo['googleMeetLink']))
                          <span class="event-meet-badge" aria-label="Google Meet">Meet</span>
                        @endif
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
        @include('partials.calendar-height-resizer')
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
                      @if(!empty($todo['googleMeetLink']))
                        · Google Meet
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

    @include('partials.todo-edit-modal', [
      'modalReturnTo' => $returnTo,
      'showDashboardMemoButton' => true,
    ])

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
                  @class([
                    'note-color-dot',
                    'note-compose-color-dot',
                    'is-selected' => $key === 'default',
                  ])
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

    <script>
      const TODO_ITEMS = {!! \Illuminate\Support\Js::from($todosForJs ?? []) !!};
      const NOTE_ITEMS = {!! \Illuminate\Support\Js::from($notesForJs ?? []) !!};
      const NOTE_COLORS = {!! \Illuminate\Support\Js::from($noteColors) !!};
      const RETURN_TO = {!! \Illuminate\Support\Js::from($returnTo) !!};
      const CALENDAR_VIEW = {!! \Illuminate\Support\Js::from($view) !!};

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
      const editForm = document.getElementById('todo-edit-form')
      const deleteForm = document.getElementById('todo-delete-form')
      const todoModalCopyBtn = document.getElementById('todo-modal-copy')
      const todoModalMemoBtn = document.getElementById('todo-modal-memo-btn')
      const todoModalSaveBtn = document.getElementById('todo-modal-save')
      let editingTodoId = null

      function findTodo(id) {
        const key = String(id)
        return TODO_ITEMS.find((item) => String(item.id) === key)
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

      function pad2(n) {
        return String(n).padStart(2, '0')
      }

      function openTodoCreateModal(date, timeHour, timeMinute) {
        if (!editForm || !todoModal || !date) return
        quickAddDate = date
        closeModals()
        hideEventTooltip()
        editingTodoId = null
        Array.from(editForm.elements).forEach((el) => { el.disabled = false })
        const hasTime = timeHour != null && Number.isFinite(Number(timeHour))
        const hour = hasTime ? Number(timeHour) : 9
        const minute = hasTime && Number.isFinite(Number(timeMinute)) ? Number(timeMinute) : 0
        const endHour = (hour + 1) % 24
        fillTodoModalFields({
          title: '',
          startDate: date,
          endDate: date,
          startTime: hasTime ? pad2(hour) + ':' + pad2(minute) : '',
          endTime: hasTime ? pad2(endHour) + ':' + pad2(minute) : '',
          importance: 'medium',
          category: 'task',
          completed: false,
          reminders: [],
          reminderTime: '',
          notifyVia: null,
          groupId: null,
        })
        setTodoModalMode('create')
        if (todoModalTitle) {
          todoModalTitle.textContent = @json(__(':date に ToDo を追加')).replace(':date', date);
        }
        todoModal.hidden = false
        editForm.querySelector('[name=title]')?.focus()
      }

      function openQuickAddForDate(date, timeHour, timeMinute) {
        openTodoCreateModal(date, timeHour, timeMinute)
      }

      function inRange(date, todo) {
        if (!todo.startDate && !todo.endDate) return false
        const start = todo.startDate || todo.endDate
        const end = todo.endDate || todo.startDate
        return date >= start && date <= end
      }

      const IMPORTANCE_LABELS = {!! \Illuminate\Support\Js::from($importanceLabels) !!};
      const CATEGORY_LABELS = {!! \Illuminate\Support\Js::from($categoryLabels) !!};

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
        if (!editForm) return
        const titleEl = editForm.querySelector('[name=title]')
        if (titleEl) titleEl.value = todo.title || ''
        const startDateEl = editForm.querySelector('#modal-start-date')
        const endDateEl = editForm.querySelector('#modal-end-date')
        if (startDateEl) startDateEl.value = todo.startDate || ''
        if (endDateEl) endDateEl.value = todo.endDate || todo.startDate || ''
        const enableTimeRange = editForm.querySelector('#modal-enable-time-range')
        const timeRangePanel = editForm.querySelector('#modal-time-range-panel')
        const startTimeInput = editForm.querySelector('#modal-start-time')
        const endTimeInput = editForm.querySelector('#modal-end-time')
        const hasTime = Boolean(todo.startTime)
        if (enableTimeRange) enableTimeRange.checked = hasTime
        timeRangePanel?.classList.toggle('date-panel-hidden', !hasTime)
        if (startTimeInput) {
          startTimeInput.disabled = !hasTime
          startTimeInput.value = todo.startTime || ''
        }
        if (endTimeInput) {
          endTimeInput.disabled = !hasTime
          endTimeInput.value = todo.endTime || ''
        }
        const importanceEl = editForm.querySelector('#modal-importance')
        const categoryEl = editForm.querySelector('#modal-category')
        const completedEl = editForm.querySelector('[name=completed], [data-completed-input]')
        if (importanceEl) importanceEl.value = todo.importance || 'medium'
        if (categoryEl) categoryEl.value = todo.category || 'task'
        if (completedEl) completedEl.checked = !!todo.completed
        const reminderVals = Array.isArray(todo.reminders) ? todo.reminders.map(String) : []
        const uiKeys = new Set()
        let reminderTime = todo.reminderTime ? String(todo.reminderTime) : ''
        reminderVals.forEach((key) => {
          if (key === 'at9am') {
            uiKeys.add('at_time')
            if (!reminderTime) reminderTime = '09:00'
            return
          }
          const atMatch = String(key).match(/^at:(\d{2}:\d{2})$/)
          if (atMatch) {
            uiKeys.add('at_time')
            if (!reminderTime) reminderTime = atMatch[1]
            return
          }
          uiKeys.add(key)
        })
        editForm.querySelectorAll('[data-modal-reminder]').forEach((cb) => {
          cb.checked = uiKeys.has(String(cb.value))
        })
        const reminderTimeEl = editForm.querySelector('[data-modal-reminder-time]')
        if (reminderTimeEl) reminderTimeEl.value = reminderTime
        const notify = todo.notifyVia ? String(todo.notifyVia) : ''
        editForm.querySelectorAll('[data-modal-notify]').forEach((radio) => {
          radio.checked = notify !== '' && radio.value === notify
        })
        const groupEl = editForm.querySelector('#modal-group-id')
        if (groupEl) groupEl.value = todo.groupId != null && todo.groupId !== '' ? String(todo.groupId) : ''
        const isSingle = !todo.startDate || !todo.endDate || todo.startDate === todo.endDate
        syncModalDateMode(isSingle ? 'single' : 'range')

        const meetEl = document.getElementById('modal-meet-link')
        if (meetEl) {
          if (todo.googleMeetLink) {
            meetEl.hidden = false
            const label = @json(__('Google Meet'));
            const url = escapeHtml(todo.googleMeetLink);
            meetEl.innerHTML = `${label}: <a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`;
          } else {
            meetEl.hidden = true;
            meetEl.textContent = '';
          }
        }
      }

      function setTodoModalMode(mode) {
        todoModalMode = mode
        const isCopy = mode === 'copy'
        const isCreate = mode === 'create'
        const isNew = isCopy || isCreate
        const titleLabel = document.getElementById('modal-title-label')
        const titleEl = editForm?.querySelector('[name=title]')
        const splitEl = document.getElementById('modal-split-by-line')
        const groupEl = document.getElementById('modal-group-id')
        if (todoModalTitle) {
          todoModalTitle.textContent = isCreate
            ? @json(__('ToDo を追加'))
            : (isCopy ? @json(__('ToDo をコピーして追加')) : @json(__('ToDo 編集')));
        }
        if (titleLabel) {
          titleLabel.textContent = isNew ? @json(__('タイトル（1行1件）')) : @json(__('タイトル'));
        }
        if (titleEl) {
          titleEl.placeholder = isNew
            ? (@json(__('買い物に行く')) + '\n' + @json(__('レポートを書く')))
            : ''
        }
        if (todoModalDeleteBtn) todoModalDeleteBtn.hidden = isNew
        if (todoModalCopyBtn) todoModalCopyBtn.hidden = isNew
        if (todoModalSaveBtn) {
          todoModalSaveBtn.hidden = false
          todoModalSaveBtn.textContent = isNew ? @json(__('追加')) : @json(__('保存'));
        }
        if (todoModalMemoBtn) todoModalMemoBtn.hidden = !isCreate
        if (modalCompletedLabel) modalCompletedLabel.classList.toggle('date-panel-hidden', isNew)
        if (splitEl) splitEl.disabled = !isNew
        if (groupEl) groupEl.disabled = !isNew
        if (isNew) {
          editForm.action = '/todos'
          const completed = editForm.querySelector('[name=completed]')
          if (completed) {
            completed.removeAttribute('name')
            completed.setAttribute('data-completed-input', '1')
          }
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
        if (!todo || !editForm) return
        hideEventTooltip()
        const isRemoteOnly = typeof todo.id === 'string' && String(todo.id).startsWith('gcal:')
        editingTodoId = todo.id
        dayModal.hidden = true
        noteModal.hidden = true
        noteDayModal.hidden = true
        const saveBtn = document.getElementById('todo-modal-save')
        try {
          if (isRemoteOnly) {
            // 未取込の Google 予定: 編集不可。Meet / カレンダーリンクのみ
            editForm.action = '#'
            if (deleteForm) deleteForm.action = '#'
            fillTodoModalFields(todo)
            setTodoModalMode('edit')
            if (todoModalTitle) todoModalTitle.textContent = @json(__('Google 予定（表示のみ）'));
            if (todoModalDeleteBtn) todoModalDeleteBtn.hidden = true;            if (saveBtn) saveBtn.hidden = true
            if (todoModalCopyBtn) todoModalCopyBtn.hidden = true
            Array.from(editForm.elements).forEach((el) => {
              if (el.name === 'returnTo' || el.name === '_token') return
              el.disabled = true
            })
          } else {
            editForm.action = `/todos/${todo.id}/update`
            if (deleteForm) deleteForm.action = `/todos/${todo.id}/delete`
            Array.from(editForm.elements).forEach((el) => { el.disabled = false })
            if (saveBtn) saveBtn.hidden = false
            if (todoModalCopyBtn) todoModalCopyBtn.hidden = false
            fillTodoModalFields(todo)
            setTodoModalMode('edit')
          }
          todoModal.hidden = false
        } catch (err) {
          console.error('openTodoModal failed', err)
        }
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
          // 実際にドラッグ移動した場合だけクリックを無視（単純クリックは常に開く）
          if (el.dataset.dragMoved === '1') {
            el.dataset.dragMoved = '0'
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
        const todoId = el.getAttribute('data-todo-id')
        if (todoId && !/^\d+$/.test(todoId)) {
          return
        }
        el.setAttribute('draggable', 'true')
        let startX = 0
        let startY = 0
        el.addEventListener('dragstart', (e) => {
          startX = e.clientX
          startY = e.clientY
          el.dataset.dragging = '1'
          el.dataset.dragMoved = '0'
          e.dataTransfer.setData('application/json', JSON.stringify({
            type: 'todo',
            id: Number(el.dataset.todoId),
          }))
          e.dataTransfer.effectAllowed = 'move'
          el.classList.add('is-dragging')
        })
        el.addEventListener('drag', (e) => {
          if (e.clientX === 0 && e.clientY === 0) return
          if (Math.abs(e.clientX - startX) > 4 || Math.abs(e.clientY - startY) > 4) {
            el.dataset.dragMoved = '1'
          }
        })
        el.addEventListener('dragend', () => {
          el.classList.remove('is-dragging')
          el.dataset.dragging = '0'
          // drop 後のゴーストクリックだけ抑止。単純クリックは dragMoved=0 のまま
          if (el.dataset.dragMoved === '1') {
            setTimeout(() => {
              el.dataset.dragMoved = '0'
            }, 50)
          }
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
        if (el.dataset.tipMeet) lines.push('Meet: ' + el.dataset.tipMeet)
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
          el.dataset.tipMeet
            ? `<a class="event-hover-tooltip-meet" href="${escapeHtml(el.dataset.tipMeet)}" target="_blank" rel="noopener noreferrer">Google Meet</a>`
            : '',
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
        if (copyBtn && currentTooltipSource) {
          e.preventDefault()
          e.stopPropagation()
          copyEventTooltipText(currentTooltipSource)
          return
        }
        if (e.target.closest('a')) return
        // ツールチップ本体クリックでも予定を開く（チップ上に被ってクリックが届かない対策）
        if (currentTooltipSource?.dataset?.todoId) {
          e.preventDefault()
          e.stopPropagation()
          openTodoModal(currentTooltipSource.dataset.todoId)
        }
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

      todoModalMemoBtn?.addEventListener('click', (e) => {
        e.preventDefault()
        e.stopPropagation()
        const date = editForm?.querySelector('#modal-start-date')?.value || quickAddDate
        if (date) openNoteComposeModal(date)
      })

      document.addEventListener('input', (e) => {
        const timeInput = e.target?.closest?.('input[name="reminderTime"]')
        if (!timeInput || !timeInput.value) return
        const wrap = timeInput.closest('.notify-at-time-option')
        const checkbox = wrap?.querySelector('input[value="at_time"]')
        if (checkbox) checkbox.checked = true
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

      const modalEnableTimeRange = editForm?.querySelector('#modal-enable-time-range')
      const modalTimeRangePanel = editForm?.querySelector('#modal-time-range-panel')
      const modalStartTime = editForm?.querySelector('#modal-start-time')
      const modalEndTime = editForm?.querySelector('#modal-end-time')

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
          openTodoCreateModal(btn.dataset.date)
        })
      })

      document.querySelectorAll('.cal-day-canvas[data-date], .cal-week-allday-col[data-date]').forEach((el) => {
        el.addEventListener('click', (e) => {
          if (e.target.closest('button, a, form, textarea, input, select')) return
          const hourLine = e.target.closest('.cal-hour-line')
          const hour = hourLine ? Number(hourLine.dataset.hour) : null
          openTodoCreateModal(el.dataset.date, Number.isFinite(hour) ? hour : null, 0)
        })
      })
    </script>
    @include('partials.calendar-height-resizer-script')
    @include('partials.accordion-state')
  </body>
</html>
