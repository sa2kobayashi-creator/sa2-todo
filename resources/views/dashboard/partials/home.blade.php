@php
  /** @var array<string, mixed> $home */
  $counts = $home['counts'] ?? [];
  $links = $home['links'] ?? [];
  $nextActions = $home['nextActions'] ?? [];
  $calendar = $home['calendar'] ?? ['connected' => false, 'events' => []];
  $photos = $home['photos'] ?? ['items' => [], 'mode' => 'recent', 'title' => ''];
  $pinnedNotes = $home['pinnedNotes'] ?? [];
@endphp

<section class="dash-home" aria-label="{{ __('今日') }}">
  <header class="dash-home-greeting">
    <p class="dash-home-date">{{ $home['dateLabel'] ?? '' }}</p>
    <h1 class="dash-home-hello">{{ $home['greetingLine'] ?? '' }}</h1>
    <ul class="dash-home-counts" aria-label="{{ __('今日の概要') }}">
      <li>
        <span class="dash-home-count-icon" aria-hidden="true">📅</span>
        <span class="dash-home-count-num">{{ (int) ($counts['events'] ?? 0) }}</span>
        <span class="dash-home-count-label">{{ __('今日の予定') }}</span>
      </li>
      <li>
        <span class="dash-home-count-icon" aria-hidden="true">✓</span>
        <span class="dash-home-count-num">{{ (int) ($counts['todos'] ?? 0) }}</span>
        <span class="dash-home-count-label">{{ __('今日のTodo') }}</span>
      </li>
    </ul>
  </header>

  <div class="dash-home-grid">
    <section class="dash-home-card dash-home-next" aria-labelledby="dash-home-next-title">
      <div class="dash-home-card-head">
        <h2 id="dash-home-next-title">{{ __('次にやること') }}</h2>
      </div>
      @if(count($nextActions) === 0)
        <p class="dash-home-empty">{{ __('今日の ToDo はありません') }}</p>
      @else
        <ul class="dash-home-list">
          @foreach($nextActions as $item)
            <li @class(['is-overdue' => !empty($item['isOverdue'])])>
              <span class="dash-home-list-time">{{ $item['timeLabel'] ?? '' }}</span>
              <a class="dash-home-list-title" href="{{ $item['url'] ?? ($links['todosToday'] ?? '/todos') }}">{{ $item['title'] ?? '' }}</a>
            </li>
          @endforeach
        </ul>
      @endif
      <div class="dash-home-card-foot">
        <a class="dash-home-action" href="{{ $links['todosNew'] ?? '/todos' }}">＋ {{ __('Todoを追加') }}</a>
        <a class="dash-home-more" href="{{ $links['todosToday'] ?? '/todos?today=1' }}">{{ __('今日のTodo') }} →</a>
      </div>
    </section>

    <section class="dash-home-card dash-home-events" aria-labelledby="dash-home-events-title">
      <div class="dash-home-card-head">
        <h2 id="dash-home-events-title">{{ __('今日の予定') }}</h2>
        @if(!empty($calendar['nextInLabel']))
          <p class="dash-home-countdown">{{ __('次の予定まで :label', ['label' => $calendar['nextInLabel']]) }}</p>
        @endif
      </div>
      @if(empty($calendar['connected']))
        <p class="dash-home-empty">{{ __('Googleカレンダーは未連携です') }}</p>
        <div class="dash-home-card-foot">
          <a class="dash-home-action" href="{{ $links['googleCalendarConnect'] ?? '/settings?section=integration#google-calendar' }}">{{ __('Googleカレンダーを連携') }}</a>
        </div>
      @elseif(count($calendar['events'] ?? []) === 0)
        <p class="dash-home-empty">{{ __('今日の予定はありません') }}</p>
        <div class="dash-home-card-foot">
          <a class="dash-home-more" href="{{ $links['googleCalendar'] ?? 'https://calendar.google.com/calendar/r/day' }}" target="_blank" rel="noopener noreferrer">Google Calendar →</a>
        </div>
      @else
        <ul class="dash-home-list">
          @foreach($calendar['events'] as $event)
            <li @class(['is-next' => ($calendar['next']['id'] ?? null) === ($event['id'] ?? null)])>
              <span class="dash-home-list-time">{{ $event['timeLabel'] ?? '' }}</span>
              @if(!empty($event['htmlLink']))
                <a class="dash-home-list-title" href="{{ $event['htmlLink'] }}" target="_blank" rel="noopener noreferrer">{{ $event['title'] ?? '' }}</a>
              @else
                <span class="dash-home-list-title">{{ $event['title'] ?? '' }}</span>
              @endif
            </li>
          @endforeach
        </ul>
        <div class="dash-home-card-foot">
          <a class="dash-home-more" href="{{ $links['googleCalendar'] ?? 'https://calendar.google.com/calendar/r/day' }}" target="_blank" rel="noopener noreferrer">Google Calendar →</a>
        </div>
      @endif
    </section>

    <section class="dash-home-card dash-home-photos" aria-labelledby="dash-home-photos-title">
      <div class="dash-home-card-head">
        <h2 id="dash-home-photos-title">{{ __('Photos') }}</h2>
        <p class="dash-home-card-sub">
          {{ $photos['title'] ?? __('最近の写真') }}
          @if(!empty($photos['subtitle']))
            <span class="dash-home-card-note">{{ $photos['subtitle'] }}</span>
          @endif
        </p>
      </div>
        @if(count($photos['items'] ?? []) === 0)
        <p class="dash-home-empty">{{ __('まだ写真がありません') }}</p>
      @else
        @php
          $photoPool = array_values($photos['pool'] ?? $photos['items']);
          $photoVisible = max(1, min((int) ($photos['visible'] ?? 4), count($photoPool)));
          $photoRotateMs = max(5_000, (int) ($photos['rotateMs'] ?? 60_000));
          $photosHref = $links['photos'] ?? '/photos';
          $canRotate = count($photoPool) > $photoVisible;
        @endphp
        <ul
          class="dash-home-photo-grid"
          id="dash-home-photo-grid"
          data-photos-href="{{ $photosHref }}"
          data-visible="{{ $photoVisible }}"
          data-rotate-ms="{{ $photoRotateMs }}"
          data-can-rotate="{{ $canRotate ? '1' : '0' }}"
        >
          @foreach(array_slice($photoPool, 0, $photoVisible) as $index => $photo)
            <li>
              <button
                type="button"
                class="dash-home-photo-link"
                data-photo-index="{{ $index }}"
                title="{{ $photo['originalName'] ?? '' }}"
                aria-label="{{ __('写真を表示') }}"
              >
                <img
                  src="{{ $photo['thumbUrl'] ?? ($photo['url'] ?? '') }}"
                  alt=""
                  loading="eager"
                  decoding="async"
                />
              </button>
            </li>
          @endforeach
        </ul>

        <div class="dash-home-lightbox" id="dash-home-lightbox" hidden>
          <div class="dash-home-lightbox-backdrop" data-dash-lb-close></div>
          <button type="button" class="dash-home-lightbox-close" data-dash-lb-close aria-label="{{ __('閉じる') }}">×</button>
          <div class="dash-home-lightbox-stage">
            <img src="" alt="" id="dash-home-lb-image" />
            <video src="" id="dash-home-lb-video" controls playsinline preload="metadata" hidden></video>
            <p class="dash-home-lightbox-meta" id="dash-home-lb-meta" hidden></p>
            <a class="dash-home-lightbox-open" id="dash-home-lb-open" href="/photos">{{ __('Photosで開く') }} →</a>
          </div>
        </div>

        <script type="application/json" id="dash-home-photo-pool">{!! json_encode($photoPool, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) !!}</script>
        <script>
          (function () {
            const grid = document.getElementById('dash-home-photo-grid')
            const poolEl = document.getElementById('dash-home-photo-pool')
            const lightbox = document.getElementById('dash-home-lightbox')
            const lbImage = document.getElementById('dash-home-lb-image')
            const lbVideo = document.getElementById('dash-home-lb-video')
            const lbMeta = document.getElementById('dash-home-lb-meta')
            const lbOpen = document.getElementById('dash-home-lb-open')
            if (!grid || !poolEl || !lightbox) return

            let pool = []
            try {
              pool = JSON.parse(poolEl.textContent || '[]')
            } catch (_) {
              return
            }
            if (!Array.isArray(pool) || pool.length === 0) return

            const visible = Math.max(1, parseInt(grid.getAttribute('data-visible') || '4', 10) || 4)
            const rotateMs = Math.max(5000, parseInt(grid.getAttribute('data-rotate-ms') || '60000', 10) || 60000)
            const canRotate = grid.getAttribute('data-can-rotate') === '1' && pool.length > visible
            let offset = 0
            const openLabel = @json(__('写真を表示'))

            function escapeAttr(value) {
              return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
            }

            function stopVideo() {
              if (!lbVideo) return
              lbVideo.pause()
              lbVideo.removeAttribute('src')
              while (lbVideo.firstChild) lbVideo.removeChild(lbVideo.firstChild)
              lbVideo.load()
              lbVideo.hidden = true
            }

            function paintGrid() {
              const slice = []
              for (let i = 0; i < Math.min(visible, pool.length); i++) {
                const idx = (offset + i) % pool.length
                slice.push({ photo: pool[idx], index: idx })
              }
              grid.innerHTML = slice.map(({ photo, index }) => {
                const src = escapeAttr(photo.thumbUrl || photo.url || '')
                const title = escapeAttr(photo.originalName || '')
                return (
                  '<li><button type="button" class="dash-home-photo-link" data-photo-index="' + index + '" title="' + title + '" aria-label="' + escapeAttr(openLabel) + '">' +
                  '<img src="' + src + '" alt="" loading="eager" decoding="async" />' +
                  '</button></li>'
                )
              }).join('')
            }

            function showPhoto(index, warmSrc) {
              if (!pool.length) return
              const photo = pool[(index + pool.length) % pool.length]
              if (!photo) return
              const isVideo = photo.mediaKind === 'video'
              const thumb = photo.thumbUrl || warmSrc || ''
              const full = photo.fileUrl || photo.url || (photo.id ? ('/photos/' + photo.id + '/file') : '')
              const instant = thumb || full
              if (!instant) return

              stopVideo()
              if (lbMeta) {
                const label = [photo.takenAt, photo.originalName].filter(Boolean).join(' · ')
                lbMeta.textContent = label
                lbMeta.hidden = !label
              }
              if (lbOpen) lbOpen.href = photo.href || ('/photos?photo=' + (photo.id || ''))

              // 先に開いて、グリッドで既に読まれたサムネを即表示する
              lightbox.hidden = false
              document.body.classList.add('dash-home-lightbox-open')

              if (isVideo) {
                if (lbImage) {
                  if (thumb) {
                    lbImage.hidden = false
                    lbImage.src = thumb
                    lbImage.alt = photo.originalName || ''
                  } else {
                    lbImage.hidden = true
                    lbImage.removeAttribute('src')
                  }
                }
                if (lbVideo) {
                  lbVideo.onloadeddata = () => {
                    if (lightbox.hidden) return
                    if (lbImage) {
                      lbImage.hidden = true
                      lbImage.removeAttribute('src')
                    }
                  }
                  lbVideo.hidden = false
                  lbVideo.src = full || instant
                  lbVideo.load()
                }
                return
              }

              if (lbVideo) lbVideo.hidden = true
              if (!lbImage) return
              lbImage.hidden = false
              lbImage.alt = photo.originalName || ''
              lbImage.onerror = () => {
                const fallback = full && full !== lbImage.getAttribute('src') ? full : ''
                if (fallback) {
                  lbImage.onerror = null
                  lbImage.src = fallback
                }
              }
              // キャッシュ済みサムネを先に出し、フル画像は裏で差し替え
              lbImage.src = instant
              if (full && full !== instant) {
                const upgrade = new Image()
                upgrade.decoding = 'async'
                upgrade.onload = () => {
                  if (lightbox.hidden) return
                  lbImage.onerror = null
                  lbImage.src = full
                }
                upgrade.src = full
              }
            }

            function closeLightbox() {
              stopVideo()
              if (lbImage) {
                lbImage.onerror = null
                lbImage.removeAttribute('src')
              }
              if (lbVideo) lbVideo.onloadeddata = null
              lightbox.hidden = true
              document.body.classList.remove('dash-home-lightbox-open')
            }

            grid.addEventListener('click', (e) => {
              const btn = e.target.closest('[data-photo-index]')
              if (!btn || !grid.contains(btn)) return
              e.preventDefault()
              const thumbImg = btn.querySelector('img')
              const warmSrc = thumbImg ? (thumbImg.currentSrc || thumbImg.src || '') : ''
              showPhoto(Number(btn.getAttribute('data-photo-index') || 0), warmSrc)
            })

            lightbox.querySelectorAll('[data-dash-lb-close]').forEach((el) => {
              el.addEventListener('click', closeLightbox)
            })
            document.addEventListener('keydown', (e) => {
              if (lightbox.hidden) return
              if (e.key === 'Escape') closeLightbox()
            })

            if (canRotate) {
              window.setInterval(() => {
                if (!lightbox.hidden) return
                offset = (offset + visible) % pool.length
                grid.classList.add('is-rotating')
                window.setTimeout(() => {
                  paintGrid()
                  grid.classList.remove('is-rotating')
                }, 220)
              }, rotateMs)
            }
          })()
        </script>
      @endif
      <div class="dash-home-card-foot">
        @if(((int) ($photos['addedToday'] ?? 0)) > 0)
          <span class="dash-home-card-note">{{ __('今日追加 :count枚', ['count' => (int) $photos['addedToday']]) }}</span>
        @endif
        <a class="dash-home-more" href="{{ $links['photos'] ?? '/photos' }}">{{ __('Photosを見る') }} →</a>
      </div>
    </section>
  </div>

  <footer class="dash-home-footer">
    @if(count($pinnedNotes) > 0)
      <div class="dash-home-pins">
        <span class="dash-home-pins-label">{{ __('ピン留めメモ') }}</span>
        <ul class="dash-home-pins-list">
          @foreach($pinnedNotes as $note)
            <li><a href="{{ $note['url'] ?? '/notes' }}">{{ $note['title'] ?? '' }}</a></li>
          @endforeach
        </ul>
      </div>
    @endif
    <nav class="dash-home-links" aria-label="{{ __('ショートカット') }}">
      <a href="{{ $links['notes'] ?? '/notes' }}">{{ __('メモ') }}</a>
      <span aria-hidden="true">｜</span>
      <a href="{{ $links['aiSettings'] ?? '/settings?section=ai' }}">{{ __('AI設定') }}</a>
      <span aria-hidden="true">｜</span>
      <a href="{{ $links['map'] ?? '/map' }}">{{ __('Map') }}</a>
      <span aria-hidden="true">｜</span>
      <a href="{{ $links['transit'] ?? '/transit' }}">{{ __('路線検索') }}</a>
    </nav>
  </footer>
</section>
