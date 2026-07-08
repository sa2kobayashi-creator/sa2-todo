<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>メモ - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="notes-page">
    @include('partials.header', ['active' => 'notes'])
    <main class="page-main notes-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      @if($filterDate)
        <p class="hint inline-hint notes-date-filter">
          登録日: <strong>{{ $filterDate }}</strong>
          <a href="{{ $buildNotesQuery($filters) }}">日付フィルタを解除</a>
        </p>
      @endif

      <div class="notes-top-actions">
        <form class="notes-period-form" method="get" action="/notes" id="notes-period-form">
          @if($showArchived)<input type="hidden" name="archived" value="1" />@endif
          @if($searchQuery)<input type="hidden" name="q" value="{{ $searchQuery }}" />@endif
          @if($filterDate)<input type="hidden" name="date" value="{{ $filterDate }}" />@endif
          <label class="notes-period-label">
            表示月
            <input type="month" name="period" id="notes-period" value="{{ $periodValue }}" @disabled($filterDate) />
          </label>
        </form>
        @if($showArchived)
          <a href="{{ $buildNotesQuery(array_merge($filters, ['archived' => false])) }}" class="button-link secondary">メモに戻る</a>
        @else
          <a href="{{ $buildNotesQuery(array_merge($filters, ['archived' => true])) }}" class="button-link secondary">アーカイブ</a>
        @endif
      </div>

      <div class="notes-input-row">
        <form class="notes-search" method="get" action="/notes" id="notes-search-form">
          @if($showArchived)<input type="hidden" name="archived" value="1" />@endif
          @if($filterDate)
            <input type="hidden" name="date" value="{{ $filterDate }}" />
          @else
            <input type="hidden" name="period" value="{{ $periodValue }}" />
          @endif
          <input type="search" name="q" value="{{ $searchQuery }}" placeholder="メモを検索" aria-label="メモを検索" />
        </form>

        @if(!$showArchived)
          <section class="note-composer" id="note-composer">
            <form method="post" action="/notes" class="note-composer-form" id="note-composer-form">
          @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <input type="hidden" name="type" id="composer-type" value="text" />
              <input type="hidden" name="color" id="composer-color" value="default" />
              <div class="note-composer-collapsed" id="composer-collapsed" tabindex="0" role="button" aria-label="メモを作成">
                メモを入力...
              </div>
              <div class="note-composer-expanded date-panel-hidden" id="composer-expanded">
                <label class="note-date-field">
                  <span class="field-label">登録日</span>
                  <input type="date" name="registeredDate" id="composer-registered-date" value="{{ $defaultRegisteredDate }}" />
                </label>
                <input type="text" name="title" id="composer-title" placeholder="タイトル" autocomplete="off" />
                <div class="composer-text-panel" id="composer-text-panel">
                  <textarea name="body" id="composer-body" placeholder="メモを入力..." rows="4"></textarea>
                </div>
                <div class="composer-checklist-panel date-panel-hidden" id="composer-checklist-panel">
                  <div class="checklist-editor" id="composer-checklist"></div>
                  <button type="button" class="text-btn" id="composer-add-item">項目を追加</button>
                </div>
                <div class="note-composer-footer">
                  <div class="note-color-picker" role="group" aria-label="色">
                    @foreach($colorKeys as $key)
                      <button
                        type="button"
                        class="note-color-dot @class(['is-selected' => $key === 'default'])"
                        data-color="{{ $key }}"
                        style="--note-color: {{ $noteColors[$key]['bg'] }}; --note-border: {{ $noteColors[$key]['border'] }}"
                        title="{{ $noteColors[$key]['label'] }}"
                        aria-label="{{ $noteColors[$key]['label'] }}"
                      ></button>
                    @endforeach
                  </div>
                  <div class="note-composer-actions">
                    <button type="button" class="text-btn" id="composer-toggle-type">チェックリスト</button>
                    <button type="button" class="text-btn" id="composer-close">閉じる</button>
                    <button type="submit" class="button-link">保存</button>
                  </div>
                </div>
              </div>
            </form>
          </section>
        @endif

        <div class="notes-view-toggle" role="group" aria-label="表示切替">
          <button type="button" class="notes-view-btn is-active" data-view="gallery" title="ギャラリー表示" aria-pressed="true" aria-label="ギャラリー表示">⊞</button>
          <button type="button" class="notes-view-btn" data-view="list" title="リスト表示" aria-pressed="false" aria-label="リスト表示">☰</button>
        </div>
      </div>

      <div class="notes-bulk-bar panel" id="notes-bulk-bar">
        <input type="hidden" id="notes-bulk-return-to" value="{{ $returnTo }}" />
        <label class="note-bulk-select-all-label">
          <input type="checkbox" id="notes-select-all" />
          全選択
        </label>
        <div class="bulk-actions notes-bulk-actions">
          @if($showArchived)
            <button type="button" class="secondary notes-bulk-btn" data-bulk-url="/notes/bulk/archive" data-bulk-unarchive="1">一括で戻す</button>
          @else
            <button type="button" class="secondary notes-bulk-btn" data-bulk-url="/notes/bulk/archive">一括アーカイブ</button>
          @endif
          <button type="button" class="secondary" id="notes-bulk-edit-open">一括編集</button>
          <button type="button" class="danger notes-bulk-btn" data-bulk-url="/notes/bulk/delete" data-confirm="選択したメモを削除しますか？">一括削除</button>
        </div>
        <span class="notes-page-summary">{{ $pagination['total'] }}件中 {{ $pagination['total'] === 0 ? 0 : ($pagination['page'] - 1) * $pagination['perPage'] + 1 }}〜{{ min($pagination['page'] * $pagination['perPage'], $pagination['total']) }}件を表示</span>
      </div>

      <div class="notes-content notes-view-gallery" id="notes-content">
      @if(count($pinnedNotes) > 0)
        <h2 class="notes-section-title">ピン留め</h2>
        <div class="notes-grid">
          @foreach($pinnedNotes as $note)
            @include('partials.note-card', ['note' => $note, 'returnTo' => $returnTo, 'showArchived' => $showArchived, 'highlightId' => $highlightId])
          @endforeach
        </div>
      @endif

      @if(count($otherNotes) > 0)
        @if(count($pinnedNotes) > 0)<h2 class="notes-section-title">その他</h2>@endif
        <div class="notes-grid">
          @foreach($otherNotes as $note)
            @include('partials.note-card', ['note' => $note, 'returnTo' => $returnTo, 'showArchived' => $showArchived, 'highlightId' => $highlightId])
          @endforeach
        </div>
      @endif

      @if(count($pinnedNotes) === 0 && count($otherNotes) === 0)
        <p class="notes-empty">{{ $showArchived ? 'アーカイブされたメモはありません。' : 'メモがありません。上の欄から追加できます。' }}</p>
      @endif
      </div>

      @if(($pagination['totalPages'] ?? 1) > 1)
        <nav class="notes-pagination" aria-label="メモ一覧のページ">
          @if($pagination['page'] > 1)
            <a class="button-link secondary" href="{{ $buildNotesQuery($filters, ['page' => $pagination['page'] - 1, 'note' => $highlightId]) }}">‹ 前へ</a>
          @endif
          <span class="notes-pagination-label">{{ $pagination['page'] }} / {{ $pagination['totalPages'] }}</span>
          @if($pagination['page'] < $pagination['totalPages'])
            <a class="button-link secondary" href="{{ $buildNotesQuery($filters, ['page' => $pagination['page'] + 1, 'note' => $highlightId]) }}">次へ ›</a>
          @endif
        </nav>
      @endif
    </main>

    <div class="modal modal-centered" id="note-bulk-edit-modal" hidden>
      <div class="modal-backdrop" data-close-bulk-edit></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="note-bulk-edit-title">
        <div class="modal-header">
          <h2 id="note-bulk-edit-title">一括編集（末尾に追記）</h2>
          <button type="button" class="modal-close" data-close-bulk-edit aria-label="閉じる">×</button>
        </div>
        <form method="post" action="/notes/bulk/append" id="note-bulk-append-form" class="modal-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <div id="note-bulk-append-ids"></div>
          <label>
            追加する内容
            <textarea name="appendText" id="note-bulk-append-text" rows="6" placeholder="選択したメモの末尾に追記します（既存の内容は削除しません）" required></textarea>
          </label>
          <p class="hint inline-hint">チェックリスト形式のメモには、行ごとに項目として追加されます。</p>
          <div class="modal-actions">
            <button type="button" class="secondary" data-close-bulk-edit>キャンセル</button>
            <button type="submit">追記する</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      (function () {
        const VIEW_KEY = 'notesViewMode'
        const notesContent = document.getElementById('notes-content')
        const viewButtons = document.querySelectorAll('.notes-view-btn')

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
          try {
            localStorage.setItem(VIEW_KEY, view)
          } catch (_) {}
        }

        let savedView = 'gallery'
        try {
          savedView = localStorage.getItem(VIEW_KEY) || 'gallery'
        } catch (_) {}
        applyView(savedView)

        viewButtons.forEach((btn) => {
          btn.addEventListener('click', () => applyView(btn.dataset.view))
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
          if (!isComposerDirty() || window.confirm('入力内容を破棄して閉じますか？')) {
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
              alert('メモの内容を入力してください')
            }
          }
        })

        }

        document.querySelectorAll('.note-card').forEach((card) => {
          const view = card.querySelector('.note-card-view')
          const edit = card.querySelector('.note-card-edit')
          const editBtn = card.querySelector('.note-action-edit')
          const cancelBtn = card.querySelector('.note-edit-cancel')

          function openEdit() {
            document.querySelectorAll('.note-card.is-editing').forEach((other) => {
              if (other !== card) other.classList.remove('is-editing')
            })
            card.classList.add('is-editing')
          }

          function closeEdit() {
            card.classList.remove('is-editing')
          }

          view?.addEventListener('click', (e) => {
            if (e.target.closest('.note-card-actions, .note-bulk-check')) return
            openEdit()
          })

          editBtn?.addEventListener('click', (e) => {
            e.stopPropagation()
            openEdit()
          })

          cancelBtn?.addEventListener('click', (e) => {
            e.preventDefault()
            closeEdit()
          })

          card.querySelectorAll('.note-color-dot').forEach((dot) => {
            dot.addEventListener('click', (e) => {
              e.preventDefault()
              const colorField = card.querySelector('input[name="color"]')
              if (colorField) colorField.value = dot.dataset.color || 'default'
              card.querySelectorAll('.note-color-dot').forEach((d) => d.classList.toggle('is-selected', d === dot))
              card.className = card.className.replace(/note-color-\w+/g, '')
              card.classList.add(`note-color-${dot.dataset.color || 'default'}`)
            })
          })

          const addRowBtn = card.querySelector('.note-add-check-item')
          const listEditor = card.querySelector('.checklist-editor')
          addRowBtn?.addEventListener('click', () => {
            const index = listEditor?.querySelectorAll('.checklist-row').length || 0
            const row = document.createElement('div')
            row.className = 'checklist-row'
            row.innerHTML = `
              <input type="checkbox" class="checklist-check" aria-label="完了" />
              <input type="text" class="checklist-text" name="items[${index}][text]" placeholder="項目" />
              <input type="hidden" name="items[${index}][checked]" value="0" class="checklist-checked-hidden" />
              <button type="button" class="checklist-remove" aria-label="削除">×</button>
            `
            const check = row.querySelector('.checklist-check')
            const hidden = row.querySelector('.checklist-checked-hidden')
            check?.addEventListener('change', () => {
              if (hidden) hidden.value = check.checked ? '1' : '0'
            })
            row.querySelector('.checklist-remove')?.addEventListener('click', () => row.remove())
            listEditor?.appendChild(row)
            row.querySelector('.checklist-text')?.focus()
          })

          card.querySelectorAll('.checklist-row .checklist-check').forEach((check) => {
            const hidden = check.closest('.checklist-row')?.querySelector('.checklist-checked-hidden')
            check.addEventListener('change', () => {
              if (hidden) hidden.value = check.checked ? '1' : '0'
            })
          })
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
              btn.title = '日本語⇔英語に翻訳'
              return
            }

            if (state.cache) {
              if (!state.original) captureOriginal()
              render(state.cache)
              state.translated = true
              btn.classList.add('is-translated')
              btn.setAttribute('aria-pressed', 'true')
              btn.title = '原文に戻す'
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
                window.alert(data.message || '翻訳に失敗しました')
                return
              }
              captureOriginal()
              state.cache = data
              render(data)
              state.translated = true
              btn.classList.add('is-translated')
              btn.setAttribute('aria-pressed', 'true')
              btn.title = '原文に戻す'
            } catch (err) {
              window.alert('翻訳中に通信エラーが発生しました')
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

        const notesPeriodForm = document.getElementById('notes-period-form')
        const notesPeriodInput = document.getElementById('notes-period')
        notesPeriodInput?.addEventListener('change', () => notesPeriodForm?.submit())

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
            window.alert('対象が選択されていません')
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

          ids.forEach((id) => {
            const idInput = document.createElement('input')
            idInput.type = 'hidden'
            idInput.name = 'ids'
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
            window.alert('対象が選択されていません')
            return
          }
          if (bulkAppendIds) {
            bulkAppendIds.innerHTML = ''
            ids.forEach((id) => {
              const input = document.createElement('input')
              input.type = 'hidden'
              input.name = 'ids'
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
            window.alert('対象が選択されていません')
          }
        })
      })()
    </script>
  </body>
</html>
