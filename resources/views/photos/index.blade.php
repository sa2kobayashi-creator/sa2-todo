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
              accept="image/*,video/mp4,.heic,.heif,.mp4"
              multiple
              hidden
            />
            <span class="photos-upload-btn-label">写真・動画を追加</span>
          </label>
          <button type="button" class="photos-secondary-btn" id="photos-album-open">アルバム作成</button>
          @if($selectedAlbum)
            <button type="button" class="photos-secondary-btn" id="photos-album-edit">名前変更</button>
            <form
              method="post"
              action="/photos/albums/{{ $selectedAlbumId }}/delete"
              class="photos-album-delete-form"
              onsubmit="return confirm({{ json_encode('「'.$selectedAlbum['name'].'」を削除しますか？'."\n".'アルバム内の写真・動画もすべて削除されます。', JSON_UNESCAPED_UNICODE) }})"
            >
              @csrf
              <input type="hidden" name="returnTo" value="/photos" />
              <button type="submit" class="photos-secondary-btn photos-danger-btn">アルバム削除</button>
            </form>
          @endif
          <button type="button" class="photos-secondary-btn" id="photos-pwa-install" hidden>ホーム画面に追加</button>
        </div>
      </section>

      <aside class="photos-storage" aria-label="保存容量">
        <div class="photos-storage-head">
          <strong>保存容量</strong>
          <span>{{ $storageStats['formattedUsed'] }} / {{ $storageStats['formattedQuota'] }}（{{ $storageStats['photoCount'] }}枚）</span>
        </div>
        <div class="photos-storage-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $storageStats['percent'] }}">
          <span class="photos-storage-bar-fill{{ $storageStats['percent'] >= 90 ? ' is-warn' : '' }}" style="width: {{ min(100, $storageStats['percent']) }}%"></span>
        </div>
        <p class="photos-storage-note">
          画像は長辺1920pxへ自動圧縮。動画は MP4（最大 {{ number_format((int) config('photos.max_video_upload_bytes') / 1048576) }}MB）に対応。
          保存先: {{ $storageStats['diskLabel'] }}
          @if(($storageStats['disk'] ?? '') === 'public')
            （本番では PHOTO_DISK=r2 で Cloudflare R2 に切り替え可能）
          @endif
        </p>
      </aside>

      <aside class="photos-sync-tip" aria-label="スマホからの追加・PWA">
        <strong>スマホ同期 / アプリ化</strong>
        <span>スマホブラウザでこのページを開き「写真・動画を追加」。対応端末では「ホーム画面に追加」でアプリのように使えます。動画は MP4 のみです。</span>
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
        <input type="file" name="photos[]" id="photos-form-files" accept="image/*,video/mp4,.heic,.heif,.mp4" multiple hidden />
        <input type="file" name="video_thumbs[]" id="photos-form-thumbs" accept="image/jpeg" multiple hidden />
        <input type="hidden" name="video_thumb_for" id="photos-form-thumb-for" value="" />
      </form>

      @if(count($photos) === 0)
        <div class="photos-empty" id="photos-dropzone">
          <div class="photos-empty-frame">
            <p class="photos-empty-title">まだメディアがありません</p>
            <p class="photos-empty-text">写真または MP4 動画を追加できます。<br />ドラッグ＆ドロップ、またはボタンから。</p>
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
                    @class(['photos-tile', 'is-video' => ($photo['mediaKind'] ?? '') === 'video'])
                    style="--photo-ratio: {{ max(0.66, min(1.45, ($photo['height'] && $photo['width']) ? ($photo['height'] / $photo['width']) : (($photo['mediaKind'] ?? '') === 'video' ? 0.75 : 1))) }}"
                    data-photo-index="{{ $flatIndex }}"
                    aria-label="{{ $photo['caption'] ?: ($photo['originalName'] ?: ((($photo['mediaKind'] ?? '') === 'video') ? '動画を再生' : '写真を表示')) }}"
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
                        alt="{{ $photo['caption'] ?: ($photo['originalName'] ?: 'メディア') }}"
                        loading="lazy"
                        decoding="async"
                      />
                    @endif
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
        <button type="button" class="photos-lightbox-nav is-prev" id="photos-lightbox-prev" aria-label="前へ">‹</button>
        <img src="" alt="" id="photos-lightbox-image" />
        <video src="" id="photos-lightbox-video" controls playsinline preload="metadata" hidden></video>
        <button type="button" class="photos-lightbox-nav is-next" id="photos-lightbox-next" aria-label="次へ">›</button>
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
            <form method="post" action="" id="photos-delete-form" onsubmit="return confirm('このメディアを削除しますか？')">
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
        <form method="post" action="/photos/albums" class="modal-form" id="photos-album-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" id="photos-album-return-to" />
          <label>
            アルバム名
            <input type="text" name="name" id="photos-album-name" required maxlength="120" placeholder="例: 旅行 2026" autocomplete="off" />
          </label>
          <label>
            説明（任意）
            <input type="text" name="description" id="photos-album-description" maxlength="500" placeholder="短いメモ" autocomplete="off" />
          </label>
          <div class="modal-actions">
            <button type="button" class="secondary" data-close-album-modal>キャンセル</button>
            <button type="submit" id="photos-album-submit">作成</button>
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
            if (uploadLabel) uploadLabel.textContent = '写真・動画を追加'
            window.alert('ファイルの処理に失敗しました。別の形式で試してください。')
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
            coverBtn.textContent = isCover ? '表紙に設定済み' : '表紙にする'
          }
          lightbox.hidden = false
          document.body.style.overflow = 'hidden'
        }

        function closeLightbox() {
          if (!lightbox) return
          stopLightboxVideo()
          lightbox.hidden = true
          if (lightboxImage) lightboxImage.src = ''
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
