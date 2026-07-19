<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a1f24" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="Sa2 Photos" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <link rel="manifest" href="/manifest.webmanifest" />
    <link rel="apple-touch-icon" href="/icons/pwa-192.png" />
    <title>{{ __('Photos') }} - Sa2 ToDo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Outfit:wght@400;500;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="/app.css" />
  </head>
  <body class="photos-page">
    @include('partials.header', ['active' => 'photos'])
    <main class="page-main photos-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <section class="photos-hero">
        <div class="photos-hero-copy">
          <p class="photos-kicker">{{ __('Album') }}</p>
          <h1 class="photos-title">{{ $selectedAlbum['name'] ?? __('Photos') }}</h1>
          <p class="photos-lead">
            @if($selectedAlbum)
              {{ __(':count枚', ['count' => $selectedAlbum['photoCount']]) }} · {{ __('表紙を選んでアルバムらしく') }}
            @else
              {{ __('旅・日常・お気に入りを、見ていて気持ちよく残す場所。') }}
            @endif
          </p>
        </div>
        <div class="photos-hero-actions">
          <label class="photos-upload-btn">
            <input
              type="file"
              name="photos[]"
              id="photos-file-input"
              accept="image/*,video/mp4,.heic,.heif,.mp4"
              multiple
              hidden
            />
            <span class="photos-upload-btn-label">{{ __('写真・動画を追加') }}</span>
          </label>
          <button type="button" class="photos-secondary-btn" id="photos-album-open">{{ __('アルバム作成') }}</button>
          @if($selectedAlbum)
            <button type="button" class="photos-secondary-btn" id="photos-album-edit">{{ __('名前変更') }}</button>
            <form
              method="post"
              action="/photos/albums/{{ $selectedAlbumId }}/delete"
              class="photos-album-delete-form"
              onsubmit="return confirm({{ json_encode(__('このアルバムを削除しますか？') . "\n" . $selectedAlbum['name'] . "\n" . __('アルバム内の写真・動画もすべて削除されます。'), JSON_UNESCAPED_UNICODE) }})"
            >
              @csrf
              <input type="hidden" name="returnTo" value="/photos" />
              <button type="submit" class="photos-secondary-btn photos-danger-btn">{{ __('アルバム削除') }}</button>
            </form>
          @endif
        </div>
      </section>

      <aside class="photos-storage" aria-label="保存容量">
        <div class="photos-storage-head">
          <strong>{{ __('保存容量') }}</strong>
          <span>
            {{ $storageStats['formattedUsed'] }}
            / {{ __('無料枠') }} {{ $storageStats['formattedQuota'] }}
            {{ __('（:count枚）', ['count' => $storageStats['photoCount']]) }}
            @if(!empty($storageStats['overFreeTier']))
              <em class="photos-storage-over">{{ __('無料枠超過') }}</em>
            @endif
          </span>
        </div>
        <div class="photos-storage-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) min(100, $storageStats['percent']) }}">
          <span class="photos-storage-bar-fill{{ ($storageStats['percent'] >= 90 || !empty($storageStats['overFreeTier'])) ? ' is-warn' : '' }}" style="width: {{ min(100, $storageStats['percent']) }}%"></span>
        </div>
        <p class="photos-storage-note">
          {{ __('画像は長辺1920pxへ自動圧縮。動画は MP4（最大') }} {{ number_format((int) config('photos.max_video_upload_bytes') / 1048576) }}MB）{{ __('に対応。') }}
          {{ __('保存先:') }} {{ $storageStats['diskLabel'] }}。
          {{ __('Cloudflare R2 の無料枠は') }} {{ $storageStats['formattedQuota'] }}{{ __('（超過分は約') }} {{ $storageStats['overagePriceLabel'] }} {{ __('の従量課金）。') }}
          @if(($storageStats['disk'] ?? '') === 'public')
            {{ __('（本番では PHOTO_DISK=r2 で Cloudflare R2 に切り替え可能）') }}
          @endif
        </p>
      </aside>

      <aside class="photos-sync-tip" aria-label="スマホからの追加・PWA">
        <div class="photos-sync-tip-copy">
          <strong>{{ __('スマホ同期 / アプリ化') }}</strong>
          <span>{{ __('このページをスマホで開き「写真・動画を追加」。下のボタンからホーム画面に追加できます（iPhone は案内を表示）。動画は MP4 のみです。') }}</span>
        </div>
        <button type="button" class="photos-secondary-btn photos-pwa-tip-btn" id="photos-pwa-install">{{ __('ホーム画面に追加') }}</button>
      </aside>

      <section class="photos-album-covers" aria-label="アルバム">
        <a href="/photos" @class(['photos-cover-card', 'is-all', 'is-active' => !$selectedAlbumId])>
          <span class="photos-cover-all-label">{{ __('すべて') }}</span>
          <span class="photos-cover-meta">{{ count($photos) }}{{ __('枚を表示中') }}</span>
        </a>
        @foreach($albums as $album)
          <a
            href="/photos?album={{ $album['id'] }}"
            @class(['photos-cover-card', 'is-active' => $selectedAlbumId === $album['id']])
          >
            @if(!empty($album['coverUrl']))
              @if(($album['coverMediaKind'] ?? '') === 'video')
                <video
                  class="photos-cover-image photos-cover-video"
                  src="{{ $album['coverUrl'] }}#t=0.1"
                  muted
                  playsinline
                  preload="metadata"
                  aria-hidden="true"
                ></video>
              @else
                <img src="{{ $album['coverUrl'] }}" alt="" class="photos-cover-image" loading="lazy" />
              @endif
            @else
              <span class="photos-cover-placeholder" aria-hidden="true"></span>
            @endif
            <span class="photos-cover-shade"></span>
            <span class="photos-cover-text">
              <strong>{{ $album['name'] }}</strong>
              <span>{{ __(':count枚', ['count' => $album['photoCount']]) }}</span>
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
        <input type="file" name="photos[]" id="photos-form-files" accept="image/*,video/mp4,.heic,.heif,.mp4" multiple hidden />
        <input type="file" name="video_thumbs[]" id="photos-form-thumbs" accept="image/jpeg" multiple hidden />
        <input type="hidden" name="video_thumb_for" id="photos-form-thumb-for" value="" />
      </form>

      @if(count($photos) === 0)
        <div class="photos-empty" id="photos-dropzone">
          <div class="photos-empty-frame">
            <p class="photos-empty-title">{{ __('まだメディアがありません') }}</p>
            <p class="photos-empty-text">{{ __('写真または MP4 動画を追加できます。') }}<br />{{ __('ドラッグ＆ドロップ、またはボタンから。') }}</p>
            <label class="photos-upload-btn photos-upload-btn-large">
              <span class="photos-upload-btn-label">{{ __('最初の一枚を入れる') }}</span>
            </label>
          </div>
        </div>
      @else
        <div class="photos-toolbar" role="toolbar" aria-label="{{ __('表示モード') }}">
          <div class="photos-mode-toggle" role="group" aria-label="{{ __('表示モード') }}">
            <button type="button" class="photos-mode-btn is-active" data-photos-mode="normal" aria-pressed="true">{{ __('通常') }}</button>
            <button type="button" class="photos-mode-btn" data-photos-mode="select" aria-pressed="false">{{ __('選択') }}</button>
            <button type="button" class="photos-mode-btn" data-photos-mode="list" aria-pressed="false">{{ __('一覧') }}</button>
          </div>
          <div class="photos-bulk-bar" id="photos-bulk-bar" hidden>
            <span class="photos-bulk-count" id="photos-bulk-count">0{{ __('件選択') }}</span>
            <button type="button" class="photos-secondary-btn photos-danger-btn" id="photos-bulk-delete">{{ __('一括削除') }}</button>
            <label class="photos-bulk-move" id="photos-bulk-move-wrap" hidden>
              <span>{{ __('アルバムへ移動') }}</span>
              <select id="photos-bulk-move-album">
                <option value="">{{ __('アルバムなし') }}</option>
                @foreach($albums as $album)
                  <option value="{{ $album['id'] }}">{{ $album['name'] }}</option>
                @endforeach
              </select>
              <button type="button" class="photos-secondary-btn" id="photos-bulk-move">{{ __('移動') }}</button>
            </label>
          </div>
        </div>

        <div class="photos-timeline" id="photos-gallery" data-photos-mode="normal">
          @php $flatIndex = 0; @endphp
          @foreach($photoGroups as $group)
            <section class="photos-day-group">
              <header class="photos-day-header">
                <h2 class="photos-day-label">{{ $group['label'] }}</h2>
                <span class="photos-day-count">{{ __(':count枚', ['count' => count($group['photos'])]) }}</span>
              </header>
              <div class="photos-masonry">
                @foreach($group['photos'] as $photo)
                  <div
                    @class(['photos-tile-wrap', 'is-video' => ($photo['mediaKind'] ?? '') === 'video'])
                    data-photo-id="{{ $photo['id'] }}"
                    data-photo-index="{{ $flatIndex }}"
                  >
                    <label class="photos-tile-check">
                      <input type="checkbox" class="photo-check" value="{{ $photo['id'] }}" aria-label="{{ __('選択') }}" />
                    </label>
                    <button
                      type="button"
                      @class(['photos-tile', 'is-video' => ($photo['mediaKind'] ?? '') === 'video'])
                      style="--photo-ratio: {{ max(0.66, min(1.45, ($photo['height'] && $photo['width']) ? ($photo['height'] / $photo['width']) : (($photo['mediaKind'] ?? '') === 'video' ? 0.75 : 1))) }}"
                      data-photo-index="{{ $flatIndex }}"
                      aria-label="{{ $photo['caption'] ?: ($photo['originalName'] ?: ((($photo['mediaKind'] ?? '') === 'video') ? __('動画を再生') : __('写真を表示'))) }}"
                    >
                      @if(($photo['mediaKind'] ?? '') === 'video')
                        <video
                          class="photos-tile-video"
                          src="{{ $photo['url'] }}#t=0.1"
                          muted
                          playsinline
                          preload="metadata"
                          aria-hidden="true"
                        ></video>
                        <span class="photos-play-badge" aria-hidden="true">▶</span>
                      @else
                        <img
                          src="{{ $photo['thumbUrl'] }}"
                          alt="{{ $photo['caption'] ?: ($photo['originalName'] ?: __('メディア')) }}"
                          loading="lazy"
                          decoding="async"
                        />
                      @endif
                      <span class="photos-tile-glow" aria-hidden="true"></span>
                    </button>
                    <div class="photos-list-meta">
                      <strong>{{ $photo['caption'] ?: ($photo['originalName'] ?: __('メディア')) }}</strong>
                      <span>{{ $photo['takenAt'] ?? '' }}</span>
                    </div>
                  </div>
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
        <button type="button" class="photos-lightbox-nav is-prev" id="photos-lightbox-prev" aria-label="前へ">‹</button>
        <div class="photos-lightbox-media" id="photos-lightbox-media">
          <img src="" alt="" id="photos-lightbox-image" />
          <video src="" id="photos-lightbox-video" controls playsinline preload="metadata" hidden></video>
        </div>
        <button type="button" class="photos-lightbox-nav is-next" id="photos-lightbox-next" aria-label="次へ">›</button>
        <div class="photos-lightbox-zoom" role="group" aria-label="{{ __('拡大') }}">
          <button type="button" id="photos-zoom-out" aria-label="{{ __('縮小') }}">−</button>
          <button type="button" id="photos-zoom-reset" aria-label="{{ __('リセット') }}">100%</button>
          <button type="button" id="photos-zoom-in" aria-label="{{ __('拡大') }}">＋</button>
        </div>
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
                <button type="submit" class="photos-cover-btn" id="photos-cover-btn">{{ __('表紙にする') }}</button>
              </form>
            @endif
            <form method="post" action="" id="photos-delete-form" onsubmit='return confirm(@json(__('このメディアを削除しますか？')))'>
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <button type="submit" class="photos-delete-btn">{{ __('削除') }}</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="modal modal-centered" id="photos-album-modal" hidden>
      <div class="modal-backdrop" data-close-album-modal></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="photos-album-modal-title">
        <div class="modal-header">
          <h2 id="photos-album-modal-title">{{ __('アルバムを作成') }}</h2>
          <button type="button" class="modal-close" data-close-album-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" action="/photos/albums" class="modal-form" id="photos-album-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" id="photos-album-return-to" />
          <label>
            {{ __('アルバム名') }}
            <input type="text" name="name" id="photos-album-name" required maxlength="120" placeholder="{{ __('例: 旅行 2026') }}" autocomplete="off" />
          </label>
          <label>
            {{ __('説明（任意）') }}
            <input type="text" name="description" id="photos-album-description" maxlength="500" placeholder="{{ __('短いメモ') }}" autocomplete="off" />
          </label>
          <div class="modal-actions">
            <button type="button" class="secondary" data-close-album-modal>{{ __('キャンセル') }}</button>
            <button type="submit" id="photos-album-submit">{{ __('作成') }}</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal modal-centered" id="photos-pwa-guide-modal" hidden>
      <div class="modal-backdrop" data-close-pwa-guide></div>
      <div class="modal-dialog" role="dialog" aria-labelledby="photos-pwa-guide-title">
        <div class="modal-header">
          <h2 id="photos-pwa-guide-title">{{ __('ホーム画面に追加') }}</h2>
          <button type="button" class="modal-close" data-close-pwa-guide aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <div class="photos-pwa-guide-body" id="photos-pwa-guide-body">
          <p class="photos-pwa-guide-lead" id="photos-pwa-guide-lead"></p>
          <ol class="photos-pwa-guide-steps" id="photos-pwa-guide-steps"></ol>
          <p class="hint" id="photos-pwa-guide-note"></p>
        </div>
        <div class="modal-actions">
          <button type="button" class="button-link" data-close-pwa-guide>{{ __('閉じる') }}</button>
        </div>
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
        const formThumbs = document.getElementById('photos-form-thumbs')
        const formThumbFor = document.getElementById('photos-form-thumb-for')
        const emptyZone = document.getElementById('photos-dropzone')
        const lightbox = document.getElementById('photos-lightbox')
        const lightboxImage = document.getElementById('photos-lightbox-image')
        const lightboxVideo = document.getElementById('photos-lightbox-video')
        const lightboxCaption = document.getElementById('photos-lightbox-caption')
        const lightboxDate = document.getElementById('photos-lightbox-date')
        const deleteForm = document.getElementById('photos-delete-form')
        const coverForm = document.getElementById('photos-cover-form')
        const coverPhotoInput = document.getElementById('photos-cover-photo-id')
        const coverBtn = document.getElementById('photos-cover-btn')
        const albumModal = document.getElementById('photos-album-modal')
        const installBtn = document.getElementById('photos-pwa-install')
        const uploadLabel = document.querySelector('.photos-hero-actions .photos-upload-btn-label')
        let currentIndex = 0
        let deferredPrompt = null
        let uploading = false

        function isVideoFile(file) {
          return !!file && (file.type.startsWith('video/') || /\.mp4$/i.test(file.name || ''))
        }

        async function captureVideoThumb(file) {
          if (!file || typeof document === 'undefined') return null
          return new Promise((resolve) => {
            const url = URL.createObjectURL(file)
            const video = document.createElement('video')
            video.muted = true
            video.playsInline = true
            video.preload = 'auto'
            let settled = false
            const finish = (value) => {
              if (settled) return
              settled = true
              clearTimeout(timer)
              URL.revokeObjectURL(url)
              resolve(value)
            }
            const timer = setTimeout(() => finish(null), 10000)
            video.addEventListener('loadeddata', () => {
              try {
                const t = Number.isFinite(video.duration) && video.duration > 0
                  ? Math.min(1, Math.max(0.1, video.duration * 0.05))
                  : 0.1
                video.currentTime = t
              } catch (_) {
                finish(null)
              }
            })
            video.addEventListener('seeked', () => {
              try {
                if (!video.videoWidth || !video.videoHeight) {
                  finish(null)
                  return
                }
                const maxEdge = 720
                const scale = Math.min(1, maxEdge / Math.max(video.videoWidth, video.videoHeight))
                const w = Math.max(1, Math.round(video.videoWidth * scale))
                const h = Math.max(1, Math.round(video.videoHeight * scale))
                const canvas = document.createElement('canvas')
                canvas.width = w
                canvas.height = h
                const ctx = canvas.getContext('2d')
                if (!ctx) {
                  finish(null)
                  return
                }
                ctx.drawImage(video, 0, 0, w, h)
                canvas.toBlob((blob) => {
                  if (!blob) {
                    finish(null)
                    return
                  }
                  const name = (file.name || 'video').replace(/\.\w+$/, '') + '_thumb.jpg'
                  finish(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }))
                }, 'image/jpeg', 0.82)
              } catch (_) {
                finish(null)
              }
            })
            video.addEventListener('error', () => finish(null))
            video.src = url
            video.load()
          })
        }

        async function compressImageFile(file) {
          if (!file) return file
          if (isVideoFile(file)) return file
          if (!file.type.startsWith('image/')) return file
          if (file.type === 'image/gif' || /heic|heif/i.test(file.type) || /\.(heic|heif)$/i.test(file.name)) {
            return file
          }
          if (typeof createImageBitmap !== 'function') return file

          const maxEdge = 1920
          const quality = 0.82
          let bitmap
          try {
            bitmap = await createImageBitmap(file)
          } catch (_) {
            return file
          }
          const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height))
          const w = Math.max(1, Math.round(bitmap.width * scale))
          const h = Math.max(1, Math.round(bitmap.height * scale))
          const canvas = document.createElement('canvas')
          canvas.width = w
          canvas.height = h
          const ctx = canvas.getContext('2d')
          if (!ctx) {
            bitmap.close?.()
            return file
          }
          ctx.drawImage(bitmap, 0, 0, w, h)
          bitmap.close?.()

          const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality))
          if (!blob || blob.size >= file.size) return file
          const name = file.name.replace(/\.\w+$/, '') + '.jpg'
          return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() })
        }

        async function submitFiles(fileList) {
          if (!fileList?.length || !form || !formFiles || uploading) return
          uploading = true
          const list = Array.from(fileList)
          const hasVideo = list.some(isVideoFile)
          if (uploadLabel) uploadLabel.textContent = hasVideo ? 'サムネ作成中…' : '圧縮中…'
          try {
            const dt = new DataTransfer()
            const thumbDt = new DataTransfer()
            const thumbFor = []
            for (let i = 0; i < list.length; i++) {
              const file = list[i]
              dt.items.add(await compressImageFile(file))
              if (isVideoFile(file)) {
                const thumb = await captureVideoThumb(file)
                if (thumb) {
                  thumbDt.items.add(thumb)
                  thumbFor.push(String(i))
                }
              }
            }
            if (!dt.files.length) return
            formFiles.files = dt.files
            if (formThumbs) formThumbs.files = thumbDt.files
            if (formThumbFor) formThumbFor.value = thumbFor.join(',')
            if (uploadLabel) uploadLabel.textContent = '送信中…'
            form.submit()
          } catch (_) {
            uploading = false
            if (uploadLabel) uploadLabel.textContent = @json(__('写真・動画を追加'));
            window.alert(@json(__('ファイルの処理に失敗しました。別の形式で試してください。')));
          }
        }

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
          if (!fileInput.files?.length) return
          submitFiles(fileInput.files)
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
          if (!files?.length) return
          const filtered = Array.from(files).filter((f) =>
            f.type.startsWith('image/') ||
            f.type === 'video/mp4' ||
            /\.(heic|heif|mp4)$/i.test(f.name)
          )
          submitFiles(filtered)
        })

        function stopLightboxVideo() {
          if (!lightboxVideo) return
          lightboxVideo.pause()
          lightboxVideo.removeAttribute('src')
          lightboxVideo.load()
          lightboxVideo.hidden = true
        }

        function openLightbox(index) {
          if (!photos.length || !lightbox) return
          currentIndex = (index + photos.length) % photos.length
          const photo = photos[currentIndex]
          const isVideo = photo.mediaKind === 'video'
          stopLightboxVideo()
          if (isVideo) {
            lightboxImage.hidden = true
            lightboxImage.removeAttribute('src')
            if (lightboxVideo) {
              lightboxVideo.hidden = false
              lightboxVideo.src = photo.url
            }
          } else {
            if (lightboxImage) {
              lightboxImage.hidden = false
              lightboxImage.src = photo.url
              lightboxImage.alt = photo.caption || photo.originalName || '写真'
            }
          }
          lightboxCaption.textContent = photo.caption || photo.originalName || ''
          lightboxDate.textContent = (isVideo ? '動画 · ' : '') + (photo.takenAt || '')
          deleteForm.action = `/photos/${photo.id}/delete`
          if (coverPhotoInput) coverPhotoInput.value = String(photo.id)
          if (coverBtn && selectedAlbumId) {
            const isCover = coverPhotoId && Number(coverPhotoId) === Number(photo.id)
            const inAlbum = Number(photo.albumId) === Number(selectedAlbumId)
            coverBtn.hidden = !inAlbum
            coverBtn.disabled = !!isCover
            coverBtn.textContent = isCover ? @json(__('表紙に設定済み')) : @json(__('表紙にする'));
          }
          lightbox.hidden = false
          document.body.style.overflow = 'hidden'
          setLightboxZoom(1)
        }

        function closeLightbox() {
          if (!lightbox) return
          stopLightboxVideo()
          lightbox.hidden = true
          if (lightboxImage) lightboxImage.src = ''
          document.body.style.overflow = ''
          setLightboxZoom(1)
        }

        let lightboxZoom = 1
        const lightboxMedia = document.getElementById('photos-lightbox-media')
        const zoomResetBtn = document.getElementById('photos-zoom-reset')
        function setLightboxZoom(next) {
          lightboxZoom = Math.max(1, Math.min(4, Number(next) || 1))
          if (lightboxMedia) lightboxMedia.style.transform = `scale(${lightboxZoom})`
          if (zoomResetBtn) zoomResetBtn.textContent = `${Math.round(lightboxZoom * 100)}%`
        }
        document.getElementById('photos-zoom-in')?.addEventListener('click', () => setLightboxZoom(lightboxZoom + 0.25))
        document.getElementById('photos-zoom-out')?.addEventListener('click', () => setLightboxZoom(lightboxZoom - 0.25))
        zoomResetBtn?.addEventListener('click', () => setLightboxZoom(1))
        lightboxMedia?.addEventListener('wheel', (e) => {
          if (lightbox?.hidden) return
          e.preventDefault()
          setLightboxZoom(lightboxZoom + (e.deltaY < 0 ? 0.15 : -0.15))
        }, { passive: false })

        const gallery = document.getElementById('photos-gallery')
        const bulkBar = document.getElementById('photos-bulk-bar')
        const bulkCount = document.getElementById('photos-bulk-count')
        const bulkMoveWrap = document.getElementById('photos-bulk-move-wrap')
        const PHOTOS_MODE_KEY = 'photos-view-mode'
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || ''
        const photosReturnTo = @json($returnTo);

        function photoChecks() {
          return Array.from(document.querySelectorAll('.photo-check'))
        }
        function selectedPhotoIds() {
          return photoChecks().filter((cb) => cb.checked).map((cb) => cb.value)
        }
        function currentPhotosMode() {
          return gallery?.dataset.photosMode || 'normal'
        }
        function updatePhotosBulkUi() {
          const mode = currentPhotosMode()
          const ids = selectedPhotoIds()
          if (bulkCount) bulkCount.textContent = @json(__(':count件選択')).replace(':count', String(ids.length));
          if (bulkBar) bulkBar.hidden = mode === 'normal' || ids.length === 0
          if (bulkMoveWrap) bulkMoveWrap.hidden = mode !== 'list'
        }
        function setPhotosMode(mode) {
          const next = ['normal', 'select', 'list'].includes(mode) ? mode : 'normal'
          if (gallery) gallery.dataset.photosMode = next
          document.querySelectorAll('[data-photos-mode]').forEach((btn) => {
            const active = btn.dataset.photosMode === next
            btn.classList.toggle('is-active', active)
            btn.setAttribute('aria-pressed', active ? 'true' : 'false')
          })
          if (next === 'normal') {
            photoChecks().forEach((cb) => { cb.checked = false })
          }
          try { localStorage.setItem(PHOTOS_MODE_KEY, next) } catch (_) {}
          updatePhotosBulkUi()
        }
        document.querySelectorAll('[data-photos-mode]').forEach((btn) => {
          btn.addEventListener('click', () => setPhotosMode(btn.dataset.photosMode))
        })
        try {
          const savedMode = localStorage.getItem(PHOTOS_MODE_KEY)
          setPhotosMode(savedMode || 'normal')
        } catch (_) {
          setPhotosMode('normal')
        }
        photoChecks().forEach((cb) => cb.addEventListener('change', updatePhotosBulkUi))

        function submitPhotosBulk(url, extra = {}) {
          const ids = selectedPhotoIds()
          if (ids.length === 0) {
            window.alert(@json(__('対象が選択されていません')));
            return
          }
          const form = document.createElement('form')
          form.method = 'POST'
          form.action = url
          form.style.display = 'none'
          const token = document.createElement('input')
          token.type = 'hidden'
          token.name = '_token'
          token.value = csrfToken
          form.appendChild(token)
          const returnTo = document.createElement('input')
          returnTo.type = 'hidden'
          returnTo.name = 'returnTo'
          returnTo.value = photosReturnTo || '/photos'
          form.appendChild(returnTo)
          ids.forEach((id) => {
            const input = document.createElement('input')
            input.type = 'hidden'
            input.name = 'ids[]'
            input.value = id
            form.appendChild(input)
          })
          Object.entries(extra).forEach(([name, value]) => {
            const input = document.createElement('input')
            input.type = 'hidden'
            input.name = name
            input.value = value
            form.appendChild(input)
          })
          document.body.appendChild(form)
          form.submit()
        }
        document.getElementById('photos-bulk-delete')?.addEventListener('click', () => {
          if (!window.confirm(@json(__('選択したメディアを削除しますか？')))) return
          submitPhotosBulk('/photos/bulk/delete')
        })
        document.getElementById('photos-bulk-move')?.addEventListener('click', () => {
          const albumId = document.getElementById('photos-bulk-move-album')?.value ?? ''
          submitPhotosBulk('/photos/bulk/move', { album_id: albumId })
        })

        document.querySelectorAll('.photos-tile').forEach((tile) => {
          tile.addEventListener('click', (e) => {
            const mode = currentPhotosMode()
            if (mode === 'select' || mode === 'list') {
              e.preventDefault()
              const wrap = tile.closest('.photos-tile-wrap')
              const check = wrap?.querySelector('.photo-check')
              if (check) {
                check.checked = !check.checked
                updatePhotosBulkUi()
              }
              return
            }
            openLightbox(Number(tile.dataset.photoIndex || 0))
          })
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
          if (e.key === '+' || e.key === '=') setLightboxZoom(lightboxZoom + 0.25)
          if (e.key === '-') setLightboxZoom(lightboxZoom - 0.25)
        })

        const albumForm = document.getElementById('photos-album-form')
        const albumModalTitle = document.getElementById('photos-album-modal-title')
        const albumNameInput = document.getElementById('photos-album-name')
        const albumDescInput = document.getElementById('photos-album-description')
        const albumSubmit = document.getElementById('photos-album-submit')
        const selectedAlbum = @json($selectedAlbum);

        function openAlbumModal(mode) {
          if (!albumModal || !albumForm) return
          if (mode === 'edit' && selectedAlbum) {
            albumModalTitle.textContent = 'アルバム名を変更'
            albumForm.action = `/photos/albums/${selectedAlbum.id}/update`
            albumNameInput.value = selectedAlbum.name || ''
            albumDescInput.value = selectedAlbum.description || ''
            albumSubmit.textContent = '保存'
          } else {
            albumModalTitle.textContent = 'アルバムを作成'
            albumForm.action = '/photos/albums'
            albumNameInput.value = ''
            albumDescInput.value = ''
            albumSubmit.textContent = '作成'
          }
          albumModal.hidden = false
          albumNameInput.focus()
        }

        document.getElementById('photos-album-open')?.addEventListener('click', () => openAlbumModal('create'))
        document.getElementById('photos-album-edit')?.addEventListener('click', () => openAlbumModal('edit'))
        document.querySelectorAll('[data-close-album-modal]').forEach((el) => {
          el.addEventListener('click', () => {
            if (albumModal) albumModal.hidden = true
          })
        })

        document.querySelectorAll('.photos-tile-video, .photos-cover-video').forEach((video) => {
          const showFrame = () => {
            try {
              if (video.readyState >= 1 && Number.isFinite(video.duration) && video.duration > 0) {
                video.currentTime = Math.min(0.25, video.duration * 0.05)
              } else if (video.readyState >= 2) {
                video.currentTime = 0.1
              }
            } catch (_) {}
          }
          video.addEventListener('loadedmetadata', showFrame)
          video.addEventListener('loadeddata', showFrame)
        })

        if ('serviceWorker' in navigator) {
          navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then((reg) => reg.update().catch(() => {}))
            .catch((err) => {
              console.warn('Photos SW registration failed', err)
            })
        }

        const pwaGuideModal = document.getElementById('photos-pwa-guide-modal')
        const pwaGuideLead = document.getElementById('photos-pwa-guide-lead')
        const pwaGuideSteps = document.getElementById('photos-pwa-guide-steps')
        const pwaGuideNote = document.getElementById('photos-pwa-guide-note')
        const installButtons = [installBtn].filter(Boolean)

        function isPhotosStandalone() {
          return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true
        }

        function isIosDevice() {
          return /iphone|ipad|ipod/i.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
        }

        function isChromeBrowser() {
          const ua = navigator.userAgent
          return /Chrome|CriOS|Edg\//i.test(ua) && !/OPR\//i.test(ua)
        }

        function isSecureForPwa() {
          return window.isSecureContext === true
        }

        function hideInstallButtons() {
          installButtons.forEach((btn) => {
            btn.hidden = true
          })
        }

        function showPwaGuide(extraNote = '') {
          const ios = isIosDevice()
          const secure = isSecureForPwa()
          const chrome = isChromeBrowser()
          if (pwaGuideLead) {
            if (!secure) {
              pwaGuideLead.textContent = 'Chrome のワンタップ追加には HTTPS または localhost が必要です。'
            } else if (ios) {
              pwaGuideLead.textContent = 'iPhone / iPad では、Safari の共有メニューから追加します。'
            } else if (chrome) {
              pwaGuideLead.textContent = '自動プロンプトがまだ来ていません。次の手順で追加できます。'
            } else {
              pwaGuideLead.textContent = 'ブラウザのメニューからホーム画面に追加してください。'
            }
          }
          if (pwaGuideSteps) {
            let steps
            if (!secure && chrome) {
              steps = [
                'PC なら http://localhost:8000/photos で開き直す',
                'スマホなら PC と同じ Wi‑Fi でも HTTP のままでは Chrome 追加不可（localhost / HTTPS が必要）',
                'アドレスバー右のインストールアイコン、またはメニュー「アプリをインストール」を選ぶ',
              ]
            } else if (ios) {
              steps = [
                'Safari でこのページを開く',
                '共有ボタン（□と↑）をタップ',
                '「ホーム画面に追加」→「追加」',
              ]
            } else if (chrome) {
              steps = [
                'ページを再読み込みする（Service Worker 更新）',
                'アドレスバー右の「インストール」アイコンを押す',
                'またはメニュー（⋮）→「アプリをインストール」 / 「Cast, save, and share」→「Install page as app」',
              ]
            } else {
              steps = [
                'ブラウザのメニューを開く',
                '「アプリをインストール」または「ホーム画面に追加」を選ぶ',
                '確認画面で追加する',
              ]
            }
            pwaGuideSteps.innerHTML = steps.map((step) => `<li>${step}</li>`).join('')
          }
          if (pwaGuideNote) {
            const notes = []
            notes.push(`いまのURL: ${location.origin}`)
            if (!secure) {
              notes.push('この接続は安全なコンテキストではありません（HTTP の LAN IP など）。')
            }
            if (extraNote) notes.push(extraNote)
            pwaGuideNote.textContent = notes.join(' ')
          }
          pwaGuideModal?.removeAttribute('hidden')
        }

        function closePwaGuide() {
          pwaGuideModal?.setAttribute('hidden', '')
        }

        async function waitForInstallPrompt(ms = 1500) {
          if (deferredPrompt) return deferredPrompt
          if (!('serviceWorker' in navigator)) return null
          try {
            await navigator.serviceWorker.ready
          } catch (_) {}
          const started = Date.now()
          while (!deferredPrompt && Date.now() - started < ms) {
            await new Promise((resolve) => setTimeout(resolve, 150))
          }
          return deferredPrompt
        }

        async function handleInstallClick() {
          if (isPhotosStandalone()) {
            window.alert(@json(__('すでにホーム画面アプリとして開いています。')));
            return
          }
          const promptEvent = deferredPrompt || await waitForInstallPrompt()
          if (promptEvent) {
            promptEvent.prompt()
            await promptEvent.userChoice
            deferredPrompt = null
            hideInstallButtons()
            return
          }
          let extra = ''
          if (!isSecureForPwa()) {
            extra = 'Chrome では localhost か HTTPS で開き直してください。'
          } else if ('serviceWorker' in navigator) {
            const ready = await navigator.serviceWorker.getRegistration('/')
            if (!ready) extra = 'Service Worker 未登録です。再読み込み後にもう一度試してください。'
          }
          showPwaGuide(extra)
        }

        if (isPhotosStandalone()) {
          hideInstallButtons()
        }

        window.addEventListener('beforeinstallprompt', (e) => {
          e.preventDefault()
          deferredPrompt = e
          installButtons.forEach((btn) => {
            btn.hidden = false
          })
        })

        window.addEventListener('appinstalled', () => {
          deferredPrompt = null
          hideInstallButtons()
          closePwaGuide()
        })

        installButtons.forEach((btn) => {
          btn.addEventListener('click', () => {
            handleInstallClick().catch(() => showPwaGuide())
          })
        })

        document.querySelectorAll('[data-close-pwa-guide]').forEach((el) => {
          el.addEventListener('click', closePwaGuide)
        })
      })()
    </script>
  </body>
</html>
