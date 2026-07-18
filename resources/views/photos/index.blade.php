<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a1f24" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="Sa2 Photos" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}" />
    <link rel="apple-touch-icon" href="{{ asset('icons/pwa-192.png') }}" />
    <title>Photos - Sa2 ToDo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="photos-page">
    @include('partials.header', ['active' => 'photos'])
    <main class="page-main photos-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <section class="photos-hero">
        <div class="photos-hero-copy">
          <p class="photos-kicker">Album</p>
          <h1 class="photos-title">{{ $selectedAlbum['name'] ?? 'Photos' }}</h1>
          <p class="photos-lead">
            @if($selectedAlbum)
              {{ $selectedAlbum['photoCount'] }}枚 · 表紙を選んでアルバムらしく
            @else
              旅・日常・お気に入りを、見ていて気持ちよく残す場所。
            @endif
          </p>
        </div>
        <div class="photos-hero-actions">
          <label class="photos-upload-btn">
            <input
              type="file"
              name="photos[]"
              id="photos-file-input"
              accept="image/*,.heic,.heif"
              multiple
              hidden
            />
            <span class="photos-upload-btn-label">写真を追加</span>
          </label>
          <button type="button" class="photos-secondary-btn" id="photos-album-open">アルバム作成</button>
          <button type="button" class="photos-secondary-btn" id="photos-pwa-install" hidden>ホーム画面に追加</button>
        </div>
      </section>

      <aside class="photos-sync-tip" aria-label="スマホからの追加・PWA">
        <strong>スマホ同期 / アプリ化</strong>
        <span>スマホブラウザでこのページを開き「写真を追加」。対応端末では「ホーム画面に追加」でアプリのように使えます。</span>
      </aside>

      <section class="photos-album-covers" aria-label="アルバム">
        <a href="/photos" @class(['photos-cover-card', 'is-all', 'is-active' => !$selectedAlbumId])>
          <span class="photos-cover-all-label">すべて</span>
          <span class="photos-cover-meta">{{ count($photos) }}枚を表示中</span>
        </a>
        @foreach($albums as $album)
          <a
            href="/photos?album={{ $album['id'] }}"
            @class(['photos-cover-card', 'is-active' => $selectedAlbumId === $album['id']])
          >
            @if(!empty($album['coverUrl']))
              <img src="{{ $album['coverUrl'] }}" alt="" class="photos-cover-image" loading="lazy" />
            @else
              <span class="photos-cover-placeholder" aria-hidden="true"></span>
            @endif
            <span class="photos-cover-shade"></span>
            <span class="photos-cover-text">
              <strong>{{ $album['name'] }}</strong>
              <span>{{ $album['photoCount'] }}枚</span>
            </span>
          </a>
        @endforeach
      </section>

      <form method="post" action="/photos" enctype="multipart/form-data" id="photos-upload-form" class="photos-upload-form">
        @csrf
        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
        @if($selectedAlbumId)
          <input type="hidden" name="album_id" value="{{ $selectedAlbumId }}" />
        @endif
        <input type="file" name="photos[]" id="photos-form-files" accept="image/*,.heic,.heif" multiple hidden />
      </form>

      @if(count($photos) === 0)
        <div class="photos-empty" id="photos-dropzone">
          <div class="photos-empty-frame">
            <p class="photos-empty-title">まだ写真がありません</p>
            <p class="photos-empty-text">ドラッグ＆ドロップ、または下のボタンから追加できます。<br />スマホならカメラロールと直結します。</p>
            <label class="photos-upload-btn photos-upload-btn-large">
              <span class="photos-upload-btn-label">最初の一枚を入れる</span>
            </label>
          </div>
        </div>
      @else
        <div class="photos-timeline" id="photos-gallery">
          @php $flatIndex = 0; @endphp
          @foreach($photoGroups as $group)
            <section class="photos-day-group">
              <header class="photos-day-header">
                <h2 class="photos-day-label">{{ $group['label'] }}</h2>
                <span class="photos-day-count">{{ count($group['photos']) }}枚</span>
              </header>
              <div class="photos-masonry">
                @foreach($group['photos'] as $photo)
                  <button
                    type="button"
                    class="photos-tile"
                    style="--photo-ratio: {{ max(0.66, min(1.45, ($photo['height'] && $photo['width']) ? ($photo['height'] / $photo['width']) : 1)) }}"
                    data-photo-index="{{ $flatIndex }}"
                    aria-label="{{ $photo['caption'] ?: ($photo['originalName'] ?: '写真を表示') }}"
                  >
                    <img
                      src="{{ $photo['thumbUrl'] }}"
                      alt="{{ $photo['caption'] ?: ($photo['originalName'] ?: '写真') }}"
                      loading="lazy"
                      decoding="async"
                    />
                    <span class="photos-tile-glow" aria-hidden="true"></span>
                  </button>
                  @php $flatIndex++; @endphp
                @endforeach
              </div>
            </section>
          @endforeach
        </div>
      @endif
    </main>

    <div class="photos-lightbox" id="photos-lightbox" hidden>
      <div class="photos-lightbox-backdrop" data-close-lightbox></div>
      <div class="photos-lightbox-stage" role="dialog" aria-modal="true" aria-label="写真プレビュー">
        <button type="button" class="photos-lightbox-close" data-close-lightbox aria-label="閉じる">×</button>
        <button type="button" class="photos-lightbox-nav is-prev" id="photos-lightbox-prev" aria-label="前の写真">‹</button>
        <img src="" alt="" id="photos-lightbox-image" />
        <button type="button" class="photos-lightbox-nav is-next" id="photos-lightbox-next" aria-label="次の写真">›</button>
        <div class="photos-lightbox-meta">
          <div>
            <p class="photos-lightbox-caption" id="photos-lightbox-caption"></p>
            <p class="photos-lightbox-date" id="photos-lightbox-date"></p>
          </div>
          <div class="photos-lightbox-actions">
            @if($selectedAlbumId)
              <form method="post" action="/photos/albums/{{ $selectedAlbumId }}/cover" id="photos-cover-form">
                @csrf
                <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                <input type="hidden" name="photo_id" id="photos-cover-photo-id" value="" />
                <button type="submit" class="photos-cover-btn" id="photos-cover-btn">表紙にする</button>
              </form>
            @endif
            <form method="post" action="" id="photos-delete-form" onsubmit="return confirm('この写真を削除しますか？')">
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <button type="submit" class="photos-delete-btn">削除</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="modal modal-centered" id="photos-album-modal" hidden>
      <div class="modal-backdrop" data-close-album-modal></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="photos-album-modal-title">
        <div class="modal-header">
          <h2 id="photos-album-modal-title">アルバムを作成</h2>
          <button type="button" class="modal-close" data-close-album-modal aria-label="閉じる">×</button>
        </div>
        <form method="post" action="/photos/albums" class="modal-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <label>
            アルバム名
            <input type="text" name="name" required maxlength="120" placeholder="例: 旅行 2026" autocomplete="off" />
          </label>
          <label>
            説明（任意）
            <input type="text" name="description" maxlength="500" placeholder="短いメモ" autocomplete="off" />
          </label>
          <div class="modal-actions">
            <button type="button" class="secondary" data-close-album-modal>キャンセル</button>
            <button type="submit">作成</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      (() => {
        const photos = @json($photos);
        const selectedAlbumId = @json($selectedAlbumId);
        const coverPhotoId = @json($selectedAlbum['coverPhotoId'] ?? null);
        const fileInput = document.getElementById('photos-file-input')
        const form = document.getElementById('photos-upload-form')
        const formFiles = document.getElementById('photos-form-files')
        const emptyZone = document.getElementById('photos-dropzone')
        const lightbox = document.getElementById('photos-lightbox')
        const lightboxImage = document.getElementById('photos-lightbox-image')
        const lightboxCaption = document.getElementById('photos-lightbox-caption')
        const lightboxDate = document.getElementById('photos-lightbox-date')
        const deleteForm = document.getElementById('photos-delete-form')
        const coverForm = document.getElementById('photos-cover-form')
        const coverPhotoInput = document.getElementById('photos-cover-photo-id')
        const coverBtn = document.getElementById('photos-cover-btn')
        const albumModal = document.getElementById('photos-album-modal')
        const installBtn = document.getElementById('photos-pwa-install')
        let currentIndex = 0
        let deferredPrompt = null

        function triggerUpload() {
          fileInput?.click()
        }

        document.querySelectorAll('.photos-upload-btn').forEach((btn) => {
          btn.addEventListener('click', (e) => {
            if (e.target === fileInput) return
            e.preventDefault()
            triggerUpload()
          })
        })

        fileInput?.addEventListener('change', () => {
          if (!fileInput.files?.length || !form || !formFiles) return
          const dt = new DataTransfer()
          Array.from(fileInput.files).forEach((f) => dt.items.add(f))
          formFiles.files = dt.files
          form.submit()
        })

        ;['dragenter', 'dragover'].forEach((type) => {
          emptyZone?.addEventListener(type, (e) => {
            e.preventDefault()
            emptyZone.classList.add('is-dragover')
          })
        })
        ;['dragleave', 'drop'].forEach((type) => {
          emptyZone?.addEventListener(type, (e) => {
            e.preventDefault()
            emptyZone.classList.remove('is-dragover')
          })
        })
        emptyZone?.addEventListener('drop', (e) => {
          const files = e.dataTransfer?.files
          if (!files?.length || !form || !formFiles) return
          const dt = new DataTransfer()
          Array.from(files).forEach((f) => {
            if (f.type.startsWith('image/') || /\.(heic|heif)$/i.test(f.name)) dt.items.add(f)
          })
          if (!dt.files.length) return
          formFiles.files = dt.files
          form.submit()
        })

        function openLightbox(index) {
          if (!photos.length || !lightbox) return
          currentIndex = (index + photos.length) % photos.length
          const photo = photos[currentIndex]
          lightboxImage.src = photo.url
          lightboxImage.alt = photo.caption || photo.originalName || '写真'
          lightboxCaption.textContent = photo.caption || photo.originalName || ''
          lightboxDate.textContent = photo.takenAt || ''
          deleteForm.action = `/photos/${photo.id}/delete`
          if (coverPhotoInput) coverPhotoInput.value = String(photo.id)
          if (coverBtn && selectedAlbumId) {
            const isCover = coverPhotoId && Number(coverPhotoId) === Number(photo.id)
            const inAlbum = Number(photo.albumId) === Number(selectedAlbumId)
            coverBtn.hidden = !inAlbum
            coverBtn.disabled = !!isCover
            coverBtn.textContent = isCover ? '表紙に設定済み' : '表紙にする'
          }
          lightbox.hidden = false
          document.body.style.overflow = 'hidden'
        }

        function closeLightbox() {
          if (!lightbox) return
          lightbox.hidden = true
          lightboxImage.src = ''
          document.body.style.overflow = ''
        }

        document.querySelectorAll('.photos-tile').forEach((tile) => {
          tile.addEventListener('click', () => openLightbox(Number(tile.dataset.photoIndex || 0)))
        })
        document.querySelectorAll('[data-close-lightbox]').forEach((el) => {
          el.addEventListener('click', closeLightbox)
        })
        document.getElementById('photos-lightbox-prev')?.addEventListener('click', () => openLightbox(currentIndex - 1))
        document.getElementById('photos-lightbox-next')?.addEventListener('click', () => openLightbox(currentIndex + 1))
        document.addEventListener('keydown', (e) => {
          if (lightbox?.hidden) return
          if (e.key === 'Escape') closeLightbox()
          if (e.key === 'ArrowLeft') openLightbox(currentIndex - 1)
          if (e.key === 'ArrowRight') openLightbox(currentIndex + 1)
        })

        document.getElementById('photos-album-open')?.addEventListener('click', () => {
          if (albumModal) albumModal.hidden = false
        })
        document.querySelectorAll('[data-close-album-modal]').forEach((el) => {
          el.addEventListener('click', () => {
            if (albumModal) albumModal.hidden = true
          })
        })

        if ('serviceWorker' in navigator) {
          navigator.serviceWorker.register(@json(asset('sw.js'))).catch(() => {})
        }

        window.addEventListener('beforeinstallprompt', (e) => {
          e.preventDefault()
          deferredPrompt = e
          if (installBtn) installBtn.hidden = false
        })

        installBtn?.addEventListener('click', async () => {
          if (!deferredPrompt) return
          deferredPrompt.prompt()
          await deferredPrompt.userChoice
          deferredPrompt = null
          installBtn.hidden = true
        })
      })()
    </script>
  </body>
</html>
