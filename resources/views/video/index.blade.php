<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('動画') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="media-player-page video-page">
    @include('partials.header', ['active' => 'video'])
    <main class="page-main media-player-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <section class="panel media-player-hero">
        <div>
          <h1 class="media-player-title">{{ __('動画') }}</h1>
          <p class="hint">{{ __('YouTube検索・リンク貼り付け・MP4アップロードで再生できます。') }}</p>
        </div>
        <div class="media-video-add-actions">
          <form method="post" action="/video" enctype="multipart/form-data" class="media-upload-form">
            @csrf
            <input type="hidden" name="returnTo" value="/video" />
            <label class="button-link media-upload-btn">
              <input type="file" name="videos[]" accept="video/mp4,.mp4" multiple required hidden />
              <span>{{ __('MP4を追加') }}</span>
            </label>
            <p class="hint">{{ __('1ファイル最大 :size', ['size' => $maxUploadLabel ?? '800 MB']) }}</p>
          </form>
        </div>
      </section>

      <section class="panel media-youtube-search-panel" id="youtube-search-panel">
        <h2 class="media-list-title">{{ __('YouTubeで探す') }}</h2>
        @if(empty($youtubeSearchReady))
          <p class="hint">
            {{ __('検索を使うには YouTube Data API キーが必要です。') }}
            <a href="/settings?section=ai#youtube-api-settings">{{ __('AI設定へ') }}</a>
          </p>
        @endif
        <form class="media-youtube-search-form" id="youtube-search-form">
          <input
            type="search"
            id="youtube-search-q"
            name="q"
            placeholder="{{ __('キーワードで検索（例: ジャズ ライブ）') }}"
            autocomplete="off"
            @disabled(empty($youtubeSearchReady))
          />
          <button type="submit" class="button-link" @disabled(empty($youtubeSearchReady))>{{ __('検索') }}</button>
        </form>
        <p class="hint" id="youtube-search-status" aria-live="polite"></p>
        <div class="media-youtube-results" id="youtube-search-results" hidden></div>
        <div class="media-youtube-pager" id="youtube-search-pager" hidden>
          <button type="button" class="secondary" id="youtube-search-prev" hidden>{{ __('前へ') }}</button>
          <button type="button" class="secondary" id="youtube-search-next" hidden>{{ __('次へ') }}</button>
        </div>
      </section>

      <section class="panel media-youtube-add">
        <h2 class="media-list-title">{{ __('YouTubeリンクを追加') }}</h2>
        <form method="post" action="/video/youtube" class="media-youtube-form">
          @csrf
          <input type="hidden" name="returnTo" value="/video" />
          <label>
            {{ __('YouTube URL') }}
            <input
              type="url"
              name="youtube_url"
              required
              placeholder="https://www.youtube.com/watch?v=... または https://youtu.be/..."
              autocomplete="off"
            />
          </label>
          <label>
            {{ __('タイトル（任意）') }}
            <input type="text" name="title" maxlength="255" placeholder="{{ __('未入力なら自動取得') }}" autocomplete="off" />
          </label>
          <button type="submit" class="button-link">{{ __('リンクを追加') }}</button>
        </form>
      </section>

      <section class="panel media-player-stage media-player-stage-video">
        <div class="media-video-frame" id="video-local-wrap">
          <video id="video-player" controls playsinline preload="metadata" class="media-video-el"></video>
        </div>
        <div class="media-video-frame media-youtube-frame" id="video-youtube-wrap" hidden>
          <iframe
            id="youtube-player"
            class="media-youtube-el"
            title="YouTube"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen
            referrerpolicy="strict-origin-when-cross-origin"
          ></iframe>
        </div>
        <div class="media-now-playing">
          <strong id="video-now-title">{{ __('動画を選択してください') }}</strong>
          <span class="hint" id="video-now-meta"></span>
        </div>
      </section>

      <section class="panel">
        <h2 class="media-list-title">{{ __('マイリスト') }}</h2>
        @if(count($playlist) === 0)
          <p class="hint">{{ __('まだ動画がありません。検索結果から追加するか、リンク／MP4を登録してください。') }}</p>
        @else
          <ul class="media-track-list media-video-list" id="video-track-list">
            @foreach($playlist as $index => $item)
              <li class="media-track-item" data-index="{{ $index }}">
                <button
                  type="button"
                  class="media-track-play media-video-play"
                  data-source="{{ $item['source'] }}"
                  data-url="{{ $item['url'] ?? '' }}"
                  data-embed="{{ $item['embedUrl'] ?? '' }}"
                  data-title="{{ $item['title'] }}"
                  data-meta="{{ $item['meta'] ?? ($item['createdAt'] ?? '') }}"
                >
                  <span class="media-video-thumb" aria-hidden="true">
                    @if(!empty($item['thumbUrl']))
                      <img src="{{ $item['thumbUrl'] }}" alt="" loading="lazy" />
                    @else
                      <span class="media-video-thumb-fallback">▶</span>
                    @endif
                  </span>
                  <span class="media-track-copy">
                    <strong>{{ $item['title'] }}</strong>
                    <span class="hint">
                      @if(($item['source'] ?? '') === 'youtube')
                        YouTube
                      @else
                        {{ __('アップロード') }}
                      @endif
                      @if(!empty($item['meta']) || !empty($item['createdAt']))
                        · {{ $item['meta'] ?? $item['createdAt'] }}
                      @endif
                    </span>
                  </span>
                </button>
                @if(($item['source'] ?? '') === 'youtube')
                  <form method="post" action="/video/youtube/{{ $item['id'] }}/delete" onsubmit='return confirm(@json(__('このYouTube動画を削除しますか？')))'>
                    @csrf
                    <input type="hidden" name="returnTo" value="/video" />
                    <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
                  </form>
                @else
                  <a class="text-btn" href="/photos?photo={{ $item['photoId'] ?? $item['id'] }}">{{ __('Photosで開く') }}</a>
                @endif
              </li>
            @endforeach
          </ul>
        @endif
      </section>
    </main>
    <script>
      (function () {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
        const player = document.getElementById('video-player')
        const youtube = document.getElementById('youtube-player')
        const localWrap = document.getElementById('video-local-wrap')
        const youtubeWrap = document.getElementById('video-youtube-wrap')
        const titleEl = document.getElementById('video-now-title')
        const metaEl = document.getElementById('video-now-meta')
        const buttons = () => [...document.querySelectorAll('.media-video-play')]
        const uploadInput = document.querySelector('.media-upload-form input[type="file"]')
        const searchReady = @json(!empty($youtubeSearchReady))
        const strings = {
          searching: @json(__('検索中…')),
          noResults: @json(__('該当する動画がありません。')),
          searchFailed: @json(__('検索に失敗しました。')),
          play: @json(__('再生')),
          save: @json(__('リストに追加')),
          saved: @json(__('追加しました')),
          saveFailed: @json(__('追加に失敗しました')),
          notReady: @json(__('YouTube検索が未設定です。')),
        }

        uploadInput?.addEventListener('change', () => {
          if (uploadInput.files?.length) uploadInput.closest('form')?.submit()
        })

        function stopLocal() {
          if (!player) return
          player.pause()
          player.removeAttribute('src')
          player.load()
        }

        function stopYoutube() {
          if (!youtube) return
          youtube.removeAttribute('src')
        }

        function playEmbed(embedUrl, title, meta) {
          stopLocal()
          if (localWrap) localWrap.hidden = true
          if (youtubeWrap) youtubeWrap.hidden = false
          if (titleEl) titleEl.textContent = title || ''
          if (metaEl) metaEl.textContent = meta || ''
          if (youtube && embedUrl) {
            const join = embedUrl.includes('?') ? '&' : '?'
            youtube.src = embedUrl + join + 'autoplay=1'
          }
        }

        function playLocal(url, title, meta) {
          stopYoutube()
          if (youtubeWrap) youtubeWrap.hidden = true
          if (localWrap) localWrap.hidden = false
          if (titleEl) titleEl.textContent = title || ''
          if (metaEl) metaEl.textContent = meta || ''
          if (player) {
            player.src = url || ''
            player.play().catch(() => {})
          }
        }

        function playAt(index) {
          const list = buttons()
          const btn = list[index]
          if (!btn) return
          list.forEach((b) => b.classList.toggle('is-active', b === btn))
          const source = btn.dataset.source || 'upload'
          if (source === 'youtube') {
            playEmbed(btn.dataset.embed || '', btn.dataset.title || '', btn.dataset.meta || '')
            return
          }
          playLocal(btn.dataset.url || '', btn.dataset.title || '', btn.dataset.meta || '')
        }

        document.getElementById('video-track-list')?.addEventListener('click', (e) => {
          const btn = e.target.closest('.media-video-play')
          if (!btn) return
          const list = buttons()
          const index = list.indexOf(btn)
          if (index >= 0) playAt(index)
        })

        player?.addEventListener('ended', () => {
          const list = buttons()
          const current = list.findIndex((b) => b.classList.contains('is-active'))
          if (current >= 0 && current < list.length - 1) playAt(current + 1)
        })

        if (buttons().length > 0) playAt(0)

        // --- YouTube search ---
        const searchForm = document.getElementById('youtube-search-form')
        const searchInput = document.getElementById('youtube-search-q')
        const statusEl = document.getElementById('youtube-search-status')
        const resultsEl = document.getElementById('youtube-search-results')
        const pagerEl = document.getElementById('youtube-search-pager')
        const prevBtn = document.getElementById('youtube-search-prev')
        const nextBtn = document.getElementById('youtube-search-next')
        let lastQuery = ''
        let nextPageToken = null
        let prevPageToken = null

        function setStatus(msg, isError) {
          if (!statusEl) return
          statusEl.textContent = msg || ''
          statusEl.classList.toggle('is-error', Boolean(isError && msg))
        }

        function renderResults(items) {
          if (!resultsEl) return
          resultsEl.innerHTML = ''
          if (!items.length) {
            resultsEl.hidden = true
            return
          }
          resultsEl.hidden = false
          items.forEach((item) => {
            const card = document.createElement('article')
            card.className = 'yt-result-card'
            card.innerHTML = `
              <button type="button" class="yt-result-play">
                <span class="yt-result-thumb"><img alt="" loading="lazy" /></span>
                <span class="yt-result-copy">
                  <strong class="yt-result-title"></strong>
                  <span class="hint yt-result-channel"></span>
                </span>
              </button>
              <button type="button" class="secondary yt-result-save"></button>
            `
            const img = card.querySelector('img')
            if (img) img.src = item.thumbUrl || ''
            const title = card.querySelector('.yt-result-title')
            if (title) title.textContent = item.title || ''
            const channel = card.querySelector('.yt-result-channel')
            if (channel) channel.textContent = [item.channelTitle, item.publishedAt].filter(Boolean).join(' · ')
            const saveBtn = card.querySelector('.yt-result-save')
            if (saveBtn) saveBtn.textContent = strings.save

            card.querySelector('.yt-result-play')?.addEventListener('click', () => {
              playEmbed(item.embedUrl, item.title, item.channelTitle || '')
              buttons().forEach((b) => b.classList.remove('is-active'))
            })

            saveBtn?.addEventListener('click', async () => {
              saveBtn.disabled = true
              try {
                const res = await fetch('/video/youtube', {
                  method: 'POST',
                  headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                  },
                  body: JSON.stringify({
                    youtube_id: item.youtubeId,
                    title: item.title,
                    thumb_url: item.thumbUrl,
                  }),
                })
                const data = await res.json().catch(() => ({}))
                if (!res.ok || !data.ok) {
                  window.alert(data.message || strings.saveFailed)
                  return
                }
                saveBtn.textContent = strings.saved
                window.location.reload()
              } catch (_) {
                window.alert(strings.saveFailed)
              } finally {
                saveBtn.disabled = false
              }
            })

            resultsEl.appendChild(card)
          })
        }

        function updatePager() {
          if (!pagerEl) return
          const hasPager = Boolean(nextPageToken || prevPageToken)
          pagerEl.hidden = !hasPager
          if (prevBtn) {
            prevBtn.hidden = !prevPageToken
            prevBtn.disabled = !prevPageToken
          }
          if (nextBtn) {
            nextBtn.hidden = !nextPageToken
            nextBtn.disabled = !nextPageToken
          }
        }

        async function runSearch(pageToken) {
          if (!searchReady) {
            setStatus(strings.notReady, true)
            return
          }
          const q = String(searchInput?.value || '').trim()
          if (!q) return
          lastQuery = q
          setStatus(strings.searching, false)
          try {
            const params = new URLSearchParams({ q })
            if (pageToken) params.set('pageToken', pageToken)
            const res = await fetch('/video/youtube/search?' + params.toString(), {
              headers: { Accept: 'application/json' },
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok || !data.ok) {
              setStatus(data.message || strings.searchFailed, true)
              renderResults([])
              nextPageToken = null
              prevPageToken = null
              updatePager()
              return
            }
            const items = Array.isArray(data.items) ? data.items : []
            nextPageToken = data.nextPageToken || null
            prevPageToken = data.prevPageToken || null
            updatePager()
            if (!items.length) {
              setStatus(strings.noResults, false)
              renderResults([])
              return
            }
            const total = data.totalResults != null ? `（${Number(data.totalResults).toLocaleString()}）` : ''
            setStatus(`${items.length}件表示${total}`, false)
            renderResults(items)
          } catch (_) {
            setStatus(strings.searchFailed, true)
          }
        }

        searchForm?.addEventListener('submit', (e) => {
          e.preventDefault()
          runSearch(null)
        })
        prevBtn?.addEventListener('click', () => {
          if (prevPageToken) runSearch(prevPageToken)
        })
        nextBtn?.addEventListener('click', () => {
          if (nextPageToken) runSearch(nextPageToken)
        })
      })()
    </script>
  </body>
</html>
