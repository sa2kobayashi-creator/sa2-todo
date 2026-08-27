<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('共有された曲') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body class="media-player-page music-page">
    @include('partials.header', ['active' => 'music'])
    <main class="page-main media-player-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <section class="panel">
        <h1 class="media-player-title">{{ __('共有された曲') }}</h1>
        <p class="hint">{{ __('他のアプリから共有された音声をライブラリに保存します。') }}</p>

        <p class="hint" id="share-status">{{ __('受け取った曲を確認しています…') }}</p>
        <ul class="media-track-list" id="share-file-list"></ul>

        <div class="media-library-filter" id="share-actions" hidden>
          <label class="media-library-filter-label">
            {{ __('保存先ライブラリ') }}
            <select id="share-library" class="media-field-input">
              @foreach($libraries as $lib)
                <option value="{{ $lib['id'] }}" @selected((int) $lib['id'] === (int) $defaultLibraryId)>{{ $lib['name'] }}</option>
              @endforeach
            </select>
          </label>
          <button type="button" class="button-link" id="share-save">{{ __('ライブラリに保存') }}</button>
          <button type="button" class="secondary" id="share-discard">{{ __('破棄する') }}</button>
        </div>

        <p class="hint"><a href="/music">{{ __('音楽に戻る') }}</a></p>
      </section>
    </main>
    <script>
      (function () {
        const SHARE_CACHE = 'sa2-shared-audio-v1'
        const statusEl = document.getElementById('share-status')
        const listEl = document.getElementById('share-file-list')
        const actionsEl = document.getElementById('share-actions')
        const librarySelect = document.getElementById('share-library')
        const saveBtn = document.getElementById('share-save')
        const discardBtn = document.getElementById('share-discard')
        const t = {
          none: @json(__('共有された曲が見つかりませんでした。音楽の「曲を追加」からも取り込めます。')),
          found: @json(__(':count曲を受け取りました。保存先を選んでください。')),
          saving: @json(__('保存しています… :done / :total')),
          failed: @json(__('保存に失敗しました: :message')),
          unsupported: @json(__('このブラウザは共有からの取り込みに対応していません。')),
        }
        let sharedFiles = []

        function csrfToken() {
          return document.querySelector('meta[name="csrf-token"]')?.content || ''
        }

        function fill(text, values) {
          return Object.entries(values).reduce((acc, [key, value]) => acc.replaceAll(':' + key, String(value)), text)
        }

        async function readSharedFiles() {
          if (!('caches' in window)) {
            statusEl.textContent = t.unsupported
            return []
          }
          const cache = await caches.open(SHARE_CACHE)
          const keys = await cache.keys()
          const files = []
          for (const key of keys) {
            const res = await cache.match(key)
            if (!res) continue
            const blob = await res.blob()
            if (!blob.size) continue
            const rawName = res.headers.get('X-Shared-Name') || 'track.mp3'
            let name = rawName
            try {
              name = decodeURIComponent(rawName)
            } catch (_) {}
            files.push(new File([blob], name, { type: blob.type || 'audio/mpeg' }))
          }
          return files
        }

        async function clearSharedFiles() {
          if (!('caches' in window)) return
          await caches.delete(SHARE_CACHE)
        }

        function renderList(files) {
          listEl.innerHTML = ''
          files.forEach((file) => {
            const li = document.createElement('li')
            li.className = 'media-track-item'
            const copy = document.createElement('span')
            copy.className = 'media-track-copy'
            const title = document.createElement('strong')
            title.textContent = file.name
            const meta = document.createElement('span')
            meta.className = 'hint'
            meta.textContent = (file.size / (1024 * 1024)).toFixed(1) + ' MB'
            copy.append(title, meta)
            li.append(copy)
            listEl.append(li)
          })
        }

        async function uploadAll() {
          const libraryId = librarySelect?.value || ''
          let done = 0
          for (const file of sharedFiles) {
            const body = new FormData()
            body.append('_token', csrfToken())
            body.append('returnTo', '/music')
            if (libraryId) body.append('library_id', libraryId)
            body.append('tracks[]', file, file.name)

            const res = await fetch('/music', {
              method: 'POST',
              body: body,
              headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
              credentials: 'same-origin',
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok || !data.ok) {
              throw new Error(data.message || String(res.status))
            }
            done += 1
            statusEl.textContent = fill(t.saving, { done: done, total: sharedFiles.length })
          }
        }

        saveBtn?.addEventListener('click', async () => {
          saveBtn.disabled = true
          discardBtn.disabled = true
          try {
            await uploadAll()
            await clearSharedFiles()
            window.location.href = '/music' + (librarySelect?.value ? '?library=' + encodeURIComponent(librarySelect.value) : '')
          } catch (err) {
            statusEl.textContent = fill(t.failed, { message: err?.message || '' })
            saveBtn.disabled = false
            discardBtn.disabled = false
          }
        })

        discardBtn?.addEventListener('click', async () => {
          await clearSharedFiles()
          window.location.href = '/music'
        })

        void (async function () {
          try {
            sharedFiles = await readSharedFiles()
          } catch (_) {
            sharedFiles = []
          }
          if (!sharedFiles.length) {
            statusEl.textContent = t.none
            return
          }
          renderList(sharedFiles)
          statusEl.textContent = fill(t.found, { count: sharedFiles.length })
          actionsEl.hidden = false
        })()
      })()
    </script>
  </body>
</html>
