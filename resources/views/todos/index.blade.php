<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>Todo - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'todos'])
    <main class="page-main">
      @if($notice)<div class="banner notice">{{ $notice }}</div>@endif
      @if($error)<div class="banner error">{{ $error }}</div>@endif

      <div class="panel">
        <h2>ToDo を追加（複数行可）</h2>
        <p class="hint">改行ごとに1件ずつ登録。日付は単日または期間を選べます。期間モードでは曜日を指定すると、該当する日ごとに ToDo を作成します。定休日の設定は <a href="/settings?section=holidays#weekday-holidays">設定 → 休日設定</a> で行います。</p>
        <form class="add" method="post" action="/todos" id="add-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $listReturnTo }}" />
          <textarea id="titles-input" name="titles" placeholder="買い物に行く&#10;レポートを書く" required></textarea>
          <div class="add-form-grid">
            <div class="form-grid-row form-grid-row-top">
              <span class="field-label">指定方法</span>
              <label class="radio-inline">
                <input type="radio" name="dateMode" value="single" checked />
                単日
              </label>
              <label class="radio-inline">
                <input type="radio" name="dateMode" value="range" />
                期間
              </label>
              <span class="form-grid-spacer"></span>
            </div>
            <div class="form-grid-row form-grid-row-labels">
              <span class="field-label" id="label-start">開始日</span>
              <span class="field-label" id="label-end">終了日</span>
              <span class="field-label">重要度</span>
              <span class="field-label">ステータス</span>
            </div>
            <div class="form-grid-row form-grid-row-inputs">
              <div class="form-grid-cell">
                <input type="date" name="startDate" id="start-date" value="{{ $defaultStartDate }}" aria-label="開始日" />
              </div>
              <div class="form-grid-cell" id="cell-end">
                <input type="date" name="endDate" id="end-date" value="{{ $defaultEndDate }}" aria-label="終了日" />
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
              <legend>登録する曜日（期間内の該当日のみ）</legend>
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
                祝日を除く（日本の祝日のみ）
              </label>
              <label class="weekday-check exclude-holiday-check">
                <input type="checkbox" id="exclude-closures" class="add-exclude-closures" checked />
                <input type="hidden" name="excludeClosures" id="exclude-closures-value" value="1" />
                休業日を除く（会社休業日・定休日）
              </label>
              <p class="hint inline-hint">未選択の場合は、期間全体を1件の ToDo として登録します。「祝日を除く」は日本の祝日（例: 山の日）のみ、「休業日を除く」は会社休業日・定休日のみです（PH祝日は除きません）。既に登録済みの予定は自動では消えません。</p>
            </fieldset>
            <div class="schedule-option">
              <label class="schedule-toggle">
                <input type="checkbox" id="enable-time-range" />
                時間帯を追加
              </label>
              <div class="time-range-panel date-panel-hidden" id="time-range-panel">
                <div class="time-range-inputs">
                  <input type="time" name="startTime" id="todo-start-time" aria-label="開始時刻" disabled />
                  <span class="time-range-separator" aria-hidden="true">～</span>
                  <input type="time" name="endTime" id="todo-end-time" aria-label="終了時刻" disabled />
                </div>
              </div>
            </div>
            <fieldset class="reminder-checkboxes">
              <legend>通知タイミング</legend>
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
              <legend>通知方法</legend>
              <div class="notify-via-row">
                @foreach($notifyViaOptions as $key)
                  <label class="notify-via-option">
                    <input type="radio" name="notifyVia" value="{{ $key }}" />
                    {{ $notifyViaLabels[$key] }}
                  </label>
                @endforeach
              </div>
              <p class="hint inline-hint">通知タイミングを選ぶ場合は、いずれか1つを選択してください。</p>
            </fieldset>
          </div>
          <label class="split-option">
            <input type="checkbox" id="split-by-line" name="splitByLine" value="1" checked />
            改行ごとに別の ToDo として登録する
          </label>
          <div class="preview" id="line-preview" hidden>
            <h3>登録プレビュー（<span id="preview-count">0</span>件）</h3>
            <ol id="preview-list"></ol>
          </div>
          <div class="form-actions">
            <button type="submit">追加</button>
          </div>
        </form>
      </div>

      <div class="panel" id="todo-list-panel">
        <form class="filter-bar" method="get" action="/todos#todo-list-panel" id="filter-form">
          @if(($displayMode ?? 'list') === 'calendar')
            <input type="hidden" name="display" value="calendar" />
          @endif
          <div class="filter-group filter-group-period">
            <label>期間@if(($filters['scope'] ?? '') === 'today') <span class="filter-scope-hint">（今日で表示中）</span>@endif</label>
            <div class="filter-period-mode" role="group" aria-label="期間の指定方法">
              <label class="radio-inline filter-period-mode-option">
                <input type="radio" name="periodMode" value="month" @checked(($filters['periodMode'] ?? $periodMode ?? 'month') === 'month') />
                月
              </label>
              <label class="radio-inline filter-period-mode-option">
                <input type="radio" name="periodMode" value="year" @checked(($filters['periodMode'] ?? '') === 'year' || ($filters['scope'] ?? '') === 'year' || ($periodMode ?? '') === 'year') />
                年
              </label>
            </div>
            <input
              type="month"
              name="period"
              id="filter-period-month"
              class="filter-period-input"
              value="{{ ($filters['scope'] ?? '') === 'today' ? '' : ($periodValue ?? '') }}"
              @if(($filters['scope'] ?? '') === 'today') placeholder="未設定" @endif
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
              aria-label="年"
            />
          </div>
          <div class="filter-group">
            <label>完了</label>
            <select name="status">
              <option value="all" @selected(($filters['status'] ?? 'all') === 'all')>すべて</option>
              <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>未完了</option>
              <option value="done" @selected(($filters['status'] ?? '') === 'done')>完了</option>
            </select>
          </div>
          <div class="filter-group filter-group-categories">
            <label for="filter-category-trigger">ステータス</label>
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
                  @if(empty($filters['categories']))すべて@else
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
            <label>重要度</label>
            <select name="importance">
              <option value="all" @selected(($filters['importance'] ?? 'all') === 'all')>すべて</option>
              @foreach($importanceLabels as $value => $label)
                <option value="{{ $value }}" @selected(($filters['importance'] ?? '') === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="filter-bar-actions">
            <button type="submit" class="secondary">絞り込み</button>
            <a
              href="{{ $todayFilterHref }}"
              class="filter-today-btn {{ ($filters['scope'] ?? '') === 'today' ? 'active' : '' }}"
              id="filter-today-btn"
            >今日</a>
            <a
              href="{{ $clearFiltersHref ?? '/todos#todo-list-panel' }}"
              class="filter-clear-btn"
              id="filter-clear-btn"
            >クリア</a>
          </div>
        </form>
        @if(($filters['scope'] ?? '') === 'today' && !empty($filters['todayDate']))
          <p class="hint filter-today-note">表示中: {{ $filters['todayDate'] }} の予定</p>
        @elseif(($filters['scope'] ?? '') === 'year')
          <p class="hint filter-period-note">表示中: {{ $filters['year'] }}年</p>
        @endif

        <input type="hidden" id="bulk-return-to" value="{{ $listReturnTo }}" />

        <div class="list-toolbar">
          <h2>
            @if(($displayMode ?? 'list') === 'calendar')
              カレンダー（{{ $calendarYear }}年{{ $calendarMonth }}月）
            @else
              一覧（{{ $pagination['total'] }}件）
            @endif
          </h2>
          <div class="todos-display-toggle" role="group" aria-label="表示切替">
            <a href="{{ $buildTodosQuery(['display' => null]) }}#todo-list-panel" class="{{ ($displayMode ?? 'list') === 'list' ? 'is-active' : '' }}">一覧</a>
            <a href="{{ $buildTodosQuery(['display' => 'calendar']) }}#todo-list-panel" class="{{ ($displayMode ?? '') === 'calendar' ? 'is-active' : '' }}">カレンダー</a>
          </div>
          @if(($displayMode ?? 'list') === 'list' && ($pagination['total'] ?? 0) > 0)
            <span class="todo-page-summary">{{ $pagination['total'] }}件中 {{ ($pagination['page'] - 1) * $pagination['perPage'] + 1 }}〜{{ min($pagination['page'] * $pagination['perPage'], $pagination['total']) }}件を表示</span>
          @endif
          @if(($displayMode ?? 'list') === 'list')
          <div class="bulk-actions">
            <button type="button" class="secondary" id="select-all-btn">全選択</button>
            <button type="button" class="secondary bulk-btn" data-bulk-url="/todos/bulk/complete">一括完了</button>
            <button type="button" class="secondary bulk-btn" data-bulk-url="/todos/bulk/uncomplete">一括で未完了</button>
            <button type="button" class="danger bulk-btn" data-bulk-url="/todos/bulk/delete" data-confirm="選択した ToDo を削除しますか？">一括削除</button>
            <button type="button" class="secondary bulk-btn" data-bulk-url="/todos/bulk/duplicate">コピー</button>
          </div>
          @else
            <p class="hint inline-hint">ドラッグ＆ドロップや詳細編集は <a href="{{ $dashboardMonthUrl }}">ダッシュボード</a> でも操作できます。</p>
          @endif
        </div>

        @if(($displayMode ?? 'list') === 'calendar')
          <div class="todos-calendar-panel calendar-month-view">
            <div class="calendar-weekdays">
              @foreach($weekdayLabels as $label)
                <div class="calendar-weekday">{{ $label }}</div>
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
                    >
                      <div class="day-header">
                        <span class="day-num">{{ $cell['day'] }}</span>
                        @if(count($cellNotes) > 0)
                          <a
                            class="day-note-badge"
                            href="/notes?date={{ $cell['date'] }}"
                            title="メモ {{ count($cellNotes) }}件"
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
                          <a
                            class="event-chip category-{{ $todo['category'] ?? 'task' }} importance-{{ $todo['importance'] ?? 'medium' }} {{ !empty($todo['completed']) ? 'done' : '' }}"
                            href="{{ $dashboardMonthUrl }}"
                            title="{{ $todo['title'] }}（{{ $formatPeriodLabel($todo) }}）"
                            draggable="true"
                            data-todo-id="{{ $todo['id'] }}"
                          >
                            <span class="event-title">{{ $truncateTitle($todo['title']) }}</span>
                          </a>
                        @endforeach
                        @if(($cellData['hiddenCount'] ?? 0) > 0)
                          <span class="event-more">他 {{ $cellData['hiddenCount'] }} 件</span>
                        @endif
                      </div>
                    </div>
                  @endforeach
                </div>
              @endforeach
            </div>
          </div>
          <script>
            (function () {
              const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
              function setupDraggable(el) {
                el.addEventListener('dragstart', (e) => {
                  el.dataset.dragging = '1'
                  e.dataTransfer.setData('application/json', JSON.stringify({
                    type: 'todo',
                    id: Number(el.dataset.todoId),
                  }))
                  e.dataTransfer.effectAllowed = 'move'
                })
                el.addEventListener('dragend', () => {
                  setTimeout(() => { el.dataset.dragging = '0' }, 0)
                })
                el.addEventListener('click', (e) => {
                  if (el.dataset.dragging === '1') e.preventDefault()
                })
              }
              document.querySelectorAll('.todos-calendar-panel [data-todo-id]').forEach((el) => setupDraggable(el))
              document.querySelectorAll('.todos-calendar-panel .calendar-day[data-date]').forEach((day) => {
                day.addEventListener('dragover', (e) => {
                  e.preventDefault()
                  day.classList.add('is-drop-target')
                })
                day.addEventListener('dragleave', () => day.classList.remove('is-drop-target'))
                day.addEventListener('drop', async (e) => {
                  e.preventDefault()
                  day.classList.remove('is-drop-target')
                  let payload
                  try { payload = JSON.parse(e.dataTransfer.getData('application/json') || '{}') } catch (_) { return }
                  if (payload.type !== 'todo' || !payload.id || !day.dataset.date) return
                  const res = await fetch(`/todos/${payload.id}/reschedule`, {
                    method: 'POST',
                    headers: {
                      'Content-Type': 'application/json',
                      Accept: 'application/json',
                      'X-CSRF-TOKEN': csrf,
                      'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ date: day.dataset.date }),
                  })
                  const data = await res.json().catch(() => ({}))
                  if (!res.ok || !data.ok) {
                    alert(data.message || '移動に失敗しました')
                    return
                  }
                  location.reload()
                })
              })
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
          <nav class="todo-pagination" aria-label="ToDo 一覧のページ">
            @if($pagination['page'] > 1)
              <a class="button-link secondary" href="{{ $buildTodosQuery(['page' => $pagination['page'] - 1]) }}#todo-list-panel">‹ 前へ</a>
            @endif
            <span class="todo-pagination-label">{{ $pagination['page'] }} / {{ $pagination['totalPages'] }}</span>
            @if($pagination['page'] < $pagination['totalPages'])
              <a class="button-link secondary" href="{{ $buildTodosQuery(['page' => $pagination['page'] + 1]) }}#todo-list-panel">次へ ›</a>
            @endif
          </nav>
        @endif
        @if(count($todos) === 0)
          <p class="empty-msg">条件に一致する ToDo がありません。</p>
        @endif

        @if(count($undatedTodos) > 0)
          <h3 class="undated-heading">期間未設定（{{ count($undatedTodos) }}件）</h3>
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
      let nationalHolidayDatesCache = new Set(@json($nationalHolidayDates ?? []))
      let closureDatesCache = new Set(@json($closureDates ?? []))

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
        if (labelStart) labelStart.textContent = isRange ? '開始日' : '日付'
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
              window.alert('祝日または休業日を除く場合は曜日を選択してください')
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
        selectAllBtn.textContent = shouldCheck ? '全解除' : '全選択'
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
            ? 'すべて'
            : checked.map((cb) => cb.dataset.label || cb.value).join('、')
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
            window.alert('対象が選択されていません')
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
            idInput.name = 'ids'
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
    </script>
  </body>
</html>
