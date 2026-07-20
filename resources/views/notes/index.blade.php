<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('メモ') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="notes-page">
    @include('partials.header', ['active' => 'notes'])
    <main class="page-main notes-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      @if($filterDate)
        <p class="hint inline-hint notes-date-filter">
          {{ __('登録日:') }} <strong>{{ $filterDate }}</strong>
          <a href="{{ $buildNotesQuery($filters) }}">{{ __('日付フィルタを解除') }}</a>
        </p>
      @endif

      <div class="notes-top-actions">
        <form class="notes-filter-form" method="get" action="/notes" id="notes-filter-form">
          @if($showArchived)<input type="hidden" name="archived" value="1" />@endif
          @if($filterDate)<input type="hidden" name="date" value="{{ $filterDate }}" />@endif
          <label class="notes-period-label">
            {{ __('表示月') }}
            <input type="month" name="period" id="notes-period" value="{{ $periodValue }}" @disabled($filterDate) />
          </label>
          <label class="notes-search-label">
            <span class="visually-hidden">{{ __('メモを検索') }}</span>
            <input type="search" name="q" value="{{ $searchQuery }}" placeholder="{{ __('メモを検索') }}" aria-label="{{ __('メモを検索') }}" />
          </label>
          <label class="notes-category-filter">
            {{ __('カテゴリー') }}
            <select name="category" id="notes-category-filter" aria-label="{{ __('カテゴリー') }}">
              <option value="" @selected($filterCategory === '')>{{ __('すべて') }}</option>
              @foreach($noteCategories as $key => $label)
                <option value="{{ $key }}" @selected($filterCategory === $key)>{{ $label }}</option>
              @endforeach
            </select>
          </label>
        </form>
        @if($showArchived)
          <a href="{{ $buildNotesQuery(array_merge($filters, ['archived' => false])) }}" class="button-link secondary">{{ __('メモに戻る') }}</a>
        @else
          <a href="{{ $buildNotesQuery(array_merge($filters, ['archived' => true])) }}" class="button-link secondary">{{ __('アーカイブ') }}</a>
        @endif
      </div>

      <div class="notes-input-row">
        @if(!$showArchived)
          <section class="note-composer" id="note-composer">
            <form method="post" action="/notes" class="note-composer-form" id="note-composer-form">
          @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <input type="hidden" name="type" id="composer-type" value="text" />
              <input type="hidden" name="color" id="composer-color" value="default" />
              <div class="note-composer-collapsed" id="composer-collapsed" tabindex="0" role="button" aria-label="{{ __('メモを作成') }}">
                {{ __('メモを入力...') }}
              </div>
              <div class="note-composer-expanded date-panel-hidden" id="composer-expanded">
                <div class="note-composer-meta">
                  <label class="note-date-field">
                    <span class="field-label">{{ __('登録日') }}</span>
                    <input type="date" name="registeredDate" id="composer-registered-date" value="{{ $defaultRegisteredDate }}" />
                  </label>
                  <label class="note-category-field">
                    <span class="field-label">{{ __('カテゴリー') }}</span>
                    <select name="category" id="composer-category">
                      @foreach($noteCategories as $key => $label)
                        <option value="{{ $key }}" @selected($key === $defaultCategory)>{{ $label }}</option>
                      @endforeach
                    </select>
                  </label>
                </div>
                <input type="text" name="title" id="composer-title" placeholder="{{ __('タイトル') }}" autocomplete="off" />
                <div class="composer-text-panel" id="composer-text-panel">
                  <textarea name="body" id="composer-body" placeholder="{{ __('メモを入力...') }}" rows="4"></textarea>
                </div>
                <div class="composer-checklist-panel date-panel-hidden" id="composer-checklist-panel">
                  <div class="checklist-editor" id="composer-checklist"></div>
                  <button type="button" class="text-btn" id="composer-add-item">{{ __('項目を追加') }}</button>
                </div>
                <div class="note-composer-footer">
                  <div class="note-color-picker" role="group" aria-label="{{ __('色') }}">
                    @foreach($colorKeys as $key)
                      <button
                        type="button"
                        @class(['note-color-dot', 'is-selected' => $key === 'default'])
                        data-color="{{ $key }}"
                        style="--note-color: {{ $noteColors[$key]['bg'] }}; --note-border: {{ $noteColors[$key]['border'] }}"
                        title="{{ $noteColors[$key]['label'] }}"
                        aria-label="{{ $noteColors[$key]['label'] }}"
                      ></button>
                    @endforeach
                  </div>
                  <div class="note-composer-actions">
                    <button type="button" class="text-btn" id="composer-toggle-type">{{ __('チェックリスト') }}</button>
                    <button type="button" class="text-btn" id="composer-close">{{ __('閉じる') }}</button>
                    <button type="submit" class="button-link">{{ __('保存') }}</button>
                  </div>
                </div>
              </div>
            </form>
          </section>
        @endif

        <div class="notes-view-toggle" role="group" aria-label="{{ __('表示切替') }}">
          <button type="button" class="notes-view-btn is-active" data-view="gallery" title="{{ __('ギャラリー表示') }}" aria-pressed="true" aria-label="{{ __('ギャラリー表示') }}">⊞</button>
          <button type="button" class="notes-view-btn" data-view="list" title="{{ __('リスト表示') }}" aria-pressed="false" aria-label="{{ __('リスト表示') }}">☰</button>
        </div>
      </div>

      <div class="notes-bulk-bar panel" id="notes-bulk-bar">
        <input type="hidden" id="notes-bulk-return-to" value="{{ $returnTo }}" />
        <label class="note-bulk-select-all-label">
          <input type="checkbox" id="notes-select-all" />
          {{ __('全選択') }}
        </label>
        <div class="bulk-actions notes-bulk-actions">
          @if($showArchived)
            <button type="button" class="secondary notes-bulk-btn" data-bulk-url="/notes/bulk/archive" data-bulk-unarchive="1">{{ __('一括で戻す') }}</button>
          @else
            <button type="button" class="secondary notes-bulk-btn" data-bulk-url="/notes/bulk/archive">{{ __('一括アーカイブ') }}</button>
          @endif
          <button type="button" class="secondary" id="notes-bulk-edit-open">{{ __('一括編集') }}</button>
          <button type="button" class="danger notes-bulk-btn" data-bulk-url="/notes/bulk/delete" data-confirm="{{ __('選択したメモを削除しますか？') }}">{{ __('一括削除') }}</button>
        </div>
        <span class="notes-page-summary">{{ $pagination['total'] }}{{ __('件中') }} {{ $pagination['total'] === 0 ? 0 : ($pagination['page'] - 1) * $pagination['perPage'] + 1 }}〜{{ min($pagination['page'] * $pagination['perPage'], $pagination['total']) }}{{ __('件を表示') }}</span>
      </div>

      <div class="notes-content notes-view-gallery notes-cols-4" id="notes-content">
      @if(count($pinnedNotes) > 0 || count($otherNotes) > 0)
        <div class="notes-section-header">
          <h2 class="notes-section-title">
            @if(count($pinnedNotes) > 0)
              {{ __('ピン留め') }}
            @endif
          </h2>
            <div class="notes-cols-toggle" id="notes-cols-toggle" role="group" aria-label="{{ __('横の枚数') }}">
            @for($n = 1; $n <= 5; $n++)
              <button
                type="button"
                class="notes-cols-btn{{ $n === 4 ? ' is-active' : '' }}"
                data-cols="{{ $n }}"
                title="{{ __('横に') }}{{ $n }}{{ __('枚') }}"
                aria-pressed="{{ $n === 4 ? 'true' : 'false' }}"
                aria-label="{{ __('横に') }}{{ $n }}{{ __('枚') }}"
              >{{ $n }}</button>
            @endfor
          </div>
        </div>
      @endif

      @if(count($pinnedNotes) > 0)
        <div class="notes-grid">
          @foreach($pinnedNotes as $note)
            @include('partials.note-card', ['note' => $note, 'returnTo' => $returnTo, 'showArchived' => $showArchived, 'highlightId' => $highlightId])
          @endforeach
        </div>
      @endif

        @if(count($otherNotes) > 0)
        @if(count($pinnedNotes) > 0)<h2 class="notes-section-title">{{ __('その他') }}</h2>@endif
        <div class="notes-grid">
          @foreach($otherNotes as $note)
            @include('partials.note-card', ['note' => $note, 'returnTo' => $returnTo, 'showArchived' => $showArchived, 'highlightId' => $highlightId])
          @endforeach
        </div>
      @endif

      @if(count($pinnedNotes) === 0 && count($otherNotes) === 0)
        <p class="notes-empty">{{ $showArchived ? __('アーカイブされたメモはありません。') : __('メモがありません。上の欄から追加できます。') }}</p>
      @endif
      </div>

      @if(($pagination['totalPages'] ?? 1) > 1)
        <nav class="notes-pagination" aria-label="{{ __('メモ一覧のページ') }}">
          @if($pagination['page'] > 1)
            <a class="button-link secondary" href="{{ $buildNotesQuery($filters, ['page' => $pagination['page'] - 1, 'note' => $highlightId]) }}">{{ __('‹ 前へ') }}</a>
          @endif
          <span class="notes-pagination-label">{{ $pagination['page'] }} / {{ $pagination['totalPages'] }}</span>
          @if($pagination['page'] < $pagination['totalPages'])
            <a class="button-link secondary" href="{{ $buildNotesQuery($filters, ['page' => $pagination['page'] + 1, 'note' => $highlightId]) }}">{{ __('次へ ›') }}</a>
          @endif
        </nav>
      @endif
    </main>

    <div class="modal modal-centered" id="note-edit-modal" hidden>
      <div class="modal-backdrop" data-close-note-edit></div>
      <div class="modal-dialog note-edit-dialog" role="dialog" aria-labelledby="note-edit-modal-title">
        <div class="modal-header">
          <h2 id="note-edit-modal-title">{{ __('メモを編集') }}</h2>
          <button type="button" class="modal-close" data-close-note-edit aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" action="" id="note-edit-form" class="modal-form note-edit-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <input type="hidden" name="type" id="note-edit-type" value="text" />
          <input type="hidden" name="color" id="note-edit-color" value="default" />
          <div class="note-composer-meta">
            <label class="note-date-field">
              <span class="field-label">{{ __('登録日') }}</span>
              <input type="date" name="registeredDate" id="note-edit-date" required />
            </label>
            <label class="note-category-field">
              <span class="field-label">{{ __('カテゴリー') }}</span>
              <select name="category" id="note-edit-category">
                @foreach($noteCategories as $key => $label)
                  <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
              </select>
            </label>
          </div>
          <label>
            {{ __('タイトル') }}
            <input type="text" name="title" id="note-edit-title" placeholder="{{ __('タイトル') }}" autocomplete="off" />
          </label>
          <div id="note-edit-text-panel">
            <label>
              {{ __('メモ') }}
              <textarea name="body" id="note-edit-body" rows="6" placeholder="{{ __('メモを入力...') }}"></textarea>
            </label>
          </div>
          <div id="note-edit-checklist-panel" class="date-panel-hidden">
            <div class="field-label" style="margin-bottom:6px">{{ __('チェックリスト') }}</div>
            <div class="checklist-editor" id="note-edit-checklist"></div>
            <button type="button" class="text-btn" id="note-edit-add-item">{{ __('項目を追加') }}</button>
          </div>
          <div class="note-composer-footer">
            <div class="note-color-picker" id="note-edit-colors" role="group" aria-label="{{ __('色') }}">
              @foreach($colorKeys as $key)
                <button
                  type="button"
                  @class(['note-color-dot', 'note-edit-color-dot', 'is-selected' => $key === 'default'])
                  data-color="{{ $key }}"
                  style="--note-color: {{ $noteColors[$key]['bg'] }}; --note-border: {{ $noteColors[$key]['border'] }}"
                  title="{{ $noteColors[$key]['label'] }}"
                  aria-label="{{ $noteColors[$key]['label'] }}"
                ></button>
              @endforeach
            </div>
            <div class="note-composer-actions">
              <button type="button" class="text-btn" id="note-edit-toggle-type">{{ __('チェックリスト') }}</button>
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" class="secondary" data-close-note-edit>{{ __('キャンセル') }}</button>
            <button type="submit">{{ __('保存') }}</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal modal-centered" id="note-bulk-edit-modal" hidden>
      <div class="modal-backdrop" data-close-bulk-edit></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="note-bulk-edit-title">
        <div class="modal-header">
          <h2 id="note-bulk-edit-title">{{ __('一括編集（末尾に追記）') }}</h2>
          <button type="button" class="modal-close" data-close-bulk-edit aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" action="/notes/bulk/append" id="note-bulk-append-form" class="modal-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <div id="note-bulk-append-ids"></div>
          <label>
            {{ __('追加する内容') }}
            <textarea name="appendText" id="note-bulk-append-text" rows="6" placeholder="{{ __('選択したメモの末尾に追記します（既存の内容は削除しません）') }}" required></textarea>
          </label>
          <p class="hint inline-hint">{{ __('チェックリスト形式のメモには、行ごとに項目として追加されます。') }}</p>
          <div class="modal-actions">
            <button type="button" class="secondary" data-close-bulk-edit>{{ __('キャンセル') }}</button>
            <button type="submit">{{ __('追記する') }}</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      (function () {
        const VIEW_KEY = 'notesViewMode'
        const COLS_KEY = 'notesGalleryCols'
        const notesContent = document.getElementById('notes-content')
        const viewButtons = document.querySelectorAll('.notes-view-btn')
        const colsToggle = document.getElementById('notes-cols-toggle')
        const colsButtons = document.querySelectorAll('.notes-cols-btn')

        function applyCols(count) {
          const cols = Math.min(5, Math.max(1, Number(count) || 4))
          if (notesContent) {
            for (let i = 1; i <= 5; i++) {
              notesContent.classList.toggle(`notes-cols-${i}`, i === cols)
            }
          }
          colsButtons.forEach((btn) => {
            const active = Number(btn.dataset.cols) === cols
            btn.classList.toggle('is-active', active)
            btn.setAttribute('aria-pressed', active ? 'true' : 'false')
          })
          try {
            localStorage.setItem(COLS_KEY, String(cols))
          } catch (_) {}
          return cols
        }

        function applyView(mode) {
          const view = mode === 'list' ? 'list' : 'gallery'
          if (notesContent) {
            notesContent.classList.remove('notes-view-gallery', 'notes-view-list')
            notesContent.classList.add(view === 'list' ? 'notes-view-list' : 'notes-view-gallery')
          }
          viewButtons.forEach((btn) => {
            const active = btn.dataset.view === view
            btn.classList.toggle('is-active', active)
            btn.setAttribute('aria-pressed', active ? 'true' : 'false')
          })
          if (colsToggle) {
            colsToggle.hidden = view === 'list'
          }
          try {
            localStorage.setItem(VIEW_KEY, view)
          } catch (_) {}
        }

        let savedView = 'gallery'
        let savedCols = 4
        try {
          savedView = localStorage.getItem(VIEW_KEY) || 'gallery'
          savedCols = Number(localStorage.getItem(COLS_KEY) || 4)
        } catch (_) {}
        applyCols(savedCols)
        applyView(savedView)

        viewButtons.forEach((btn) => {
          btn.addEventListener('click', () => applyView(btn.dataset.view))
        })
        colsButtons.forEach((btn) => {
          btn.addEventListener('click', () => applyCols(btn.dataset.cols))
        })

        const composer = document.getElementById('note-composer')
        if (composer) {
        const collapsed = document.getElementById('composer-collapsed')
        const expanded = document.getElementById('composer-expanded')
        const closeBtn = document.getElementById('composer-close')
        const titleInput = document.getElementById('composer-title')
        const bodyInput = document.getElementById('composer-body')
        const typeInput = document.getElementById('composer-type')
        const colorInput = document.getElementById('composer-color')
        const textPanel = document.getElementById('composer-text-panel')
        const checklistPanel = document.getElementById('composer-checklist-panel')
        const checklistEditor = document.getElementById('composer-checklist')
        const toggleTypeBtn = document.getElementById('composer-toggle-type')
        const addItemBtn = document.getElementById('composer-add-item')
        const form = document.getElementById('note-composer-form')
        const colorDots = composer.querySelectorAll('.note-color-dot')

        function openComposer() {
          collapsed.classList.add('date-panel-hidden')
          expanded.classList.remove('date-panel-hidden')
          titleInput?.focus()
        }

        function closeComposer() {
          expanded.classList.add('date-panel-hidden')
          collapsed.classList.remove('date-panel-hidden')
        }

        function isComposerDirty() {
          const hasTitle = titleInput?.value.trim()
          const hasBody = bodyInput?.value.trim()
          const hasItems = checklistEditor?.querySelectorAll('input[type="text"]').length
          return Boolean(hasTitle || hasBody || hasItems)
        }

        collapsed?.addEventListener('click', openComposer)
        collapsed?.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault()
            openComposer()
          }
        })

        closeBtn?.addEventListener('click', () => {
          if (!isComposerDirty() || window.confirm(@json(__('入力内容を破棄して閉じますか？')))) {
            form?.reset()
            syncComposerType('text')
            selectColor('default')
            closeComposer()
          }
        })

        function selectColor(key) {
          if (colorInput) colorInput.value = key
          colorDots.forEach((dot) => dot.classList.toggle('is-selected', dot.dataset.color === key))
        }

        colorDots.forEach((dot) => {
          dot.addEventListener('click', () => selectColor(dot.dataset.color || 'default'))
        })

        function addChecklistRow(value = '', checked = false) {
          const row = document.createElement('div')
          row.className = 'checklist-row'
          row.innerHTML = `
            <input type="checkbox" class="checklist-check" ${checked ? 'checked' : ''} aria-label="完了" />
            <input type="text" class="checklist-text" name="itemText" value="${value.replace(/"/g, '&quot;')}" placeholder="項目" />
            <button type="button" class="checklist-remove" aria-label="削除">×</button>
          `
          row.querySelector('.checklist-remove')?.addEventListener('click', () => row.remove())
          checklistEditor?.appendChild(row)
          row.querySelector('.checklist-text')?.focus()
        }

        function syncComposerType(type) {
          const isChecklist = type === 'checklist'
          if (typeInput) typeInput.value = isChecklist ? 'checklist' : 'text'
          textPanel?.classList.toggle('date-panel-hidden', isChecklist)
          checklistPanel?.classList.toggle('date-panel-hidden', !isChecklist)
          if (toggleTypeBtn) toggleTypeBtn.textContent = isChecklist ? 'メモ' : 'チェックリスト'
          if (isChecklist && checklistEditor && checklistEditor.children.length === 0) {
            addChecklistRow()
          }
        }

        toggleTypeBtn?.addEventListener('click', () => {
          syncComposerType(typeInput?.value === 'checklist' ? 'text' : 'checklist')
        })

        addItemBtn?.addEventListener('click', () => addChecklistRow())

        form?.addEventListener('submit', (e) => {
          if (typeInput?.value === 'checklist') {
            const rows = checklistEditor?.querySelectorAll('.checklist-row') || []
            rows.forEach((row, index) => {
              const text = row.querySelector('.checklist-text')?.value.trim()
              if (!text) return
              const checked = row.querySelector('.checklist-check')?.checked
              const textInput = document.createElement('input')
              textInput.type = 'hidden'
              textInput.name = `items[${index}][text]`
              textInput.value = text
              form.appendChild(textInput)
              const checkedInput = document.createElement('input')
              checkedInput.type = 'hidden'
              checkedInput.name = `items[${index}][checked]`
              checkedInput.value = checked ? '1' : '0'
              form.appendChild(checkedInput)
            })
          }
          if (!titleInput?.value.trim() && !bodyInput?.value.trim() && typeInput?.value !== 'checklist') {
            const hasItem = [...(checklistEditor?.querySelectorAll('.checklist-text') || [])].some((el) => el.value.trim())
            if (!hasItem) {
              e.preventDefault()
              alert(@json(__('メモの内容を入力してください')));
            }
          }
        })

        }

        const noteEditModal = document.getElementById('note-edit-modal')
        const noteEditForm = document.getElementById('note-edit-form')
        const noteEditType = document.getElementById('note-edit-type')
        const noteEditColor = document.getElementById('note-edit-color')
        const noteEditDate = document.getElementById('note-edit-date')
        const noteEditCategory = document.getElementById('note-edit-category')
        const noteEditTitle = document.getElementById('note-edit-title')
        const noteEditBody = document.getElementById('note-edit-body')
        const noteEditTextPanel = document.getElementById('note-edit-text-panel')
        const noteEditChecklistPanel = document.getElementById('note-edit-checklist-panel')
        const noteEditChecklist = document.getElementById('note-edit-checklist')
        const noteEditToggleType = document.getElementById('note-edit-toggle-type')
        const noteEditAddItem = document.getElementById('note-edit-add-item')

        function closeNoteEditModal() {
          if (noteEditModal) noteEditModal.hidden = true
        }

        function selectEditColor(key) {
          if (noteEditColor) noteEditColor.value = key || 'default'
          noteEditModal?.querySelectorAll('.note-edit-color-dot').forEach((dot) => {
            dot.classList.toggle('is-selected', dot.dataset.color === (key || 'default'))
          })
        }

        function bindEditChecklistRemove(row) {
          row.querySelector('.checklist-remove')?.addEventListener('click', (e) => {
            e.preventDefault()
            e.stopPropagation()
            row.remove()
          })
        }

        function addEditChecklistRow(value = '', checked = false) {
          const index = noteEditChecklist?.querySelectorAll('.checklist-row').length || 0
          const row = document.createElement('div')
          row.className = 'checklist-row'
          const safe = String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;')
          row.innerHTML = `
            <input type="checkbox" class="checklist-check" ${checked ? 'checked' : ''} aria-label="完了" />
            <input type="text" class="checklist-text" name="items[${index}][text]" value="${safe}" placeholder="項目" />
            <input type="hidden" name="items[${index}][checked]" value="${checked ? '1' : '0'}" class="checklist-checked-hidden" />
            <button type="button" class="checklist-remove" aria-label="削除">×</button>
          `
          const check = row.querySelector('.checklist-check')
          const hidden = row.querySelector('.checklist-checked-hidden')
          check?.addEventListener('change', () => {
            if (hidden) hidden.value = check.checked ? '1' : '0'
          })
          bindEditChecklistRemove(row)
          noteEditChecklist?.appendChild(row)
          return row
        }

        function syncNoteEditType(type, { focus = false } = {}) {
          const isChecklist = type === 'checklist'
          if (noteEditType) noteEditType.value = isChecklist ? 'checklist' : 'text'
          noteEditTextPanel?.classList.toggle('date-panel-hidden', isChecklist)
          noteEditChecklistPanel?.classList.toggle('date-panel-hidden', !isChecklist)
          if (noteEditToggleType) noteEditToggleType.textContent = isChecklist ? 'フリーメモに切替' : 'チェックリストに切替'
          if (isChecklist && noteEditChecklist && noteEditChecklist.children.length === 0) {
            addEditChecklistRow()
          }
          if (focus) {
            if (isChecklist) {
              noteEditChecklist?.querySelector('.checklist-text')?.focus()
            } else {
              noteEditBody?.focus()
            }
          }
        }

        function openNoteEdit(note) {
          if (!noteEditForm || !note) return
          noteEditForm.action = `/notes/${note.id}/update`
          if (noteEditTitle) noteEditTitle.value = note.title || ''
          if (noteEditBody) noteEditBody.value = note.body || ''
          if (noteEditDate) noteEditDate.value = note.registeredDate || ''
          if (noteEditCategory) noteEditCategory.value = note.category || 'personal'
          selectEditColor(note.color || 'default')
          if (noteEditChecklist) noteEditChecklist.innerHTML = ''
          const items = Array.isArray(note.items) ? note.items : []
          items.forEach((item) => {
            addEditChecklistRow(item.text || '', !!item.checked)
          })
          syncNoteEditType(note.type === 'checklist' ? 'checklist' : 'text')
          if (noteEditModal) noteEditModal.hidden = false
          noteEditTitle?.focus()
        }

        function parseCardNote(card) {
          try {
            const b64 = card.getAttribute('data-note-b64') || ''
            const json = decodeURIComponent(Array.prototype.map.call(atob(b64), (c) => {
              return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2)
            }).join(''))
            return JSON.parse(json)
          } catch (_) {
            return null
          }
        }

        notesContent?.addEventListener('click', (e) => {
          const editBtn = e.target.closest('.note-action-edit')
          if (editBtn) {
            e.preventDefault()
            e.stopPropagation()
            const card = editBtn.closest('.note-card')
            openNoteEdit(parseCardNote(card))
            return
          }
          const view = e.target.closest('.note-card-view')
          if (view && !e.target.closest('.note-card-actions, .note-bulk-check, .note-inline-form')) {
            const card = view.closest('.note-card')
            openNoteEdit(parseCardNote(card))
          }
        })

        noteEditToggleType?.addEventListener('click', (e) => {
          e.preventDefault()
          const next = noteEditType?.value === 'checklist' ? 'text' : 'checklist'
          if (next === 'text') {
            const texts = [...(noteEditChecklist?.querySelectorAll('.checklist-text') || [])]
              .map((el) => el.value.trim())
              .filter(Boolean)
            if (noteEditBody && !noteEditBody.value.trim() && texts.length > 0) {
              noteEditBody.value = texts.join('\n')
            }
          } else if (noteEditChecklist && noteEditChecklist.children.length === 0 && noteEditBody?.value.trim()) {
            noteEditBody.value.split(/\r?\n/).forEach((line) => {
              const text = line.trim()
              if (text) addEditChecklistRow(text)
            })
          }
          syncNoteEditType(next, { focus: true })
        })

        noteEditAddItem?.addEventListener('click', (e) => {
          e.preventDefault()
          const row = addEditChecklistRow()
          row.querySelector('.checklist-text')?.focus()
        })

        noteEditModal?.querySelectorAll('.note-edit-color-dot').forEach((dot) => {
          dot.addEventListener('click', (e) => {
            e.preventDefault()
            selectEditColor(dot.dataset.color || 'default')
          })
        })

        noteEditForm?.addEventListener('submit', () => {
          if (noteEditType?.value === 'text') {
            noteEditForm.querySelectorAll('[name^="items"]').forEach((el) => {
              el.disabled = true
            })
          } else {
            // チェックリスト保存時は表示中の行の name index を振り直す
            const rows = [...(noteEditChecklist?.querySelectorAll('.checklist-row') || [])]
            rows.forEach((row, index) => {
              const text = row.querySelector('.checklist-text')
              const hidden = row.querySelector('.checklist-checked-hidden')
              if (text) text.name = `items[${index}][text]`
              if (hidden) hidden.name = `items[${index}][checked]`
            })
          }
        })

        document.querySelectorAll('[data-close-note-edit]').forEach((el) => {
          el.addEventListener('click', closeNoteEditModal)
        })

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        document.querySelectorAll('.note-action-translate').forEach((btn) => {
          const card = btn.closest('.note-card')
          if (!card) return
          const noteId = btn.dataset.noteId
          const titleEl = card.querySelector('.note-card-view .note-card-title')
          const bodyEl = card.querySelector('.note-card-view .note-card-body')
          const itemEls = card.querySelectorAll('.note-card-view .note-checklist-preview li span:last-child')
          const state = { translated: false, loading: false, cache: null, original: null }

          function captureOriginal() {
            state.original = {
              title: titleEl ? titleEl.textContent : null,
              body: bodyEl ? bodyEl.textContent : null,
              items: Array.from(itemEls).map((el) => el.textContent),
            }
          }

          function render(data) {
            if (titleEl && typeof data.title === 'string' && data.title !== '') titleEl.textContent = data.title
            if (bodyEl && typeof data.body === 'string') bodyEl.textContent = data.body
            if (Array.isArray(data.items)) {
              itemEls.forEach((el, i) => {
                if (typeof data.items[i] === 'string') el.textContent = data.items[i]
              })
            }
          }

          btn.addEventListener('click', async (e) => {
            e.stopPropagation()
            if (state.loading) return

            if (state.translated) {
              if (state.original) render(state.original)
              state.translated = false
              btn.classList.remove('is-translated')
              btn.setAttribute('aria-pressed', 'false')
              btn.title = @json(__('日本語⇔英語に翻訳'));
              return
            }

            if (state.cache) {
              if (!state.original) captureOriginal()
              render(state.cache)
              state.translated = true
              btn.classList.add('is-translated')
              btn.setAttribute('aria-pressed', 'true')
              btn.title = @json(__('原文に戻す'));
              return
            }

            state.loading = true
            btn.classList.add('is-loading')
            try {
              const res = await fetch(`/notes/${noteId}/translate`, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-TOKEN': csrfToken,
                  'Accept': 'application/json',
                },
                body: JSON.stringify({}),
              })
              const data = await res.json()
              if (!res.ok || !data.ok) {
                window.alert(data.message || @json(__('翻訳に失敗しました')));
                return
              }
              captureOriginal()
              state.cache = data
              render(data)
              state.translated = true
              btn.classList.add('is-translated')
              btn.setAttribute('aria-pressed', 'true')
              btn.title = @json(__('原文に戻す'));
            } catch (err) {
              window.alert(@json(__('翻訳中に通信エラーが発生しました')));
            } finally {
              state.loading = false
              btn.classList.remove('is-loading')
            }
          })
        })

        const highlightId = @json($highlightId);
        if (highlightId) {
          const target = document.getElementById(`note-${highlightId}`)
          if (target) {
            target.classList.add('is-highlighted')
            target.scrollIntoView({ behavior: 'smooth', block: 'center' })
          }
        }

        const notesFilterForm = document.getElementById('notes-filter-form')
        const notesPeriodInput = document.getElementById('notes-period')
        const notesCategoryFilter = document.getElementById('notes-category-filter')
        notesPeriodInput?.addEventListener('change', () => notesFilterForm?.submit())
        notesCategoryFilter?.addEventListener('change', () => notesFilterForm?.submit())

        const bulkReturnTo = document.getElementById('notes-bulk-return-to')
        const bulkEditModal = document.getElementById('note-bulk-edit-modal')
        const bulkAppendForm = document.getElementById('note-bulk-append-form')
        const bulkAppendIds = document.getElementById('note-bulk-append-ids')
        const bulkAppendText = document.getElementById('note-bulk-append-text')
        const notesSelectAll = document.getElementById('notes-select-all')
        const noteChecks = () => Array.from(document.querySelectorAll('.note-check'))

        function getCheckedNoteIds() {
          return noteChecks().filter((cb) => cb.checked).map((cb) => cb.value)
        }

        function submitBulkAction(url, confirmMsg, extraFields = {}) {
          const ids = getCheckedNoteIds()
          if (ids.length === 0) {
            window.alert(@json(__('対象が選択されていません')));
            return
          }
          if (confirmMsg && !window.confirm(confirmMsg)) return

          const form = document.createElement('form')
          form.method = 'POST'
          form.action = url
          form.style.display = 'none'

          const returnTo = document.createElement('input')
          returnTo.type = 'hidden'
          returnTo.name = 'returnTo'
          returnTo.value = bulkReturnTo?.value || '/notes'
          form.appendChild(returnTo)

          const csrf = document.createElement('input')
          csrf.type = 'hidden'
          csrf.name = '_token'
          csrf.value = csrfToken || ''
          form.appendChild(csrf)

          ids.forEach((id) => {
            const idInput = document.createElement('input')
            idInput.type = 'hidden'
            idInput.name = 'ids[]'
            idInput.value = id
            form.appendChild(idInput)
          })

          Object.entries(extraFields).forEach(([name, value]) => {
            const input = document.createElement('input')
            input.type = 'hidden'
            input.name = name
            input.value = value
            form.appendChild(input)
          })

          document.body.appendChild(form)
          form.submit()
        }

        document.querySelectorAll('.notes-bulk-btn').forEach((btn) => {
          btn.addEventListener('click', () => {
            const extra = {}
            if (btn.dataset.bulkUnarchive === '1') extra.unarchive = '1'
            submitBulkAction(btn.dataset.bulkUrl, btn.dataset.confirm || '', extra)
          })
        })

        notesSelectAll?.addEventListener('change', () => {
          const on = notesSelectAll.checked
          noteChecks().forEach((cb) => {
            cb.checked = on
          })
        })

        noteChecks().forEach((cb) => {
          cb.addEventListener('change', () => {
            const all = noteChecks()
            if (!notesSelectAll || all.length === 0) return
            notesSelectAll.checked = all.every((item) => item.checked)
            notesSelectAll.indeterminate = !notesSelectAll.checked && all.some((item) => item.checked)
          })
        })

        function closeBulkEditModal() {
          if (bulkEditModal) bulkEditModal.hidden = true
          if (bulkAppendText) bulkAppendText.value = ''
        }

        document.getElementById('notes-bulk-edit-open')?.addEventListener('click', () => {
          const ids = getCheckedNoteIds()
          if (ids.length === 0) {
            window.alert(@json(__('対象が選択されていません')));
            return
          }
          if (bulkAppendIds) {
            bulkAppendIds.innerHTML = ''
            ids.forEach((id) => {
              const input = document.createElement('input')
              input.type = 'hidden'
              input.name = 'ids[]'
              input.value = id
              bulkAppendIds.appendChild(input)
            })
          }
          if (bulkEditModal) bulkEditModal.hidden = false
          bulkAppendText?.focus()
        })

        document.querySelectorAll('[data-close-bulk-edit]').forEach((el) => {
          el.addEventListener('click', closeBulkEditModal)
        })

        bulkAppendForm?.addEventListener('submit', (e) => {
          if (getCheckedNoteIds().length === 0) {
            e.preventDefault()
            window.alert(@json(__('対象が選択されていません')));
          }
        })

        let draggedNoteCard = null

        function getDragAfterNoteCard(container, x, y) {
          const cards = [...container.querySelectorAll('.note-card:not(.is-dragging)')]
          return cards.reduce((closest, child) => {
            const box = child.getBoundingClientRect()
            const offset = (y - box.top - box.height / 2) * 10000 + (x - box.left - box.width / 2)
            if (offset < 0 && offset > closest.offset) {
              return { offset, element: child }
            }
            return closest
          }, { offset: Number.NEGATIVE_INFINITY }).element
        }

        async function saveNoteOrder(container) {
          const noteIds = Array.from(container.querySelectorAll('.note-card'))
            .map((card) => parseInt(card.dataset.noteId, 10))
            .filter((id) => id > 0)
          if (noteIds.length === 0) return

          try {
            const response = await fetch('/notes/reorder', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
              },
              body: JSON.stringify({ noteIds }),
            })
            if (!response.ok) throw new Error('reorder failed')
          } catch (_) {
            window.location.reload()
          }
        }

        document.querySelectorAll('.note-drag-handle').forEach((handle) => {
          handle.addEventListener('dragstart', (event) => {
            draggedNoteCard = handle.closest('.note-card')
            if (!draggedNoteCard) return
            draggedNoteCard.classList.add('is-dragging')
            event.dataTransfer.effectAllowed = 'move'
            event.dataTransfer.setData('text/plain', draggedNoteCard.dataset.noteId || '')
          })
          handle.addEventListener('dragend', () => {
            draggedNoteCard?.classList.remove('is-dragging')
            draggedNoteCard = null
          })
        })

        document.querySelectorAll('.notes-grid').forEach((container) => {
          container.addEventListener('dragover', (event) => {
            event.preventDefault()
            if (!draggedNoteCard || draggedNoteCard.closest('.notes-grid') !== container) return
            const after = getDragAfterNoteCard(container, event.clientX, event.clientY)
            if (after == null) container.appendChild(draggedNoteCard)
            else container.insertBefore(draggedNoteCard, after)
          })
          container.addEventListener('drop', (event) => {
            event.preventDefault()
            if (!draggedNoteCard || draggedNoteCard.closest('.notes-grid') !== container) return
            saveNoteOrder(container)
          })
        })
      })()
    </script>
  </body>
</html>
