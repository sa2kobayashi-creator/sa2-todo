<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <title>ダッシュボード - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'dashboard'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="calendar-shell">
        <div class="calendar-toolbar">
          <div class="nav-group">
            <a class="button-link secondary icon-btn" href="/dashboard?year={{ $prev['year'] }}&month={{ $prev['month'] }}">‹</a>
            <a class="button-link secondary" href="/dashboard">今日</a>
            <a class="button-link secondary icon-btn" href="/dashboard?year={{ $next['year'] }}&month={{ $next['month'] }}">›</a>
          </div>
          <div class="month-label">{{ $year }}年{{ $month }}月</div>
          <div class="calendar-toolbar-links">
            <a class="button-link secondary" href="/notes">メモ</a>
            <a class="button-link" href="/todos">Todo 管理へ</a>
          </div>
        </div>

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
                  $cellMobile = $limitCellItems($cell['todos'] ?? [], $cellNotes, 4);
                  $cellMobileVisible = $cellMobile['visible'] ?? [];
                  $cellMobileHidden = $cellMobile['hiddenCount'] ?? 0;
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
                          class="day-note-badge desktop-only"
                          data-date="{{ $cell['date'] }}"
                          title="メモ {{ count($cellNotes) }}件"
                          aria-label="{{ $cell['date'] }} のメモ {{ count($cellNotes) }}件"
                        >
                          <span class="day-note-badge-icon" aria-hidden="true">📝</span>
                          @if(count($cellNotes) > 1)
                            <span class="day-note-badge-count">{{ count($cellNotes) }}</span>
                          @endif
                        </button>
                      @endif
                      <button type="button" class="day-add-btn" data-date="{{ $cell['date'] }}" title="ToDo を追加">+</button>
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
                        class="event-chip category-{{ $todo['category'] }} importance-{{ $todo['importance'] }} @class(['done' => !empty($todo['completed']), 'is-range' => ($todo['startDate'] ?? null) !== ($todo['endDate'] ?? null)])"
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
                        他 {{ $cellData['hiddenCount'] }} 件
                      </button>
                    @endif
                  </div>
                  <div class="day-events day-events-mobile">
                    @foreach($cellMobileVisible as $item)
                      @if(($item['kind'] ?? '') === 'todo')
                        @php $todo = $item['todo']; @endphp
                        <button
                          type="button"
                          class="event-chip category-{{ $todo['category'] }} importance-{{ $todo['importance'] }} @class(['done' => !empty($todo['completed']), 'is-range' => ($todo['startDate'] ?? null) !== ($todo['endDate'] ?? null)])"
                          data-todo-id="{{ $todo['id'] }}"
                        >
                          <span class="event-title">{{ $truncateTitle($todo['title']) }}</span>
                        </button>
                      @else
                        @php
                          $note = $item['note'];
                          $notePalette = $noteColors[$note['color'] ?? 'default'] ?? $noteColors['default'];
                        @endphp
                        <button
                          type="button"
                          class="event-chip note-chip"
                          data-note-id="{{ $note['id'] }}"
                          style="--note-bg: {{ $notePalette['bg'] }}; --note-border: {{ $notePalette['border'] }}"
                        >
                          <span class="event-title">📝 {{ $truncateTitle($getNoteDisplayTitle($note)) }}</span>
                        </button>
                      @endif
                    @endforeach
                    @if(($cellMobileHidden ?? 0) > 0)
                      <button type="button" class="event-more" data-date="{{ $cell['date'] }}">
                        他 {{ $cellMobileHidden }} 件
                      </button>
                    @endif
                  </div>
                  <div class="quick-add" id="quick-{{ $cell['date'] }}" hidden>
                    <form method="post" action="/todos">
          @csrf
                      <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                      <input type="hidden" name="startDate" value="{{ $cell['date'] }}" />
                      <input type="hidden" name="endDate" value="{{ $cell['date'] }}" />
                      <input type="hidden" name="splitByLine" value="1" />
                      <textarea name="titles" placeholder="1行1件で入力" required></textarea>
                      <div class="quick-add-actions">
                        <button type="submit">追加</button>
                        <a class="button-link secondary" href="/notes?date={{ $cell['date'] }}">メモを追加</a>
                        <button type="button" class="secondary quick-cancel" data-date="{{ $cell['date'] }}">閉じる</button>
                      </div>
                    </form>
                  </div>
                </div>
              @endforeach
            </div>
          @endforeach
        </div>
      </div>

      <section class="mobile-month-agenda panel" aria-label="{{ $month }}月の予定一覧">
        <h3>{{ $month }}月の予定（{{ count($monthAgenda) }}件）</h3>
        @if(count($monthAgenda) === 0)
          <p class="hint">今月の予定はありません。カレンダーの + から ToDo を追加するか、<a href="/notes">メモ</a>を作成できます。</p>
        @else
          <ul class="mobile-agenda-list">
            @foreach($monthAgenda as $item)
              @if(($item['kind'] ?? '') === 'note')
                <li>
                  <button type="button" class="mobile-agenda-item mobile-agenda-note" data-note-id="{{ $item['note']['id'] }}">
                    <span class="mobile-agenda-date">{{ $getNoteRegisteredDate($item['note']) }}</span>
                    <span class="mobile-agenda-title">📝 {{ $getNoteDisplayTitle($item['note']) }}</span>
                    <span class="mobile-agenda-meta">メモ</span>
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
                        終日
                      @endif
                    </span>
                  </button>
                </li>
              @endif
            @endforeach
          </ul>
        @endif
      </section>

      @if(count($undated) > 0)
        <div class="panel calendar-sidebar">
          <h3>期間未設定（{{ count($undated) }}件）</h3>
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
          <h2 id="day-modal-title">この日の予定</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="閉じる">×</button>
        </div>
        <ul class="modal-day-list" id="day-modal-list"></ul>
      </div>
    </div>

    <div class="modal" id="todo-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="todo-modal-title">
        <div class="modal-header">
          <h2 id="todo-modal-title">ToDo 編集</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="閉じる">×</button>
        </div>
        <form method="post" id="todo-edit-form" class="modal-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <label>
            タイトル
            <textarea name="title" rows="4" required></textarea>
          </label>
          <label>
            開始日
            <input type="date" name="startDate" id="modal-start-date" />
          </label>
          <label>
            終了日
            <input type="date" name="endDate" id="modal-end-date" />
          </label>
          <div class="schedule-option">
            <label class="schedule-toggle">
              <input type="checkbox" id="modal-enable-time-range" />
              時間帯を追加
            </label>
            <div class="time-range-panel date-panel-hidden" id="modal-time-range-panel">
              <div class="time-range-inputs">
                <input type="time" name="startTime" id="modal-start-time" aria-label="開始時刻" disabled />
                <span class="time-range-separator" aria-hidden="true">～</span>
                <input type="time" name="endTime" id="modal-end-time" aria-label="終了時刻" disabled />
              </div>
            </div>
          </div>
          <label>
            重要度
            <select name="importance" id="modal-importance">
              @foreach($importanceLabels as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
              @endforeach
            </select>
          </label>
          <label>
            ステータス
            <select name="category" id="modal-category">
              @foreach($categoryLabels as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
              @endforeach
            </select>
          </label>
          <label class="inline-check">
            <input type="checkbox" name="completed" value="1" />
            完了
          </label>
        </form>
        <form method="post" id="todo-delete-form" class="modal-delete-form" onsubmit="return confirm('この ToDo を削除しますか？')">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
        </form>
        <div class="modal-actions">
          <button type="button" class="secondary" data-close-modal>キャンセル</button>
          <button type="submit" form="todo-edit-form">保存</button>
          <button type="submit" form="todo-delete-form" class="danger">削除</button>
        </div>
      </div>
    </div>

    <div class="modal" id="note-day-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="note-day-modal-title">
        <div class="modal-header">
          <h2 id="note-day-modal-title">この日のメモ</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="閉じる">×</button>
        </div>
        <ul class="modal-day-list note-day-list" id="note-day-modal-list"></ul>
      </div>
    </div>

    <div class="modal" id="note-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <div class="modal-dialog note-modal-dialog" role="dialog" aria-labelledby="note-modal-title">
        <div class="modal-header">
          <h2 id="note-modal-title">メモ</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="閉じる">×</button>
        </div>
        <p class="note-modal-meta" id="note-modal-meta"></p>
        <div class="note-modal-content" id="note-modal-content"></div>
        <div class="modal-actions">
          <button type="button" class="secondary" data-close-modal>閉じる</button>
        </div>
      </div>
    </div>

    <div class="modal modal-centered" id="quick-add-modal" hidden>
      <div class="modal-backdrop" data-close-modal></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="quick-add-modal-title">
        <div class="modal-header">
          <h2 id="quick-add-modal-title">ToDo を追加</h2>
          <button type="button" class="modal-close" data-close-modal aria-label="閉じる">×</button>
        </div>
        <form method="post" action="/todos" id="quick-add-modal-form" class="modal-form quick-add-modal-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <input type="hidden" name="startDate" id="quick-add-start" />
          <input type="hidden" name="endDate" id="quick-add-end" />
          <input type="hidden" name="splitByLine" value="1" />
          <label>
            タイトル（1行1件）
            <textarea name="titles" id="quick-add-titles" rows="6" placeholder="1行1件で入力" required></textarea>
          </label>
          <div class="modal-actions quick-add-modal-actions">
            <a class="button-link secondary" id="quick-add-memo-link" href="/notes">メモを追加</a>
            <button type="button" class="secondary" data-close-modal>閉じる</button>
            <button type="submit">追加</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      const TODO_ITEMS = @json($todosForJs ?? [])
      const NOTE_ITEMS = @json($notesForJs ?? [])
      const NOTE_COLORS = @json($noteColors)
      const RETURN_TO = @json($returnTo)

      let openQuickAdd = null
      const todoModal = document.getElementById('todo-modal')
      const dayModal = document.getElementById('day-modal')
      const noteDayModal = document.getElementById('note-day-modal')
      const noteModal = document.getElementById('note-modal')
      const quickAddModal = document.getElementById('quick-add-modal')
      const quickAddTitles = document.getElementById('quick-add-titles')
      const quickAddStart = document.getElementById('quick-add-start')
      const quickAddEnd = document.getElementById('quick-add-end')
      const quickAddMemoLink = document.getElementById('quick-add-memo-link')
      const editForm = document.getElementById('todo-edit-form')
      const deleteForm = document.getElementById('todo-delete-form')

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
          return first?.text || '（無題）'
        }
        const line = String(note.body || '').split(/\r\n|\r|\n/).find((row) => row.trim())
        return line?.trim() || '（無題）'
      }

      function notesForDate(date) {
        return NOTE_ITEMS.filter((note) => noteRegisteredDate(note) === date)
      }

      function closeModals() {
        todoModal.hidden = true
        dayModal.hidden = true
        noteDayModal.hidden = true
        noteModal.hidden = true
        if (quickAddModal) quickAddModal.hidden = true
      }

      function isMobileCalendar() {
        return window.matchMedia('(max-width: 768px)').matches
      }

      function openQuickAddForDate(date) {
        if (isMobileCalendar() && quickAddModal) {
          if (openQuickAdd) openQuickAdd.hidden = true
          openQuickAdd = null
          document.getElementById('quick-add-modal-title').textContent = `${date} に ToDo を追加`
          if (quickAddStart) quickAddStart.value = date
          if (quickAddEnd) quickAddEnd.value = date
          if (quickAddMemoLink) quickAddMemoLink.href = `/notes?date=${encodeURIComponent(date)}`
          if (quickAddTitles) quickAddTitles.value = ''
          todoModal.hidden = true
          dayModal.hidden = true
          noteDayModal.hidden = true
          noteModal.hidden = true
          quickAddModal.hidden = false
          quickAddTitles?.focus()
          return
        }
        const panel = document.getElementById(`quick-${date}`)
        if (!panel) return
        closeModals()
        if (openQuickAdd && openQuickAdd !== panel) openQuickAdd.hidden = true
        panel.hidden = !panel.hidden
        openQuickAdd = panel.hidden ? null : panel
        if (!panel.hidden) panel.querySelector('textarea')?.focus()
      }

      function inRange(date, todo) {
        if (!todo.startDate && !todo.endDate) return false
        const start = todo.startDate || todo.endDate
        const end = todo.endDate || todo.startDate
        return date >= start && date <= end
      }

      const IMPORTANCE_LABELS = @json($importanceLabels)
      const CATEGORY_LABELS = @json($categoryLabels)

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

      function openTodoModal(id) {
        const todo = findTodo(id)
        if (!todo) return
        dayModal.hidden = true
        noteModal.hidden = true
        noteDayModal.hidden = true
        editForm.action = `/todos/${todo.id}/update`
        deleteForm.action = `/todos/${todo.id}/delete`
        editForm.querySelector('[name=title]').value = todo.title
        editForm.querySelector('#modal-start-date').value = todo.startDate || ''
        editForm.querySelector('#modal-end-date').value = todo.endDate || ''
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
        editForm.querySelector('[name=completed]').checked = todo.completed
        todoModal.hidden = false
      }

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
        meta.push(`登録日: ${noteRegisteredDate(note)}`)
        if (note.pinned) meta.push('ピン留め')
        if (note.type === 'checklist') meta.push('チェックリスト')
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
            : '<p class="hint">本文はありません。</p>'
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
        document.getElementById('note-day-modal-title').textContent = `${date} のメモ`
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
        document.getElementById('day-modal-title').textContent = `${date} の予定`
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
        el.addEventListener('click', () => openTodoModal(el.dataset.todoId))
      })

      document.querySelectorAll('[data-note-id]').forEach((el) => {
        el.addEventListener('click', () => openNoteModal(el.dataset.noteId))
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
        if (copyBtn) copyBtn.textContent = 'コピーしました'
        if (eventTooltip) eventTooltip.classList.add('is-copied')
        clearTimeout(tooltipCopiedTimer)
        tooltipCopiedTimer = setTimeout(() => {
          if (copyBtn) copyBtn.textContent = 'クリップボードにコピー'
          eventTooltip?.classList.remove('is-copied')
        }, 1500)
      }

      function hideEventTooltip() {
        if (eventTooltip) {
          eventTooltip.hidden = true
          eventTooltip.classList.remove('is-copied')
          const copyBtn = eventTooltip.querySelector('.event-hover-tooltip-copy')
          if (copyBtn) copyBtn.textContent = 'クリップボードにコピー'
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
          '<button type="button" class="event-hover-tooltip-copy">クリップボードにコピー</button>'
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
        el.addEventListener('click', () => openDayModal(el.dataset.date))
      })

      document.querySelectorAll('.undated-open').forEach((el) => {
        el.addEventListener('click', () => openTodoModal(el.dataset.todoId))
      })

      document.querySelectorAll('.mobile-agenda-item[data-todo-id]').forEach((el) => {
        el.addEventListener('click', () => openTodoModal(el.dataset.todoId))
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

      document.querySelectorAll('[data-close-modal]').forEach((el) => {
        el.addEventListener('click', closeModals)
      })

      const modalEnableTimeRange = editForm.querySelector('#modal-enable-time-range')
      const modalTimeRangePanel = editForm.querySelector('#modal-time-range-panel')
      const modalStartTime = editForm.querySelector('#modal-start-time')
      const modalEndTime = editForm.querySelector('#modal-end-time')

      function syncModalTimeRange() {
        const on = modalEnableTimeRange.checked
        modalTimeRangePanel.classList.toggle('date-panel-hidden', !on)
        modalStartTime.disabled = !on
        modalEndTime.disabled = !on
        if (!on) {
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

      document.querySelectorAll('.quick-cancel').forEach((btn) => {
        btn.addEventListener('click', () => {
          const panel = document.getElementById(`quick-${btn.dataset.date}`)
          if (panel) panel.hidden = true
          openQuickAdd = null
        })
      })
    </script>
  </body>
</html>
