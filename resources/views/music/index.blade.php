<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('音楽') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body class="media-player-page music-page">
    @include('partials.header', ['active' => 'music'])
    <main class="page-main media-player-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <section class="panel media-player-hero">
        <div>
          <h1 class="media-player-title">{{ __('音楽') }}</h1>
          <p class="hint">{{ __('端末に入っている曲を選んでアップロードすると、どの端末からでも聴けます。') }}</p>
        </div>
        <form method="post" action="/music" enctype="multipart/form-data" class="media-upload-form" id="music-upload-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <input type="hidden" name="library_id" value="{{ $currentLibraryId }}" />
          <label class="button-link media-upload-btn">
            <input type="file" id="music-file-input" name="tracks[]" accept="audio/*,.mp3,.m4a,.wav,.ogg,.aac,.webm" multiple required hidden />
            <span>{{ __('曲を追加') }}</span>
          </label>
          {{-- フォルダ選択は Chrome 系のみ。使えない端末では JS がボタンを隠す --}}
          <button type="button" class="secondary" id="music-folder-btn" hidden>{{ __('フォルダから取り込み') }}</button>
          <input type="file" id="music-folder-input" webkitdirectory directory multiple hidden />
          <p class="hint">{{ __('1ファイル最大 :size', ['size' => $maxUploadLabel ?? '100 MB']) }}</p>
          <p class="hint">{{ __('追加先: :name', ['name' => $currentLibrary['name'] ?? __('マイリスト')]) }}</p>
          <p class="hint" id="music-upload-status" hidden></p>
        </form>
      </section>

      <section class="panel media-player-stage">
        <audio id="music-audio" controls preload="metadata" class="media-audio-el"></audio>
        <div class="media-now-playing">
          <strong id="music-now-title">{{ __('曲を選択してください') }}</strong>
          <span class="hint" id="music-now-meta"></span>
        </div>
      </section>

      <section class="panel media-library-panel">
        <div class="media-library-head">
          <h2 class="media-list-title">{{ __('ライブラリ') }}</h2>
          <form method="post" action="/music/libraries" class="media-library-create">
            @csrf
            <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
            <input type="text" name="name" class="media-field-input" maxlength="120" required placeholder="{{ __('新しいライブラリ名') }}" />
            <button type="submit" class="button-link">{{ __('ライブラリを作成') }}</button>
          </form>
        </div>

        <div class="media-library-tabs" role="tablist" aria-label="{{ __('ライブラリ') }}">
          @foreach($libraries as $lib)
            <a
              href="/music?library={{ $lib['id'] }}"
              class="media-library-tab {{ (int) $lib['id'] === (int) $currentLibraryId ? 'is-active' : '' }}"
              role="tab"
              aria-selected="{{ (int) $lib['id'] === (int) $currentLibraryId ? 'true' : 'false' }}"
            >
              <span>{{ $lib['name'] }}</span>
              <span class="hint">{{ $lib['trackCount'] }}</span>
            </a>
          @endforeach
        </div>

        @if(empty($currentLibrary['isDefault']))
          <div class="media-library-manage">
            <form method="post" action="/music/libraries/{{ $currentLibraryId }}/update" class="media-library-rename">
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <input type="text" name="name" class="media-field-input" value="{{ $currentLibrary['name'] ?? '' }}" maxlength="120" required />
              <button type="submit" class="secondary">{{ __('名前を変更') }}</button>
            </form>
            <form method="post" action="/music/libraries/{{ $currentLibraryId }}/delete" onsubmit='return confirm(@json(__('このライブラリを削除しますか？曲はマイリストへ移動します。')))'>
              @csrf
              <input type="hidden" name="returnTo" value="/music" />
              <button type="submit" class="text-btn danger">{{ __('ライブラリを削除') }}</button>
            </form>
          </div>
        @endif

        <h3 class="media-list-subtitle">{{ $currentLibrary['name'] ?? __('マイリスト') }}</h3>
        @if(count($tracks) === 0)
          <p class="hint">
            {{ $filter !== '' ? __('一致する曲がありません。') : __('まだ曲がありません。「曲を追加」からアップロードしてください。') }}
          </p>
        @else
          <form method="get" action="/music" class="media-library-filter">
            <input type="hidden" name="library" value="{{ $currentLibraryId }}" />
            <label class="media-library-filter-label">
              {{ __('ライブラリ内を絞り込み') }}
              <input type="search" name="q" class="media-field-input" value="{{ $filter }}" placeholder="{{ __('タイトルで絞り込み') }}" autocomplete="off" />
            </label>
            <button type="submit" class="secondary">{{ __('絞り込み') }}</button>
          </form>
          <ul class="media-track-list" id="music-track-list">
            @foreach($tracks as $index => $track)
              <li class="media-track-item" data-index="{{ $index }}">
                <button
                  type="button"
                  class="media-track-play"
                  data-url="{{ $track['fileUrl'] }}"
                  data-title="{{ $track['title'] }}"
                  data-meta="{{ $track['sizeLabel'] }}"
                >
                  <span class="media-track-index">{{ $trackOffset + $index + 1 }}</span>
                  <span class="media-track-copy">
                    <strong>{{ $track['title'] }}</strong>
                    <span class="hint">{{ $track['sizeLabel'] }}@if(!empty($track['createdAt'])) · {{ $track['createdAt'] }}@endif</span>
                  </span>
                </button>
                @if(count($libraries) > 1)
                  <form method="post" action="/music/{{ $track['id'] }}/move" class="media-move-form">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <select name="library_id" aria-label="{{ __('移動先') }}" onchange="this.form.submit()">
                      <option value="">{{ __('移動…') }}</option>
                      @foreach($libraries as $lib)
                        @if((int) $lib['id'] !== (int) $currentLibraryId)
                          <option value="{{ $lib['id'] }}">{{ $lib['name'] }}</option>
                        @endif
                      @endforeach
                    </select>
                  </form>
                @endif
                <form method="post" action="/music/{{ $track['id'] }}/delete" onsubmit='return confirm(@json(__('この曲を削除しますか？')))'>
                  @csrf
                  <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                  <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
                </form>
              </li>
            @endforeach
          </ul>
          @if(($totalPages ?? 1) > 1)
            <nav class="media-youtube-pager" id="music-pager" aria-label="{{ __('ライブラリのページ') }}">
              @if(!empty($prevUrl))
                <a class="button-link secondary" href="{{ $prevUrl }}">{{ __('‹ 前へ') }}</a>
              @endif
              <span class="media-pager-status">{{ $pageLabel }}</span>
              @if(!empty($nextUrl))
                <a class="button-link secondary" href="{{ $nextUrl }}">{{ __('次へ ›') }}</a>
              @endif
            </nav>
          @endif
        @endif
      </section>
    </main>
    <script>
      (function () {
        const audio = document.getElementById('music-audio')
        const titleEl = document.getElementById('music-now-title')
        const metaEl = document.getElementById('music-now-meta')
        const buttons = [...document.querySelectorAll('.media-track-play')]

        const uploadForm = document.getElementById('music-upload-form')
        const fileInput = document.getElementById('music-file-input')
        const folderInput = document.getElementById('music-folder-input')
        const folderBtn = document.getElementById('music-folder-btn')
        const statusEl = document.getElementById('music-upload-status')
        const libraryId = @json((string) $currentLibraryId)
        const returnTo = @json($returnTo)
        const audioExtensions = ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'webm', 'flac']
        const t = {
          uploading: @json(__('アップロード中… :done / :total')),
          failed: @json(__('アップロードに失敗しました: :detail')),
          noAudio: @json(__('選んだフォルダに音声ファイルがありませんでした。')),
        }
        // クライアント側で1件ずつ送る。フォルダ取り込みで数百件でも POST 上限に当たらない
        const canUploadWithJs = typeof window.fetch === 'function' && typeof FormData !== 'undefined'

        function fill(text, values) {
          return Object.entries(values).reduce((acc, [key, value]) => acc.replaceAll(':' + key, String(value)), text)
        }

        function csrfToken() {
          return document.querySelector('meta[name="csrf-token"]')?.content || ''
        }

        function isAudioFile(file) {
          if (file.type && file.type.startsWith('audio/')) return true
          const ext = String(file.name || '').split('.').pop()?.toLowerCase()
          return !!ext && audioExtensions.includes(ext)
        }

        function setStatus(text) {
          if (!statusEl) return
          statusEl.hidden = !text
          statusEl.textContent = text || ''
        }

        async function uploadFiles(files) {
          let done = 0
          for (const file of files) {
            const body = new FormData()
            body.append('_token', csrfToken())
            body.append('returnTo', returnTo)
            body.append('library_id', libraryId)
            body.append('tracks[]', file, file.name)

            const res = await fetch('/music', {
              method: 'POST',
              body: body,
              headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
              credentials: 'same-origin',
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok || !data.ok) throw new Error(data.message || String(res.status))
            done += 1
            setStatus(fill(t.uploading, { done: done, total: files.length }))
          }
        }

        async function handlePicked(files) {
          const audio = [...files].filter(isAudioFile).sort((a, b) => String(a.name).localeCompare(String(b.name)))
          if (!audio.length) {
            setStatus(t.noAudio)
            return
          }
          setStatus(fill(t.uploading, { done: 0, total: audio.length }))
          try {
            await uploadFiles(audio)
            window.location.href = returnTo
          } catch (err) {
            setStatus(fill(t.failed, { detail: err?.message || '' }))
          }
        }

        fileInput?.addEventListener('change', (event) => {
          if (!fileInput.files?.length) return
          if (!canUploadWithJs) {
            uploadForm?.submit()
            return
          }
          event.preventDefault()
          void handlePicked(fileInput.files)
        })

        if (folderBtn && folderInput && canUploadWithJs && 'webkitdirectory' in folderInput) {
          folderBtn.hidden = false
          folderBtn.addEventListener('click', () => folderInput.click())
          folderInput.addEventListener('change', () => {
            if (folderInput.files?.length) void handlePicked(folderInput.files)
          })
        }

        function playAt(index) {
          const btn = buttons[index]
          if (!btn || !audio) return
          buttons.forEach((b) => b.classList.toggle('is-active', b === btn))
          audio.src = btn.dataset.url
          titleEl.textContent = btn.dataset.title || ''
          metaEl.textContent = btn.dataset.meta || ''
          audio.play().catch(() => {})
        }

        buttons.forEach((btn, index) => {
          btn.addEventListener('click', () => playAt(index))
        })

        audio?.addEventListener('ended', () => {
          const current = buttons.findIndex((b) => b.classList.contains('is-active'))
          if (current >= 0 && current < buttons.length - 1) playAt(current + 1)
        })
      })()
    </script>
  </body>
</html>
