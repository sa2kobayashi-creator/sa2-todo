<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('音楽') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="media-player-page music-page">
    @include('partials.header', ['active' => 'music'])
    <main class="page-main media-player-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <section class="panel media-player-hero">
        <div>
          <h1 class="media-player-title">{{ __('音楽') }}</h1>
          <p class="hint">{{ __('再生専用です。編集機能は今後追加予定です。') }}</p>
        </div>
        <form method="post" action="/music" enctype="multipart/form-data" class="media-upload-form">
          @csrf
          <input type="hidden" name="returnTo" value="/music" />
          <label class="button-link media-upload-btn">
            <input type="file" name="tracks[]" accept="audio/*,.mp3,.m4a,.wav,.ogg,.aac,.webm" multiple required hidden />
            <span>{{ __('曲を追加') }}</span>
          </label>
          <p class="hint">{{ __('1ファイル最大 :size', ['size' => $maxUploadLabel ?? '100 MB']) }}</p>
        </form>
      </section>

      <section class="panel media-player-stage">
        <audio id="music-audio" controls preload="metadata" class="media-audio-el"></audio>
        <div class="media-now-playing">
          <strong id="music-now-title">{{ __('曲を選択してください') }}</strong>
          <span class="hint" id="music-now-meta"></span>
        </div>
      </section>

      <section class="panel">
        <h2 class="media-list-title">{{ __('プレイリスト') }}</h2>
        @if(count($tracks) === 0)
          <p class="hint">{{ __('まだ曲がありません。「曲を追加」からアップロードしてください。') }}</p>
        @else
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
                  <span class="media-track-index">{{ $index + 1 }}</span>
                  <span class="media-track-copy">
                    <strong>{{ $track['title'] }}</strong>
                    <span class="hint">{{ $track['sizeLabel'] }}@if(!empty($track['createdAt'])) · {{ $track['createdAt'] }}@endif</span>
                  </span>
                </button>
                <form method="post" action="/music/{{ $track['id'] }}/delete" onsubmit='return confirm(@json(__('この曲を削除しますか？')))'>
                  @csrf
                  <input type="hidden" name="returnTo" value="/music" />
                  <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
                </form>
              </li>
            @endforeach
          </ul>
        @endif
      </section>
    </main>
    <script>
      (function () {
        const audio = document.getElementById('music-audio')
        const titleEl = document.getElementById('music-now-title')
        const metaEl = document.getElementById('music-now-meta')
        const buttons = [...document.querySelectorAll('.media-track-play')]
        const uploadInput = document.querySelector('.media-upload-form input[type="file"]')
        uploadInput?.addEventListener('change', () => {
          if (uploadInput.files?.length) uploadInput.closest('form')?.submit()
        })

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
