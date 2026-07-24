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
          <p class="hint">{{ __('アップロードしたMP4と、YouTubeリンクをこの画面で再生できます。') }}</p>
        </div>
        <div class="media-video-add-actions">
          <form method="post" action="/video" enctype="multipart/form-data" class="media-upload-form">
            @csrf
            <input type="hidden" name="returnTo" value="/video" />
            <label class="button-link media-upload-btn">
              <input type="file" name="videos[]" accept="video/mp4,.mp4" multiple required hidden />
              <span>{{ __('動画を追加') }}</span>
            </label>
            <p class="hint">{{ __('1ファイル最大 :size（MP4）', ['size' => $maxUploadLabel ?? '800 MB']) }}</p>
          </form>
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
        <p class="hint">{{ __('APIキー不要です。公開動画のURLを貼ると埋め込み再生できます。') }}</p>
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
        <h2 class="media-list-title">{{ __('動画一覧') }}</h2>
        @if(count($playlist) === 0)
          <p class="hint">{{ __('まだ動画がありません。MP4のアップロードか YouTube リンクを追加してください。') }}</p>
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
        const player = document.getElementById('video-player')
        const youtube = document.getElementById('youtube-player')
        const localWrap = document.getElementById('video-local-wrap')
        const youtubeWrap = document.getElementById('video-youtube-wrap')
        const titleEl = document.getElementById('video-now-title')
        const metaEl = document.getElementById('video-now-meta')
        const buttons = [...document.querySelectorAll('.media-video-play')]
        const uploadInput = document.querySelector('.media-upload-form input[type="file"]')
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

        function playAt(index) {
          const btn = buttons[index]
          if (!btn) return
          buttons.forEach((b) => b.classList.toggle('is-active', b === btn))
          const source = btn.dataset.source || 'upload'
          titleEl.textContent = btn.dataset.title || ''
          metaEl.textContent = btn.dataset.meta || ''

          if (source === 'youtube') {
            stopLocal()
            if (localWrap) localWrap.hidden = true
            if (youtubeWrap) youtubeWrap.hidden = false
            const embed = btn.dataset.embed || ''
            if (youtube && embed) {
              const join = embed.includes('?') ? '&' : '?'
              youtube.src = embed + join + 'autoplay=1'
            }
            return
          }

          stopYoutube()
          if (youtubeWrap) youtubeWrap.hidden = true
          if (localWrap) localWrap.hidden = false
          if (player) {
            player.src = btn.dataset.url || ''
            player.play().catch(() => {})
          }
        }

        buttons.forEach((btn, index) => {
          btn.addEventListener('click', () => playAt(index))
        })

        player?.addEventListener('ended', () => {
          const current = buttons.findIndex((b) => b.classList.contains('is-active'))
          if (current >= 0 && current < buttons.length - 1) playAt(current + 1)
        })

        if (buttons.length > 0) playAt(0)
      })()
    </script>
  </body>
</html>
