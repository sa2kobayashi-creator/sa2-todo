<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a1f24" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Sa2 Studio') }}" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <link rel="manifest" href="/manifest.webmanifest" />
    <link rel="apple-touch-icon" href="/icons/pwa-192.png" />
    <title>{{ __('Photos') }} - {{ config('app.name') }}</title>
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
          @if(empty($selectedAlbum) || !empty($canManageSelected))
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
            <label class="photos-dup-option" title="{{ __('同じ内容のファイルを再度追加する') }}">
              <input type="checkbox" id="photos-allow-duplicates" />
              <span>{{ __('重複も追加') }}</span>
            </label>
            <button type="button" class="photos-secondary-btn" id="photos-dup-scan-open">{{ __('重複チェック') }}</button>
          @endif
          <button type="button" class="photos-secondary-btn" id="photos-album-open">{{ __('アルバム作成') }}</button>
          @if($selectedAlbum && !empty($canManageSelected))
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
          <span class="photos-storage-head-actions">
            <span>
              @if(!empty($storageStats['archiveEnabled']))
                {{ __('合計') }} {{ $storageStats['formattedTotalUsed'] }}
                / {{ __('無料枠合計') }} {{ $storageStats['formattedCombinedQuota'] }}
                {{ __('（:count枚）', ['count' => $storageStats['photoCount']]) }}
                @if(!empty($storageStats['overFreeTier']))
                  <em class="photos-storage-over">{{ __('無料枠超過') }}</em>
                @endif
              @else
                {{ __('常用') }} {{ $storageStats['formattedHotUsed'] }}
                / {{ __('無料枠') }} {{ $storageStats['formattedQuota'] }}
                {{ __('（:count枚）', ['count' => $storageStats['hotCount']]) }}
                @if(!empty($storageStats['hotOverFreeTier']))
                  <em class="photos-storage-over">{{ __('無料枠超過') }}</em>
                @endif
              @endif
            </span>
            <button type="button" class="photos-secondary-btn photos-storage-usage-btn" id="photos-usage-open">{{ __('使用状況') }}</button>
          </span>
        </div>
        <div class="photos-storage-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) min(100, $storageStats['percent']) }}">
          <span class="photos-storage-bar-fill{{ ($storageStats['percent'] >= 90 || !empty($storageStats['overFreeTier'])) ? ' is-warn' : '' }}" style="width: {{ min(100, $storageStats['percent']) }}%"></span>
        </div>
        @if(!empty($storageStats['archiveEnabled']) || ($storageStats['coldCount'] ?? 0) > 0)
          <div class="photos-storage-sub">
            {{ __('常用') }} ({{ $storageStats['primaryLabel'] }}):
            {{ $storageStats['formattedHotUsed'] }} / {{ $storageStats['formattedQuota'] }}
            {{ __('（:count枚）', ['count' => $storageStats['hotCount']]) }}
            ·
            {{ __('長期保存') }} (Backblaze B2):
            {{ $storageStats['formattedColdUsed'] }} / {{ $storageStats['formattedB2Quota'] }}
            {{ __('（:count枚）', ['count' => $storageStats['coldCount']]) }}
          </div>
        @endif
        <p class="photos-storage-note">
          {{ __('画像は解像度そのまま保存。動画は MP4（最大') }} {{ number_format((int) config('photos.max_video_upload_bytes') / 1048576) }}MB）{{ __('に対応。') }}
          @if(!empty($storageStats['pipelineEnabled']))
            {{ __('構成:') }}
            {{ __('常用原本') }} = {{ $storageStats['primaryLabel'] }}
            @if(!empty($storageStats['cloudinaryEditor']))
              · {{ __('編集') }} = Cloudinary（{{ __('一時のみ・常設保管なし') }}）
            @endif
            @if(!empty($storageStats['archiveEnabled']))
              · {{ __('長期保存') }} = Backblaze B2
            @endif
            。
            @if(!empty($storageStats['archiveEnabled']))
              {{ __('無料枠合計') }} {{ $storageStats['formattedCombinedQuota'] }}
              （{{ $storageStats['primaryLabel'] }} {{ $storageStats['formattedQuota'] }} + Backblaze B2 {{ $storageStats['formattedB2Quota'] }}）。
              {{ $storageStats['primaryLabel'] }} {{ __('超過') }} {{ $storageStats['overagePriceLabel'] }}、
              B2 {{ __('超過') }} {{ $storageStats['b2OveragePriceLabel'] }}。
            @else
              {{ $storageStats['primaryLabel'] }}:
              {{ __('無料枠') }} {{ $storageStats['formattedQuota'] }}、
              {{ __('超過分は約') }} {{ $storageStats['overagePriceLabel'] }}。
            @endif
          @else
            {{ __('保存先:') }} {{ $storageStats['diskLabel'] }}。
            @if(($storageStats['disk'] ?? '') === 'r2')
              Cloudflare R2:
              {{ __('無料枠') }} {{ $storageStats['formattedQuota'] }}、
              {{ __('超過分は約') }} {{ $storageStats['overagePriceLabel'] }}。
            @elseif(($storageStats['disk'] ?? '') === 'public')
              {{ __('（本番ではストレージ設定のパイプラインで R2 / B2 / Cloudinary を切り替え可能）') }}
            @endif
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
              <span>
                {{ __(':count枚', ['count' => $album['photoCount']]) }}
                @if(!empty($album['visibilityLabel']))
                  · {{ $album['visibilityLabel'] }}
                @endif
              </span>
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
          <div class="photos-mode-toggle" role="group" aria-label="{{ __('フィルター') }}">
            <button type="button" class="photos-mode-btn is-active" data-photos-kind="all" aria-pressed="true">{{ __('すべて') }}</button>
            <button type="button" class="photos-mode-btn" data-photos-kind="image" aria-pressed="false">{{ __('写真') }}</button>
            <button type="button" class="photos-mode-btn" data-photos-kind="video" aria-pressed="false">{{ __('動画') }}</button>
          </div>
          <div class="photos-cols-control" id="photos-cols-control" title="{{ __('列数') }}">
            <span class="photos-cols-icon" aria-hidden="true">
              <svg viewBox="0 0 20 20" width="16" height="16" fill="currentColor"><rect x="2" y="2" width="16" height="16" rx="2.5"/></svg>
            </span>
            <input
              type="range"
              id="photos-cols-slider"
              class="photos-cols-slider"
              min="1"
              max="7"
              step="1"
              value="4"
              aria-label="{{ __('1行の枚数') }}"
            />
            <span class="photos-cols-icon is-dense" aria-hidden="true">
              <svg viewBox="0 0 20 20" width="16" height="16" fill="currentColor">
                <rect x="1.5" y="1.5" width="5" height="5" rx="1"/>
                <rect x="7.5" y="1.5" width="5" height="5" rx="1"/>
                <rect x="13.5" y="1.5" width="5" height="5" rx="1"/>
                <rect x="1.5" y="7.5" width="5" height="5" rx="1"/>
                <rect x="7.5" y="7.5" width="5" height="5" rx="1"/>
                <rect x="13.5" y="7.5" width="5" height="5" rx="1"/>
                <rect x="1.5" y="13.5" width="5" height="5" rx="1"/>
                <rect x="7.5" y="13.5" width="5" height="5" rx="1"/>
                <rect x="13.5" y="13.5" width="5" height="5" rx="1"/>
              </svg>
            </span>
            <span class="photos-cols-value" id="photos-cols-value" aria-live="polite">4</span>
          </div>
          <div class="photos-select-actions" id="photos-select-actions" hidden>
            <button type="button" class="photos-secondary-btn" id="photos-select-all">{{ __('全選択') }}</button>
            <button type="button" class="photos-secondary-btn" id="photos-select-none">{{ __('全解除') }}</button>
          </div>
          <div class="photos-pager" id="photos-pager" hidden>
            <button type="button" class="photos-pager-btn" id="photos-pager-prev">{{ __('前へ') }}</button>
            <span class="photos-pager-status" id="photos-pager-status"></span>
            <button type="button" class="photos-pager-btn" id="photos-pager-next">{{ __('次へ') }}</button>
          </div>
          <button type="button" class="photos-secondary-btn" id="photos-slideshow-open">{{ __('スライドショー') }}</button>
          <div class="photos-bulk-bar" id="photos-bulk-bar" hidden>
            <span class="photos-bulk-count" id="photos-bulk-count">0{{ __('件選択') }}</span>
            <button type="button" class="photos-secondary-btn photos-danger-btn" id="photos-bulk-delete">{{ __('一括削除') }}</button>
            <label class="photos-bulk-move" id="photos-bulk-move-wrap" hidden>
              <span>{{ __('アルバムへ移動') }}</span>
              <select id="photos-bulk-move-album">
                <option value="">{{ __('アルバムなし') }}</option>
                @foreach($ownedAlbums ?? $albums as $album)
                  <option value="{{ $album['id'] }}">{{ $album['name'] }}</option>
                @endforeach
              </select>
              <button type="button" class="photos-secondary-btn" id="photos-bulk-move">{{ __('移動') }}</button>
            </label>
          </div>
        </div>

        <div class="photos-timeline" id="photos-gallery" data-photos-mode="normal" data-cols="4" style="--photos-cols: 4">
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
                    data-media-kind="{{ ($photo['mediaKind'] ?? '') === 'video' ? 'video' : 'image' }}"
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
      <button type="button" class="photos-lightbox-close" data-close-lightbox aria-label="閉じる">×</button>
      <button type="button" class="photos-lightbox-fs" id="photos-lightbox-fs" aria-pressed="false" aria-label="{{ __('全画面') }}">{{ __('全画面') }}</button>
      <div class="photos-lightbox-stage" role="dialog" aria-modal="true" aria-label="写真プレビュー">
        <button type="button" class="photos-lightbox-nav is-prev" id="photos-lightbox-prev" aria-label="前へ">
          <svg class="photos-lightbox-chevron" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
            <path d="M14.5 5.5L8 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        <div class="photos-lightbox-media" id="photos-lightbox-media">
          <img src="" alt="" id="photos-lightbox-image" />
          <video src="" id="photos-lightbox-video" controls playsinline preload="metadata" hidden></video>
        </div>
        <button type="button" class="photos-lightbox-nav is-next" id="photos-lightbox-next" aria-label="次へ">
          <svg class="photos-lightbox-chevron" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
            <path d="M9.5 5.5L16 12l-6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        <div class="photos-lightbox-meta">
          <div class="photos-lightbox-info">
            <span class="photos-lightbox-caption" id="photos-lightbox-caption"></span>
            <span class="photos-lightbox-date" id="photos-lightbox-date"></span>
          </div>
          <div class="photos-lightbox-toolbar">
            <div class="photos-lightbox-zoom" role="group" aria-label="{{ __('拡大') }}">
              <button type="button" id="photos-zoom-out" aria-label="{{ __('縮小') }}">−</button>
              <button type="button" id="photos-zoom-reset" aria-label="{{ __('リセット') }}">100%</button>
              <button type="button" id="photos-zoom-in" aria-label="{{ __('拡大') }}">＋</button>
            </div>
            <div class="photos-lightbox-actions" id="photos-lb-main-actions">
              <button type="button" class="photos-secondary-btn" id="photos-lightbox-fs-action">{{ __('全画面') }}</button>
              <button type="button" class="photos-secondary-btn" id="photos-lightbox-slideshow">{{ __('スライドショー') }}</button>
              <button type="button" class="photos-secondary-btn" id="photos-share-btn">{{ __('共有') }}</button>
              <button type="button" class="photos-secondary-btn photos-messenger-btn" id="photos-share-messenger-btn">{{ __('Messengerで送る') }}</button>
              @if($selectedAlbumId && !empty($canManageSelected))
                <form method="post" action="/photos/albums/{{ $selectedAlbumId }}/cover" id="photos-cover-form">
                  @csrf
                  <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                  <input type="hidden" name="photo_id" id="photos-cover-photo-id" value="" />
                  <button type="submit" class="photos-cover-btn" id="photos-cover-btn">{{ __('表紙にする') }}</button>
                </form>
              @endif
              <button type="button" class="photos-secondary-btn" id="photos-lb-edit-open" hidden>{{ __('編集') }}</button>
            </div>
            <div class="photos-lightbox-actions photos-lb-edit-actions" id="photos-lb-edit-actions" hidden>
              <button type="button" class="photos-secondary-btn" id="photos-lb-edit-back">{{ __('戻る') }}</button>
              <button type="button" class="photos-secondary-btn" id="photos-open-crop-btn" hidden>{{ __('画像をトリム') }}</button>
              @if(!empty($cloudinaryEditorReady))
                <button type="button" class="photos-secondary-btn" id="photos-cloudinary-edit-btn" hidden>{{ __('Cloudinaryで編集') }}</button>
              @endif
              @if(!empty($stabilityEnhanceReady))
                <button type="button" class="photos-secondary-btn" id="photos-stability-enhance-btn" hidden>{{ __('AIで鮮明化') }}</button>
              @endif
              <form method="post" action="" id="photos-edit-image-form" enctype="multipart/form-data" hidden>
                @csrf
                <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                <input type="hidden" name="label" value="{{ __('トリム') }}" />
                <input type="file" name="image" accept="image/*" id="photos-edit-image-input" hidden />
              </form>
              <form method="post" action="" id="photos-taken-at-form" class="photos-taken-at-form" hidden>
                @csrf
                <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                <label>
                  <span class="photos-lb-label">{{ __('登録日') }}</span>
                  <input type="datetime-local" name="taken_at" id="photos-taken-at-input" required />
                </label>
                <button type="submit" class="photos-secondary-btn">{{ __('日付を更新') }}</button>
              </form>
              <form method="post" action="" id="photos-trim-video-form" class="photos-trim-form" hidden>
                @csrf
                <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                <label>{{ __('開始秒') }} <input type="number" name="start" id="photos-trim-start" min="0" step="0.1" value="0" /></label>
                <label>{{ __('終了秒') }} <input type="number" name="end" id="photos-trim-end" min="0.1" step="0.1" value="5" /></label>
                <button type="submit" class="photos-secondary-btn">{{ __('動画をトリム') }}</button>
              </form>
              <form method="post" action="" id="photos-delete-form" onsubmit='return confirm(@json(__('このメディアを削除しますか？')))'>
                @csrf
                <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                <button type="submit" class="photos-delete-btn">{{ __('削除') }}</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="photos-slideshow" id="photos-slideshow" hidden>
      <div class="photos-ss-stage" role="dialog" aria-modal="true" aria-label="{{ __('スライドショー') }}">
        <div class="photos-ss-viewport" id="photos-ss-viewport">
          <div class="photos-ss-layer is-active" id="photos-ss-layer-a" data-layer="a">
            <img alt="" hidden />
            <video controls playsinline preload="metadata" hidden></video>
          </div>
          <div class="photos-ss-layer" id="photos-ss-layer-b" data-layer="b">
            <img alt="" hidden />
            <video controls playsinline preload="metadata" hidden></video>
          </div>
        </div>
        <div class="photos-ss-progress" id="photos-ss-progress" aria-hidden="true"><span></span></div>
        <button type="button" class="photos-ss-nav is-prev" id="photos-ss-prev" aria-label="{{ __('前へ') }}">‹</button>
        <button type="button" class="photos-ss-nav is-next" id="photos-ss-next" aria-label="{{ __('次へ') }}">›</button>
        <button type="button" class="photos-ss-close" id="photos-ss-close" aria-label="{{ __('閉じる') }}">×</button>
        <button type="button" class="photos-ss-fs" id="photos-ss-fs" aria-pressed="false" aria-label="{{ __('全画面') }}">{{ __('全画面') }}</button>
        <button type="button" class="photos-ss-chrome-peek" id="photos-ss-chrome-peek" hidden aria-label="{{ __('フッターを表示') }}">{{ __('フッターを表示') }}</button>
        <div class="photos-ss-chrome" id="photos-ss-chrome">
          <div class="photos-ss-meta">
            <p class="photos-ss-caption" id="photos-ss-caption"></p>
            <p class="photos-ss-counter" id="photos-ss-counter"></p>
          </div>
          <div class="photos-ss-controls">
            <button type="button" class="photos-ss-btn" id="photos-ss-play" aria-pressed="false">{{ __('自動再生') }}</button>
            <button type="button" class="photos-ss-btn" id="photos-ss-chrome-toggle" aria-pressed="false">{{ __('フッターを隠す') }}</button>
            <button type="button" class="photos-ss-btn" id="photos-ss-fs-action" aria-pressed="false">{{ __('全画面') }}</button>
            <label class="photos-ss-field">
              <span>{{ __('間隔') }}</span>
              <select id="photos-ss-interval" aria-label="{{ __('切替間隔') }}">
                <option value="2000">2{{ __('秒') }}</option>
                <option value="3000" selected>3{{ __('秒') }}</option>
                <option value="5000">5{{ __('秒') }}</option>
                <option value="8000">8{{ __('秒') }}</option>
                <option value="12000">12{{ __('秒') }}</option>
              </select>
            </label>
            <label class="photos-ss-field">
              <span>{{ __('切替効果') }}</span>
              <select id="photos-ss-effect" aria-label="{{ __('切替効果') }}">
                <option value="random">{{ __('ランダム') }}</option>
                <option value="fade">{{ __('フェード') }}</option>
                <option value="slide-left">{{ __('左スライド') }}</option>
                <option value="slide-right">{{ __('右スライド') }}</option>
                <option value="slide-up">{{ __('上スライド') }}</option>
                <option value="slide-down">{{ __('下スライド') }}</option>
                <option value="zoom-in">{{ __('ズームイン') }}</option>
                <option value="zoom-out">{{ __('ズームアウト') }}</option>
                <option value="blur">{{ __('ブラー') }}</option>
                <option value="wipe-left">{{ __('ワイプ（左）') }}</option>
                <option value="wipe-right">{{ __('ワイプ（右）') }}</option>
                <option value="flip">{{ __('フリップ') }}</option>
                <option value="rotate">{{ __('回転') }}</option>
                <option value="cube">{{ __('キューブ') }}</option>
                <option value="kenburns">{{ __('ケンバーンズ') }}</option>
              </select>
            </label>
            <label class="photos-ss-check">
              <input type="checkbox" id="photos-ss-images-only" checked />
              <span>{{ __('写真のみ') }}</span>
            </label>
            <div class="photos-ss-music" id="photos-ss-music">
              <label class="photos-ss-field">
                <span>{{ __('音楽') }}</span>
                <input type="file" id="photos-ss-music-file" class="photos-ss-music-file" accept="audio/*" />
              </label>
              <span class="photos-ss-music-name" id="photos-ss-music-name" hidden></span>
              <button type="button" class="photos-ss-btn" id="photos-ss-music-clear" hidden>{{ __('音楽を解除') }}</button>
              <label class="photos-ss-field" title="{{ __('音量') }}">
                <span>{{ __('音量') }}</span>
                <input type="range" id="photos-ss-music-volume" min="0" max="100" value="60" />
              </label>
              <label class="photos-ss-check">
                <input type="checkbox" id="photos-ss-music-loop" checked />
                <span>{{ __('ループ') }}</span>
              </label>
              <label class="photos-ss-check">
                <input type="checkbox" id="photos-ss-music-mute" />
                <span>{{ __('ミュート') }}</span>
              </label>
              <audio id="photos-ss-audio" preload="auto" loop hidden></audio>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal modal-centered" id="photos-usage-modal" hidden>
      <div class="modal-backdrop" data-close-usage-modal></div>
      <div class="modal-dialog photos-usage-dialog" role="dialog" aria-labelledby="photos-usage-modal-title">
        <div class="modal-header">
          <h2 id="photos-usage-modal-title">{{ __('ストレージ使用状況') }}</h2>
          <button type="button" class="modal-close" data-close-usage-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <div class="photos-usage-body">
          <p class="photos-usage-lead">
            @if(!empty($storageStats['archiveEnabled']))
              {{ __('無料枠合計') }}:
              {{ $storageStats['formattedCombinedQuota'] }}
              （Cloudflare R2 {{ $storageStats['formattedQuota'] }} + Backblaze B2 {{ $storageStats['formattedB2Quota'] }}）
              · {{ __('現在の使用') }} {{ $storageStats['formattedTotalUsed'] }}
              · {{ __('超過課金見込') }} {{ $storageStats['estimatedTotalBillLabel'] }}
            @else
              {{ __('無料枠') }}: {{ $storageStats['formattedCombinedQuota'] }}
              · {{ __('現在の使用') }} {{ $storageStats['formattedUsed'] }}
              · {{ __('超過課金見込') }} {{ $storageStats['estimatedTotalBillLabel'] }}
            @endif
          </p>
          <div class="photos-usage-grid">
            @foreach(($storageStats['providers'] ?? []) as $provider)
              <article class="photos-usage-card{{ empty($provider['enabled']) ? ' is-off' : '' }}{{ !empty($provider['overFreeTier']) ? ' is-over' : '' }}">
                <header class="photos-usage-card-head">
                  <h3>{{ $provider['name'] }}</h3>
                  <span>{{ $provider['role'] }}</span>
                </header>
                @if(empty($provider['enabled']))
                  <p class="photos-usage-muted">{{ __('現在このパイプラインでは未使用') }}</p>
                @else
                  <dl class="photos-usage-dl">
                    <div>
                      <dt>{{ __('使用量') }}</dt>
                      <dd>{{ $provider['usedLabel'] }}</dd>
                    </div>
                    <div>
                      <dt>{{ __('無料枠') }}</dt>
                      <dd>{{ $provider['quotaLabel'] }}</dd>
                    </div>
                    @if(($provider['id'] ?? '') !== 'cloudinary')
                      <div>
                        <dt>{{ __('件数') }}</dt>
                        <dd>{{ __('（:count枚）', ['count' => $provider['count']]) }}</dd>
                      </div>
                      <div>
                        <dt>{{ __('使用率') }}</dt>
                        <dd>{{ $provider['percent'] }}%</dd>
                      </div>
                    @endif
                    <div>
                      <dt>{{ __('超過単価') }}</dt>
                      <dd>{{ $provider['overagePriceLabel'] }}</dd>
                    </div>
                    <div>
                      <dt>{{ __('課金見込') }}</dt>
                      <dd>
                        {{ $provider['estimatedBillLabel'] }}
                        @if(!empty($provider['overFreeTier']))
                          <em class="photos-storage-over">{{ __('無料枠超過') }}</em>
                        @endif
                      </dd>
                    </div>
                  </dl>
                  <p class="photos-usage-note">{{ $provider['billingNote'] }}</p>
                  @if(($provider['id'] ?? '') !== 'cloudinary')
                    <div class="photos-storage-bar photos-usage-mini-bar" role="presentation">
                      <span class="photos-storage-bar-fill{{ !empty($provider['overFreeTier']) ? ' is-warn' : '' }}" style="width: {{ min(100, (float) $provider['percent']) }}%"></span>
                    </div>
                  @endif
                @endif
              </article>
            @endforeach
          </div>
          <p class="photos-usage-foot">
            {{ __('金額は各サービスの公開単価に基づく概算です。実際の請求はダッシュボードを確認してください。') }}
          </p>
        </div>
        <div class="modal-actions">
          <button type="button" class="secondary" data-close-usage-modal>{{ __('閉じる') }}</button>
        </div>
      </div>
    </div>

    <div class="modal modal-centered" id="photos-dup-modal" hidden>
      <div class="modal-backdrop" data-close-dup-modal></div>
      <div class="modal-dialog photos-dup-dialog" role="dialog" aria-labelledby="photos-dup-modal-title">
        <div class="modal-header">
          <h2 id="photos-dup-modal-title">{{ __('重複チェック') }}</h2>
          <button type="button" class="modal-close" data-close-dup-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <div class="photos-dup-body">
          <p class="photos-dup-lead" id="photos-dup-lead">{{ __('保存済みメディアの内容が同じものを探します。') }}</p>
          <div class="photos-dup-toolbar">
            <button type="button" class="photos-secondary-btn" id="photos-dup-rescan">{{ __('再スキャン') }}</button>
            <button type="button" class="photos-secondary-btn" id="photos-dup-select-extras" hidden>{{ __('各組の余分を選択') }}</button>
            <button type="button" class="photos-secondary-btn" id="photos-dup-select-none" hidden>{{ __('選択解除') }}</button>
            <button type="button" class="photos-secondary-btn photos-danger-btn" id="photos-dup-bulk-delete" hidden>{{ __('選択を一括削除') }}</button>
            <span class="photos-dup-status" id="photos-dup-status" aria-live="polite"></span>
          </div>
          <div class="photos-dup-list" id="photos-dup-list"></div>
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
          <label>
            {{ __('公開範囲') }}
            <select name="visibility" id="photos-album-visibility">
              <option value="private">{{ __('非公開') }}</option>
              <option value="group">{{ __('グループのみ') }}</option>
              <option value="public">{{ __('登録ユーザーに公開') }}</option>
            </select>
          </label>
          <label id="photos-album-group-wrap" hidden>
            {{ __('共有グループ') }}
            <select name="group_id" id="photos-album-group-id">
              <option value="">{{ __('グループを選択') }}</option>
              @foreach($approvedGroups ?? [] as $group)
                <option value="{{ $group['id'] }}">{{ $group['name'] }}</option>
              @endforeach
            </select>
          </label>
          <div class="modal-actions">
            <button type="button" class="secondary" data-close-album-modal>{{ __('キャンセル') }}</button>
            <button type="submit" id="photos-album-submit">{{ __('作成') }}</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal modal-centered" id="photos-crop-modal" hidden>
      <div class="modal-backdrop" data-close-crop-modal></div>
      <div class="modal-dialog photos-crop-dialog" role="dialog" aria-labelledby="photos-crop-modal-title">
        <div class="modal-header">
          <h2 id="photos-crop-modal-title">{{ __('画像をトリム') }}</h2>
          <button type="button" class="modal-close" data-close-crop-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <p class="hint photos-crop-hint">{{ __('枠をドラッグして切り抜き範囲を選び、「トリムして保存」で別ファイルとして保存します。') }}</p>
        <div class="photos-crop-toolbar">
          <button type="button" class="secondary mini-btn" id="photos-crop-rotate-left">↺ {{ __('左回転') }}</button>
          <button type="button" class="secondary mini-btn" id="photos-crop-rotate-right">↻ {{ __('右回転') }}</button>
          <button type="button" class="secondary mini-btn" id="photos-crop-reset">{{ __('リセット') }}</button>
        </div>
        <div class="photos-crop-stage" id="photos-crop-stage">
          <div class="photos-crop-frame" id="photos-crop-frame">
            <canvas id="photos-crop-canvas"></canvas>
            <div class="photos-crop-overlay" id="photos-crop-overlay" hidden>
              <div class="photos-crop-shade photos-crop-shade-top"></div>
              <div class="photos-crop-shade photos-crop-shade-left"></div>
              <div class="photos-crop-shade photos-crop-shade-right"></div>
              <div class="photos-crop-shade photos-crop-shade-bottom"></div>
              <div class="photos-crop-box" id="photos-crop-box">
                <span class="photos-crop-handle" data-handle="nw"></span>
                <span class="photos-crop-handle" data-handle="ne"></span>
                <span class="photos-crop-handle" data-handle="sw"></span>
                <span class="photos-crop-handle" data-handle="se"></span>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="secondary" data-close-crop-modal>{{ __('キャンセル') }}</button>
          <button type="button" id="photos-crop-save">{{ __('トリムして保存') }}</button>
        </div>
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
        const usageModal = document.getElementById('photos-usage-modal')
        const usageOpenBtn = document.getElementById('photos-usage-open')
        const installBtn = document.getElementById('photos-pwa-install')
        const uploadLabel = document.querySelector('.photos-hero-actions .photos-upload-btn-label')
        let currentIndex = 0
        let deferredPrompt = null
        let uploading = false

        function isVideoFile(file) {
          return !!file && (file.type.startsWith('video/') || /\.mp4$/i.test(file.name || ''))
        }

        async function captureVideoThumb(file, timeoutMs = 2500) {
          if (!file || typeof document === 'undefined') return null
          return new Promise((resolve) => {
            const url = URL.createObjectURL(file)
            const video = document.createElement('video')
            video.muted = true
            video.playsInline = true
            video.preload = 'metadata'
            let settled = false
            const finish = (value) => {
              if (settled) return
              settled = true
              clearTimeout(timer)
              URL.revokeObjectURL(url)
              try { video.removeAttribute('src'); video.load() } catch (_) {}
              resolve(value)
            }
            const timer = setTimeout(() => finish(null), timeoutMs)
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
          // 画像は解像度・ファイル内容を変更せずそのままアップロードする
          return file
        }

        function setUploadProgress(text) {
          if (uploadLabel) uploadLabel.textContent = text
        }

        @php
          $photosUploadLimits = $uploadLimits ?? [
              'postMaxBytes' => 134217728,
              'uploadMaxBytes' => 134217728,
              'videoMaxBytes' => 838860800,
              'chunkBytes' => 4194304,
          ];
        @endphp
        const uploadLimits = @json($photosUploadLimits);

        function readCsrfToken() {
          const fromForm = form?.querySelector('input[name="_token"]')?.value
          if (fromForm) return fromForm
          const meta = document.querySelector('meta[name="csrf-token"]')?.content
          if (meta) return meta
          return ''
        }

        function readXsrfToken() {
          const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)
          if (!match) return ''
          try { return decodeURIComponent(match[1]) } catch (_) { return match[1] || '' }
        }

        function sleep(ms) {
          return new Promise((resolve) => setTimeout(resolve, ms))
        }

        function postUploadFormData(url, fd, { onProgress, timeoutMs = 15 * 60 * 1000 } = {}) {
          return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest()
            xhr.open('POST', url)
            xhr.setRequestHeader('Accept', 'application/json')
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest')
            const xsrf = readXsrfToken()
            if (xsrf) xhr.setRequestHeader('X-XSRF-TOKEN', xsrf)
            xhr.timeout = timeoutMs
            xhr.withCredentials = true
            xhr.upload.onprogress = (e) => {
              if (!e.lengthComputable || typeof onProgress !== 'function') return
              onProgress(Math.min(100, Math.round((e.loaded / e.total) * 100)))
            }
            xhr.onload = () => {
              let data = null
              try { data = JSON.parse(xhr.responseText || '') } catch (_) {}
              resolve({
                ok: xhr.status >= 200 && xhr.status < 300,
                status: xhr.status,
                data,
              })
            }
            xhr.onerror = () => reject(new Error('network'))
            xhr.ontimeout = () => reject(new Error('timeout'))
            xhr.send(fd)
          })
        }

        async function postUploadFormDataWithRetry(url, fd, opts = {}, retries = 3) {
          let lastErr = null
          for (let attempt = 1; attempt <= retries; attempt++) {
            try {
              // 長時間アップロード中にセッションが動くよう、毎回最新トークンを付ける
              if (fd.has('_token')) fd.delete('_token')
              fd.append('_token', readCsrfToken())
              const res = await postUploadFormData(url, fd, opts)
              if (res.ok && res.data?.ok) return res
              const retryable = res.status === 419 || res.status === 429 || res.status >= 500
              const message = res.data?.message || `アップロードに失敗しました（${res.status}）`
              if (!retryable || attempt === retries) {
                throw new Error(message)
              }
              lastErr = new Error(message)
              await sleep(800 * attempt)
            } catch (err) {
              lastErr = err
              const retryable = err?.message === 'network' || err?.message === 'timeout'
              if (!retryable || attempt === retries) throw err
              await sleep(800 * attempt)
            }
          }
          throw lastErr || new Error('アップロードに失敗しました')
        }

        function newUploadId() {
          if (window.crypto?.randomUUID) return crypto.randomUUID().replace(/-/g, '')
          return `u${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`
        }

        function shouldUseChunkedUpload(file) {
          if (isVideoFile(file)) return true
          const limit = Math.min(
            Number(uploadLimits.uploadMaxBytes) || 0,
            Number(uploadLimits.postMaxBytes) || 0
          )
          // PHP 上限の半分、または 24MB 超は分割（413 回避）
          const threshold = limit > 0 ? Math.max(4 * 1024 * 1024, Math.floor(limit * 0.45)) : 24 * 1024 * 1024
          return (Number(file.size) || 0) > threshold
        }

        function allowDuplicatesChecked() {
          return !!document.getElementById('photos-allow-duplicates')?.checked
        }

        async function sha256Hex(buffer) {
          const digest = await crypto.subtle.digest('SHA-256', buffer)
          return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('')
        }

        /** サーバー PhotoService::computeContentHashFromPath と同じアルゴリズム */
        async function computeContentHash(file) {
          const sample = 2 * 1024 * 1024
          const size = Number(file.size) || 0
          if (size <= sample * 2) {
            return sha256Hex(await file.arrayBuffer())
          }
          const headHash = await sha256Hex(await file.slice(0, sample).arrayBuffer())
          const tailHash = await sha256Hex(await file.slice(size - sample, size).arrayBuffer())
          const payload = new TextEncoder().encode(`sa2-photo-v1|${size}|${headHash}|${tailHash}`)
          return sha256Hex(payload)
        }

        async function findExistingHashes(hashes) {
          const unique = [...new Set(hashes.filter(Boolean))]
          if (!unique.length) return new Set()
          const existing = new Set()
          for (let i = 0; i < unique.length; i += 100) {
            const chunk = unique.slice(i, i + 100)
            const fd = new FormData()
            chunk.forEach((h) => fd.append('hashes[]', h))
            const res = await postUploadFormDataWithRetry('/photos/check-duplicates', fd, {
              timeoutMs: 60 * 1000,
            }, 2)
            const list = Array.isArray(res.data?.existing) ? res.data.existing : []
            list.forEach((h) => existing.add(String(h).toLowerCase()))
          }
          return existing
        }

        async function uploadFileChunked(file, { albumId, returnTo, thumb, fileLabel, onProgress, allowDuplicates }) {
          const chunkBytes = Math.max(1 * 1024 * 1024, Number(uploadLimits.chunkBytes) || 4 * 1024 * 1024)
          const total = Math.max(1, Math.ceil((Number(file.size) || 0) / chunkBytes))
          const uploadId = newUploadId()

          for (let i = 0; i < total; i++) {
            const blob = file.slice(i * chunkBytes, Math.min((i + 1) * chunkBytes, file.size))
            const fd = new FormData()
            fd.append('upload_id', uploadId)
            fd.append('chunk_index', String(i))
            fd.append('chunk_total', String(total))
            fd.append('chunk', blob, `part-${i}`)
            await postUploadFormDataWithRetry('/photos/upload/chunk', fd, {
              timeoutMs: 10 * 60 * 1000,
              onProgress: (pct) => {
                const overall = Math.round(((i + pct / 100) / total) * 90)
                if (typeof onProgress === 'function') onProgress(overall, `送信中… ${fileLabel} (${overall}%)`)
              },
            })
          }

          const done = new FormData()
          done.append('upload_id', uploadId)
          done.append('original_name', file.name || 'upload.bin')
          done.append('returnTo', returnTo)
          if (albumId) done.append('album_id', albumId)
          if (file.type) done.append('mime', file.type)
          if (allowDuplicates) done.append('allow_duplicates', '1')
          if (thumb) done.append('video_thumb', thumb, thumb.name)
          if (typeof onProgress === 'function') onProgress(95, `保存中… ${fileLabel}`)
          const complete = await postUploadFormDataWithRetry('/photos/upload/complete', done, {
            timeoutMs: 20 * 60 * 1000,
          }, 2)
          if (typeof onProgress === 'function') onProgress(100, `完了… ${fileLabel}`)
          return {
            count: Number(complete.data?.count) || 0,
            skipped: Number(complete.data?.skipped) || 0,
          }
        }

        async function uploadFileSimple(file, { albumId, returnTo, thumb, fileLabel, onProgress, allowDuplicates }) {
          const fd = new FormData()
          fd.append('returnTo', returnTo)
          if (albumId) fd.append('album_id', albumId)
          if (allowDuplicates) fd.append('allow_duplicates', '1')
          fd.append('photos[]', file, file.name)
          if (thumb) {
            fd.append('video_thumbs[]', thumb, thumb.name)
            fd.append('video_thumb_for', '0')
          }
          const res = await postUploadFormDataWithRetry(form.action || '/photos', fd, {
            onProgress: (pct) => {
              if (typeof onProgress === 'function') onProgress(pct, `送信中… ${fileLabel} (${pct}%)`)
            },
            timeoutMs: 20 * 60 * 1000,
          })
          return {
            count: Number(res.data?.count) || 0,
            skipped: Number(res.data?.skipped) || 0,
          }
        }

        async function submitFiles(fileList) {
          if (!fileList?.length || !form || uploading) return
          uploading = true
          const list = Array.from(fileList)
          const totalSelected = list.length
          const returnTo = form.querySelector('input[name="returnTo"]')?.value || '/photos'
          const albumId = form.querySelector('input[name="album_id"]')?.value || ''
          const allowDuplicates = allowDuplicatesChecked()
          let totalCreated = 0
          let totalSkipped = 0
          const failed = []

          const videoMax = Number(uploadLimits.videoMaxBytes) || (800 * 1024 * 1024)
          try {
            setUploadProgress(`重複チェック中… 0/${totalSelected}`)
            const hashed = []
            for (let i = 0; i < list.length; i++) {
              const file = list[i]
              const displayName = file.name || `file-${i + 1}`
              if (isVideoFile(file) && (Number(file.size) || 0) > videoMax) {
                failed.push(`${displayName}: 最大 ${Math.round(videoMax / 1048576)}MB まで`)
                continue
              }
              setUploadProgress(`重複チェック中… ${i + 1}/${totalSelected}`)
              let hash = ''
              try {
                hash = await computeContentHash(file)
              } catch (_) {
                hash = ''
              }
              hashed.push({ file, displayName, hash })
            }

            let existing = new Set()
            if (!allowDuplicates) {
              existing = await findExistingHashes(hashed.map((h) => h.hash).filter(Boolean))
            }

            const queue = []
            const seenInBatch = new Set()
            for (const item of hashed) {
              if (!allowDuplicates && item.hash) {
                if (existing.has(item.hash) || seenInBatch.has(item.hash)) {
                  totalSkipped += 1
                  continue
                }
                seenInBatch.add(item.hash)
              }
              queue.push(item)
            }

            if (totalSkipped > 0) {
              setUploadProgress(`重複 ${totalSkipped}件スキップ → 送信 ${queue.length}件`)
              await sleep(400)
            }

            for (let i = 0; i < queue.length; i++) {
              const { file, displayName } = queue[i]
              const fileLabel = `${i + 1}/${queue.length}`

              setUploadProgress(`準備中… ${fileLabel}`)
              let thumb = null
              if (isVideoFile(file)) {
                setUploadProgress(`サムネ準備… ${fileLabel}`)
                thumb = await captureVideoThumb(file, 2500)
              }

              try {
                const onProgress = (_pct, text) => setUploadProgress(text)
                let result = { count: 0, skipped: 0 }
                let lastErr = null
                for (let attempt = 1; attempt <= 2; attempt++) {
                  try {
                    result = shouldUseChunkedUpload(file)
                      ? await uploadFileChunked(file, { albumId, returnTo, thumb, fileLabel, onProgress, allowDuplicates })
                      : await uploadFileSimple(file, { albumId, returnTo, thumb, fileLabel, onProgress, allowDuplicates })
                    lastErr = null
                    break
                  } catch (err) {
                    lastErr = err
                    if (attempt < 2) {
                      setUploadProgress(`再試行… ${fileLabel}`)
                      await sleep(1000)
                    }
                  }
                }
                if (lastErr) throw lastErr
                totalCreated += result.count
                totalSkipped += result.skipped
                setUploadProgress(`完了… 追加${totalCreated} / スキップ${totalSkipped}`)
              } catch (err) {
                const reason = err?.message === 'timeout'
                  ? 'タイムアウト'
                  : (err?.message === 'network' ? '通信エラー' : (err?.message || '失敗'))
                failed.push(`${displayName}: ${reason}`)
                setUploadProgress(`失敗… ${fileLabel} → 次へ`)
                await sleep(300)
              }
            }

            const url = new URL(returnTo, window.location.origin)
            const parts = []
            if (totalCreated > 0) parts.push(`${totalCreated}件追加`)
            if (totalSkipped > 0) parts.push(`重複スキップ ${totalSkipped}件`)
            if (failed.length) parts.push(`失敗 ${failed.length}件`)

            if (totalCreated > 0 || totalSkipped > 0) {
              const notice = parts.join(' · ') || '処理が完了しました'
              if (failed.length) {
                window.alert(`${notice}\n\n失敗したファイル:\n- ${failed.slice(0, 8).join('\n- ')}${failed.length > 8 ? `\n…他 ${failed.length - 8}件` : ''}`)
              }
              url.searchParams.set('notice', notice)
            } else if (failed.length) {
              window.alert(`追加できませんでした:\n- ${failed.slice(0, 8).join('\n- ')}`)
              url.searchParams.set('error', failed[0])
            } else {
              url.searchParams.set('notice', '追加する新規ファイルはありませんでした')
            }
            window.location.assign(url.pathname + url.search + url.hash)
          } catch (_) {
            window.alert(@json(__('ファイルの処理に失敗しました。別の形式で試してください。')));
          } finally {
            uploading = false
            setUploadProgress(@json(__('写真・動画を追加')));
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

        let photosOverlayKind = null
        let photosOverlayIgnorePop = false
        let photosFsExitFromUi = false
        let photosFsEatNextEscClose = false

        function claimPhotosOverlay(kind) {
          if (photosOverlayKind === kind) return
          if (photosOverlayKind) {
            photosOverlayKind = kind
            try { history.replaceState({ photosOverlay: kind }, '', location.href) } catch (_) {}
            return
          }
          photosOverlayKind = kind
          try { history.pushState({ photosOverlay: kind }, '', location.href) } catch (_) {}
        }

        function releasePhotosOverlay(kind, opts = {}) {
          if (!photosOverlayKind) return
          if (kind && photosOverlayKind !== kind) return
          photosOverlayKind = null
          if (opts.skipHistory) return
          if (history.state && history.state.photosOverlay) {
            photosOverlayIgnorePop = true
            try {
              history.back()
            } catch (_) {
              photosOverlayIgnorePop = false
            }
          }
        }

        window.addEventListener('popstate', () => {
          if (photosOverlayIgnorePop) {
            photosOverlayIgnorePop = false
            return
          }
          if (slideshow && !slideshow.hidden) {
            photosOverlayKind = null
            closeSlideshow({ skipHistory: true })
            return
          }
          if (lightbox && !lightbox.hidden) {
            photosOverlayKind = null
            closeLightbox({ skipHistory: true })
          }
        })

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
          if (photo.editLabel) {
            lightboxDate.textContent += (lightboxDate.textContent ? ' · ' : '') + photo.editLabel
          }
          if (deleteForm) deleteForm.action = `/photos/${photo.id}/delete`
          const editForm = document.getElementById('photos-edit-image-form')
          const trimForm = document.getElementById('photos-trim-video-form')
          const cropBtn = document.getElementById('photos-open-crop-btn')
          const takenAtForm = document.getElementById('photos-taken-at-form')
          const takenAtInput = document.getElementById('photos-taken-at-input')
          const editOpenBtn = document.getElementById('photos-lb-edit-open')
          const cloudinaryEditBtn = document.getElementById('photos-cloudinary-edit-btn')
          const stabilityEnhanceBtn = document.getElementById('photos-stability-enhance-btn')
          const canEdit = !!photo.canEdit
          if (editForm) {
            editForm.action = `/photos/${photo.id}/edit-image`
            editForm.hidden = true
          }
          if (cropBtn) {
            // 画像のみ：動画では画像トリムを出さない
            cropBtn.hidden = !canEdit || isVideo
            cropBtn.dataset.photoId = String(photo.id)
            cropBtn.dataset.photoUrl = photo.fileUrl || (`/photos/${photo.id}/file`)
          }
          if (cloudinaryEditBtn) {
            cloudinaryEditBtn.hidden = !canEdit || isVideo
            cloudinaryEditBtn.dataset.photoId = String(photo.id)
          }
          if (stabilityEnhanceBtn) {
            stabilityEnhanceBtn.hidden = !canEdit || isVideo
            stabilityEnhanceBtn.dataset.photoId = String(photo.id)
          }
          if (trimForm) {
            // 動画のみ：写真では動画トリムを出さない
            trimForm.action = `/photos/${photo.id}/trim-video`
            trimForm.hidden = !canEdit || !isVideo
          }
          if (takenAtForm && takenAtInput) {
            takenAtForm.action = `/photos/${photo.id}/taken-at`
            takenAtForm.hidden = !canEdit
            takenAtInput.value = photo.takenAtLocal || ''
          }
          if (deleteForm) {
            deleteForm.hidden = !canEdit
          }
          if (editOpenBtn) editOpenBtn.hidden = !canEdit
          setLightboxEditMode(false)
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
          claimPhotosOverlay('lightbox')
        }

        function closeLightbox(opts = {}) {
          if (!lightbox) return
          stopLightboxVideo()
          exitPhotosFullscreen(lightbox)
          setLightboxEditMode(false)
          lightbox.classList.remove('is-fullscreen')
          lightbox.hidden = true
          if (lightboxImage) lightboxImage.src = ''
          document.body.style.overflow = ''
          setLightboxZoom(1)
          releasePhotosOverlay('lightbox', opts)
        }

        let lightboxEditMode = false
        function setLightboxEditMode(on) {
          lightboxEditMode = !!on
          const main = document.getElementById('photos-lb-main-actions')
          const edit = document.getElementById('photos-lb-edit-actions')
          if (main) main.hidden = lightboxEditMode
          if (edit) edit.hidden = !lightboxEditMode
          if (lightbox) lightbox.classList.toggle('is-edit-mode', lightboxEditMode)
        }

        document.getElementById('photos-lb-edit-open')?.addEventListener('click', () => setLightboxEditMode(true))
        document.getElementById('photos-lb-edit-back')?.addEventListener('click', () => setLightboxEditMode(false))

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

        function photosFullscreenElement() {
          return document.fullscreenElement || document.webkitFullscreenElement || null
        }

        function requestPhotosFullscreen(el) {
          if (!el) return Promise.reject(new Error('missing element'))
          const req = el.requestFullscreen || el.webkitRequestFullscreen
          if (!req) return Promise.reject(new Error('unsupported'))
          return Promise.resolve(req.call(el))
        }

        function exitPhotosFullscreen(el) {
          const current = photosFullscreenElement()
          if (!current) return Promise.resolve()
          if (el && current !== el) return Promise.resolve()
          const exit = document.exitFullscreen || document.webkitExitFullscreen
          if (!exit) return Promise.resolve()
          return Promise.resolve(exit.call(document)).catch(() => {})
        }

        async function togglePhotosFullscreen(el) {
          if (!el) return
          try {
            if (photosFullscreenElement() === el) await exitPhotosFullscreen(el)
            else await requestPhotosFullscreen(el)
          } catch (_) {
            window.alert(@json(__('このブラウザでは全画面表示を使えません。')));
          }
        }

        function syncPhotosFullscreenButtons() {
          const fsEl = photosFullscreenElement()
          const lightboxEl = document.getElementById('photos-lightbox')
          const slideshowEl = document.getElementById('photos-slideshow')
          const lightboxFs = fsEl === lightboxEl
          const slideshowFs = fsEl === slideshowEl
          const labelEnter = @json(__('全画面'));
          const labelExit = @json(__('全画面解除'));
          if (lightboxEl) {
            const wasLbFs = lightboxEl.classList.contains('is-fullscreen')
            lightboxEl.classList.toggle('is-fullscreen', lightboxFs)
            if (wasLbFs && !lightboxFs) {
              if (photosFsExitFromUi) photosFsExitFromUi = false
              else photosFsEatNextEscClose = true
            }
          }
          ;[
            document.getElementById('photos-lightbox-fs'),
            document.getElementById('photos-lightbox-fs-action'),
          ].forEach((btn) => {
            if (!btn) return
            btn.classList.toggle('is-active', lightboxFs)
            btn.setAttribute('aria-pressed', lightboxFs ? 'true' : 'false')
            if (!lightboxFs) btn.textContent = labelEnter
          })
          ;[
            document.getElementById('photos-ss-fs'),
            document.getElementById('photos-ss-fs-action'),
          ].forEach((btn) => {
            if (!btn) return
            btn.classList.toggle('is-active', slideshowFs)
            btn.setAttribute('aria-pressed', slideshowFs ? 'true' : 'false')
            if (!slideshowFs) btn.textContent = labelEnter
          })
        }

        document.getElementById('photos-lightbox-fs')?.addEventListener('click', () => togglePhotosFullscreen(lightbox))
        document.getElementById('photos-lightbox-fs-action')?.addEventListener('click', () => togglePhotosFullscreen(lightbox))

        const gallery = document.getElementById('photos-gallery')
        const bulkBar = document.getElementById('photos-bulk-bar')
        const bulkCount = document.getElementById('photos-bulk-count')
        const bulkMoveWrap = document.getElementById('photos-bulk-move-wrap')
        const colsControl = document.getElementById('photos-cols-control')
        const colsSlider = document.getElementById('photos-cols-slider')
        const colsValue = document.getElementById('photos-cols-value')
        const PHOTOS_MODE_KEY = 'photos-view-mode'
        const PHOTOS_COLS_KEY = 'photos-grid-cols'
        const PHOTOS_KIND_KEY = 'photos-media-kind'
        const PHOTOS_COLS_DEFAULT = 4
        const PHOTOS_PAGE_SIZE = 50
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || ''
        const photosReturnTo = @json($returnTo);
        const selectActions = document.getElementById('photos-select-actions')
        const pagerEl = document.getElementById('photos-pager')
        const pagerPrev = document.getElementById('photos-pager-prev')
        const pagerNext = document.getElementById('photos-pager-next')
        const pagerStatus = document.getElementById('photos-pager-status')
        let photosPage = 1
        let photosMediaKind = 'all'

        function photoChecks() {
          return Array.from(document.querySelectorAll('.photo-check'))
        }
        function photoTileWraps() {
          return Array.from(document.querySelectorAll('#photos-gallery .photos-tile-wrap'))
        }
        function selectedPhotoIds() {
          return photoChecks().filter((cb) => cb.checked).map((cb) => cb.value)
        }
        function currentPhotosMode() {
          return gallery?.dataset.photosMode || 'normal'
        }
        function clampCols(value) {
          return Math.max(1, Math.min(7, Math.round(Number(value) || PHOTOS_COLS_DEFAULT)))
        }
        function wrapMatchesMediaKind(wrap) {
          if (photosMediaKind === 'all') return true
          return (wrap.dataset.mediaKind || 'image') === photosMediaKind
        }
        function filteredPhotoIndexes() {
          if (!Array.isArray(photos) || !photos.length) return []
          if (photosMediaKind === 'all') return photos.map((_, i) => i)
          return photos
            .map((photo, index) => ({ photo, index }))
            .filter(({ photo }) => {
              const kind = photo.mediaKind === 'video' ? 'video' : 'image'
              return kind === photosMediaKind
            })
            .map(({ index }) => index)
        }
        function setPhotosMediaKind(kind, { persist = true } = {}) {
          const next = (kind === 'image' || kind === 'video') ? kind : 'all'
          photosMediaKind = next
          if (gallery) gallery.dataset.photosKind = next
          document.querySelectorAll('.photos-mode-btn[data-photos-kind]').forEach((btn) => {
            const active = btn.dataset.photosKind === next
            btn.classList.toggle('is-active', active)
            btn.setAttribute('aria-pressed', active ? 'true' : 'false')
          })
          if (persist) {
            try { localStorage.setItem(PHOTOS_KIND_KEY, next) } catch (_) {}
          }
          setPhotosPage(1)
        }
        function setPhotosPage(page, { scroll = false } = {}) {
          const wraps = photoTileWraps()
          const kindMatched = wraps.filter(wrapMatchesMediaKind)
          const mode = currentPhotosMode()
          const pagingOn = mode === 'list'
          const total = kindMatched.length
          const pageCount = Math.max(1, Math.ceil(total / PHOTOS_PAGE_SIZE) || 1)
          photosPage = Math.max(1, Math.min(pageCount, Math.round(Number(page) || 1)))
          const start = pagingOn ? (photosPage - 1) * PHOTOS_PAGE_SIZE : 0
          const end = pagingOn ? Math.min(start + PHOTOS_PAGE_SIZE, total) : total
          const visibleSet = new Set(kindMatched.slice(start, end))

          wraps.forEach((wrap) => {
            const hide = !visibleSet.has(wrap)
            wrap.hidden = hide
            wrap.classList.toggle('is-page-hidden', hide)
            wrap.classList.toggle('is-kind-hidden', !wrapMatchesMediaKind(wrap))
            if (hide) {
              wrap.style.setProperty('display', 'none', 'important')
            } else {
              wrap.style.removeProperty('display')
            }
          })

          document.querySelectorAll('#photos-gallery .photos-day-group').forEach((group) => {
            const groupWraps = Array.from(group.querySelectorAll('.photos-tile-wrap'))
            const matched = groupWraps.filter(wrapMatchesMediaKind)
            const visibleCount = groupWraps.filter((wrap) => visibleSet.has(wrap)).length
            const hideGroup = matched.length === 0 || (pagingOn && visibleCount === 0)
            group.hidden = hideGroup
            group.classList.toggle('is-page-hidden', hideGroup)
            if (hideGroup) {
              group.style.setProperty('display', 'none', 'important')
            } else {
              group.style.removeProperty('display')
            }
            const countEl = group.querySelector('.photos-day-count')
            if (countEl) {
              const count = pagingOn ? visibleCount : matched.length
              countEl.textContent = @json(__(':count枚')).replace(':count', String(count))
            }
          })

          if (pagerStatus) {
            if (!pagingOn) {
              pagerStatus.textContent = ''
            } else if (total === 0) {
              pagerStatus.textContent = @json(__('0件'));
            } else {
              pagerStatus.textContent = `${start + 1}–${end} / ${total}`
            }
          }
          if (pagerPrev) pagerPrev.disabled = !pagingOn || photosPage <= 1
          if (pagerNext) pagerNext.disabled = !pagingOn || photosPage >= pageCount
          if (pagerEl) pagerEl.hidden = !pagingOn || total <= PHOTOS_PAGE_SIZE
          if (scroll && pagingOn && gallery) {
            gallery.scrollIntoView({ behavior: 'smooth', block: 'start' })
          }
        }
        function setPhotosCols(value, { persist = true } = {}) {
          const cols = clampCols(value)
          if (gallery) {
            gallery.dataset.cols = String(cols)
            gallery.style.setProperty('--photos-cols', String(cols))
            gallery.querySelectorAll('.photos-masonry').forEach((el) => {
              el.style.columnCount = String(cols)
            })
          }
          if (colsSlider && Number(colsSlider.value) !== cols) {
            colsSlider.value = String(cols)
          }
          if (colsValue) colsValue.textContent = String(cols)
          if (colsControl) {
            const fill = ((cols - 1) / 6) * 100
            colsControl.style.setProperty('--cols-fill', `${fill}%`)
          }
          if (persist) {
            try { localStorage.setItem(PHOTOS_COLS_KEY, String(cols)) } catch (_) {}
          }
        }
        function updatePhotosBulkUi() {
          const mode = currentPhotosMode()
          const ids = selectedPhotoIds()
          if (bulkCount) bulkCount.textContent = @json(__(':count件選択')).replace(':count', String(ids.length));
          if (bulkBar) bulkBar.hidden = mode === 'normal' || ids.length === 0
          if (bulkMoveWrap) bulkMoveWrap.hidden = mode !== 'list'
          if (colsControl) colsControl.hidden = mode === 'list'
          if (selectActions) selectActions.hidden = mode === 'normal'
        }
        function setPhotosMode(mode) {
          const next = ['normal', 'select', 'list'].includes(mode) ? mode : 'normal'
          if (gallery) gallery.dataset.photosMode = next
          document.querySelectorAll('.photos-mode-btn[data-photos-mode]').forEach((btn) => {
            const active = btn.dataset.photosMode === next
            btn.classList.toggle('is-active', active)
            btn.setAttribute('aria-pressed', active ? 'true' : 'false')
          })
          if (next === 'normal') {
            photoChecks().forEach((cb) => { cb.checked = false })
          }
          try { localStorage.setItem(PHOTOS_MODE_KEY, next) } catch (_) {}
          if (next !== 'list') {
            setPhotosCols(colsSlider?.value || gallery?.dataset.cols || PHOTOS_COLS_DEFAULT, { persist: false })
          }
          setPhotosPage(next === 'list' ? 1 : photosPage)
          updatePhotosBulkUi()
        }
        document.querySelectorAll('.photos-mode-btn[data-photos-mode]').forEach((btn) => {
          btn.addEventListener('click', () => setPhotosMode(btn.dataset.photosMode))
        })
        document.querySelectorAll('.photos-mode-btn[data-photos-kind]').forEach((btn) => {
          btn.addEventListener('click', () => setPhotosMediaKind(btn.dataset.photosKind))
        })
        colsSlider?.addEventListener('input', () => setPhotosCols(colsSlider.value))
        document.getElementById('photos-select-all')?.addEventListener('click', () => {
          photoTileWraps().forEach((wrap) => {
            if (wrap.hidden || wrap.classList.contains('is-page-hidden')) return
            const check = wrap.querySelector('.photo-check')
            if (check) check.checked = true
          })
          updatePhotosBulkUi()
        })
        document.getElementById('photos-select-none')?.addEventListener('click', () => {
          photoChecks().forEach((cb) => { cb.checked = false })
          updatePhotosBulkUi()
        })
        pagerPrev?.addEventListener('click', () => setPhotosPage(photosPage - 1, { scroll: true }))
        pagerNext?.addEventListener('click', () => setPhotosPage(photosPage + 1, { scroll: true }))
        try {
          const savedCols = localStorage.getItem(PHOTOS_COLS_KEY)
          setPhotosCols(savedCols || PHOTOS_COLS_DEFAULT, { persist: false })
        } catch (_) {
          setPhotosCols(PHOTOS_COLS_DEFAULT, { persist: false })
        }
        try {
          const savedMode = localStorage.getItem(PHOTOS_MODE_KEY)
          setPhotosMode(savedMode || 'normal')
        } catch (_) {
          setPhotosMode('normal')
        }
        try {
          const savedKind = localStorage.getItem(PHOTOS_KIND_KEY)
          setPhotosMediaKind(savedKind || 'all', { persist: false })
        } catch (_) {
          setPhotosMediaKind('all', { persist: false })
        }
        setPhotosPage(1)
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
        document.getElementById('photos-lightbox-prev')?.addEventListener('click', () => stepLightbox(-1))
        document.getElementById('photos-lightbox-next')?.addEventListener('click', () => stepLightbox(1))

        async function fetchCurrentMediaFile() {
          const photo = photos[currentIndex]
          if (!photo) throw new Error('photo')
          const url = photo.fileUrl || (`/photos/${photo.id}/file`)
          const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
          })
          if (!res.ok) throw new Error('fetch ' + res.status)
          const blob = await res.blob()
          const mime = blob.type || photo.mime || (photo.mediaKind === 'video' ? 'video/mp4' : 'image/jpeg')
          let ext = 'jpg'
          if (mime.includes('mp4') || mime.includes('video')) ext = 'mp4'
          else if (mime.includes('png')) ext = 'png'
          else if (mime.includes('webp')) ext = 'webp'
          else if (mime.includes('gif')) ext = 'gif'
          else if (mime.includes('heic')) ext = 'heic'
          const base = String(photo.originalName || `media-${photo.id}`).replace(/\.[^.]+$/, '')
          return new File([blob], `${base}.${ext}`, { type: mime, lastModified: Date.now() })
        }

        function downloadFile(file) {
          const objectUrl = URL.createObjectURL(file)
          const a = document.createElement('a')
          a.href = objectUrl
          a.download = file.name
          a.rel = 'noopener'
          document.body.appendChild(a)
          a.click()
          a.remove()
          setTimeout(() => URL.revokeObjectURL(objectUrl), 2000)
        }

        async function shareCurrentMedia({ messengerHint = false } = {}) {
          const photo = photos[currentIndex]
          if (!photo) return
          const shareBtn = document.getElementById('photos-share-btn')
          const messengerBtn = document.getElementById('photos-share-messenger-btn')
          const busyBtns = [shareBtn, messengerBtn].filter(Boolean)
          busyBtns.forEach((btn) => { btn.disabled = true })
          try {
            const file = await fetchCurrentMediaFile()
            const payload = {
              files: [file],
              title: photo.caption || photo.originalName || @json(__('写真')),
              text: messengerHint
                ? @json(__('Messenger を選んで送信してください。'))
                : (photo.caption || photo.originalName || ''),
            }
            const canShareFiles = typeof navigator.canShare !== 'function'
              || navigator.canShare({ files: [file] })
            if (typeof navigator.share === 'function' && canShareFiles) {
              try {
                await navigator.share(payload)
                return
              } catch (err) {
                if (err && (err.name === 'AbortError' || err.name === 'NotAllowedError')) return
              }
            }
            downloadFile(file)
            window.alert(
              messengerHint
                ? @json(__('共有シートが使えないためダウンロードしました。Messenger を開き、ダウンロードしたファイルを添付して送信してください。'))
                : @json(__('共有に対応していないため、ファイルをダウンロードしました。'))
            )
          } catch (_) {
            window.alert(@json(__('共有の準備に失敗しました。')))
          } finally {
            busyBtns.forEach((btn) => { btn.disabled = false })
          }
        }

        document.getElementById('photos-share-btn')?.addEventListener('click', () => {
          shareCurrentMedia({ messengerHint: false })
        })
        document.getElementById('photos-share-messenger-btn')?.addEventListener('click', () => {
          shareCurrentMedia({ messengerHint: true })
        })

        const slideshow = document.getElementById('photos-slideshow')
        const ssLayerA = document.getElementById('photos-ss-layer-a')
        const ssLayerB = document.getElementById('photos-ss-layer-b')
        const ssCaption = document.getElementById('photos-ss-caption')
        const ssCounter = document.getElementById('photos-ss-counter')
        const ssPlayBtn = document.getElementById('photos-ss-play')
        const ssInterval = document.getElementById('photos-ss-interval')
        const ssEffect = document.getElementById('photos-ss-effect')
        const ssImagesOnly = document.getElementById('photos-ss-images-only')
        const ssProgress = document.getElementById('photos-ss-progress')?.querySelector('span')
        const ssAudio = document.getElementById('photos-ss-audio')
        const ssMusicFile = document.getElementById('photos-ss-music-file')
        const ssMusicName = document.getElementById('photos-ss-music-name')
        const ssMusicClear = document.getElementById('photos-ss-music-clear')
        const ssMusicVolume = document.getElementById('photos-ss-music-volume')
        const ssMusicLoop = document.getElementById('photos-ss-music-loop')
        const ssMusicMute = document.getElementById('photos-ss-music-mute')
        const SS_EFFECT_KEY = 'photos-ss-effect'
        const SS_INTERVAL_KEY = 'photos-ss-interval'
        const SS_IMAGES_ONLY_KEY = 'photos-ss-images-only'
        const SS_MUSIC_VOLUME_KEY = 'photos-ss-music-volume'
        const SS_EFFECTS = [
          'fade', 'slide-left', 'slide-right', 'slide-up', 'slide-down',
          'zoom-in', 'zoom-out', 'blur', 'wipe-left', 'wipe-right',
          'flip', 'rotate', 'cube', 'kenburns',
        ]
        const ssState = {
          index: 0,
          queue: [],
          playing: false,
          transitioning: false,
          activeLayer: 'a',
          timer: null,
          progressTimer: null,
          progressStartedAt: 0,
          musicObjectUrl: null,
        }

        function applySsMusicSettings() {
          if (!ssAudio) return
          const vol = Math.max(0, Math.min(100, Number(ssMusicVolume?.value || 60))) / 100
          const muted = !!ssMusicMute?.checked
          ssAudio.volume = vol
          ssAudio.muted = muted
          ssAudio.loop = !!ssMusicLoop?.checked
        }

        function syncSsMusicPlayback() {
          if (!ssAudio) return
          applySsMusicSettings()
          if (!ssAudio.src) return
          if (ssState.playing && slideshow && !slideshow.hidden) {
            ssAudio.play().catch(() => {})
          } else {
            ssAudio.pause()
          }
        }

        function clearSsMusic() {
          if (!ssAudio) return
          ssAudio.pause()
          ssAudio.removeAttribute('src')
          ssAudio.load()
          if (ssState.musicObjectUrl) {
            URL.revokeObjectURL(ssState.musicObjectUrl)
            ssState.musicObjectUrl = null
          }
          if (ssMusicFile) ssMusicFile.value = ''
          if (ssMusicName) {
            ssMusicName.textContent = ''
            ssMusicName.hidden = true
          }
          if (ssMusicClear) ssMusicClear.hidden = true
        }

        function setSsMusicFile(file) {
          if (!ssAudio || !file) return
          if (ssState.musicObjectUrl) {
            URL.revokeObjectURL(ssState.musicObjectUrl)
            ssState.musicObjectUrl = null
          }
          ssState.musicObjectUrl = URL.createObjectURL(file)
          ssAudio.src = ssState.musicObjectUrl
          applySsMusicSettings()
          if (ssMusicName) {
            ssMusicName.textContent = file.name
            ssMusicName.hidden = false
          }
          if (ssMusicClear) ssMusicClear.hidden = false
          syncSsMusicPlayback()
        }

        function ssQueue() {
          const imagesOnly = !!ssImagesOnly?.checked
          return photos
            .map((photo, index) => ({ photo, index }))
            .filter(({ photo }) => {
              if (imagesOnly && photo.mediaKind === 'video') return false
              if (photosMediaKind === 'image' && photo.mediaKind === 'video') return false
              if (photosMediaKind === 'video' && photo.mediaKind !== 'video') return false
              return true
            })
        }

        function stepLightbox(delta) {
          const idxs = filteredPhotoIndexes()
          if (!idxs.length) return
          let pos = idxs.indexOf(currentIndex)
          if (pos < 0) pos = 0
          const nextPos = (pos + delta + idxs.length) % idxs.length
          openLightbox(idxs[nextPos])
        }

        function stopSsTimers() {
          if (ssState.timer) {
            clearTimeout(ssState.timer)
            ssState.timer = null
          }
          if (ssState.progressTimer) {
            cancelAnimationFrame(ssState.progressTimer)
            ssState.progressTimer = null
          }
          if (ssProgress) ssProgress.style.width = '0%'
        }

        function setSsPlaying(playing) {
          ssState.playing = !!playing
          if (ssPlayBtn) {
            ssPlayBtn.setAttribute('aria-pressed', ssState.playing ? 'true' : 'false')
            ssPlayBtn.textContent = ssState.playing
              ? @json(__('一時停止'))
              : @json(__('自動再生'));
            ssPlayBtn.classList.toggle('is-playing', ssState.playing)
          }
          if (!ssState.playing) stopSsTimers()
          else scheduleSsNext()
          syncSsMusicPlayback()
        }

        function pickSsEffect() {
          const selected = ssEffect?.value || 'fade'
          if (selected !== 'random') return selected
          return SS_EFFECTS[Math.floor(Math.random() * SS_EFFECTS.length)]
        }

        function fillSsLayer(layer, photo) {
          if (!layer || !photo) return
          const img = layer.querySelector('img')
          const video = layer.querySelector('video')
          const isVideo = photo.mediaKind === 'video'
          if (video) {
            video.pause()
            video.removeAttribute('src')
            video.load()
            video.hidden = true
            // BGM があるときは動画の音声を出さない
            video.muted = !!(ssAudio && ssAudio.src)
          }
          if (img) {
            img.removeAttribute('src')
            img.hidden = true
          }
          if (isVideo && video) {
            video.hidden = false
            video.src = photo.url || photo.fileUrl || (`/photos/${photo.id}/file`)
          } else if (img) {
            img.hidden = false
            img.src = photo.url || photo.fileUrl || (`/photos/${photo.id}/file`)
            img.alt = photo.caption || photo.originalName || @json(__('写真'))
          }
        }

        function updateSsMeta() {
          const item = ssState.queue[ssState.index]
          if (!item) return
          const photo = item.photo
          if (ssCaption) {
            ssCaption.textContent = photo.caption || photo.originalName || ''
          }
          if (ssCounter) {
            ssCounter.textContent = `${ssState.index + 1} / ${ssState.queue.length}`
          }
        }

        function scheduleSsNext() {
          stopSsTimers()
          if (!ssState.playing || !slideshow || slideshow.hidden) return
          const item = ssState.queue[ssState.index]
          const photo = item?.photo
          const interval = Math.max(1500, Number(ssInterval?.value || 3000))
          if (photo?.mediaKind === 'video') {
            const layer = ssState.activeLayer === 'a' ? ssLayerA : ssLayerB
            const video = layer?.querySelector('video')
            if (video && !video.hidden) {
              const onEnded = () => {
                video.removeEventListener('ended', onEnded)
                if (ssState.playing) showSsSlide(ssState.index + 1, 1)
              }
              video.addEventListener('ended', onEnded)
              video.play().catch(() => {
                ssState.timer = setTimeout(() => showSsSlide(ssState.index + 1, 1), interval)
              })
              return
            }
          }
          ssState.progressStartedAt = performance.now()
          const tick = (now) => {
            if (!ssState.playing) return
            const ratio = Math.min(1, (now - ssState.progressStartedAt) / interval)
            if (ssProgress) ssProgress.style.width = `${ratio * 100}%`
            if (ratio >= 1) {
              showSsSlide(ssState.index + 1, 1)
              return
            }
            ssState.progressTimer = requestAnimationFrame(tick)
          }
          ssState.progressTimer = requestAnimationFrame(tick)
        }

        function showSsSlide(queueIndex, direction = 1) {
          if (!ssState.queue.length || ssState.transitioning) return
          const nextIndex = (queueIndex + ssState.queue.length) % ssState.queue.length
          const item = ssState.queue[nextIndex]
          if (!item) return
          const incoming = ssState.activeLayer === 'a' ? ssLayerB : ssLayerA
          const outgoing = ssState.activeLayer === 'a' ? ssLayerA : ssLayerB
          const effect = pickSsEffect()
          fillSsLayer(incoming, item.photo)
          ssState.transitioning = true
          incoming.className = `photos-ss-layer is-incoming effect-${effect} dir-${direction >= 0 ? 'fwd' : 'back'}`
          outgoing.className = `photos-ss-layer is-outgoing effect-${effect} dir-${direction >= 0 ? 'fwd' : 'back'}`
          // force reflow
          void incoming.offsetWidth
          incoming.classList.add('is-active', 'is-in')
          outgoing.classList.add('is-out')
          const finish = () => {
            outgoing.className = 'photos-ss-layer'
            const outImg = outgoing.querySelector('img')
            const outVideo = outgoing.querySelector('video')
            if (outVideo) {
              outVideo.pause()
              outVideo.removeAttribute('src')
              outVideo.load()
              outVideo.hidden = true
            }
            if (outImg) {
              outImg.removeAttribute('src')
              outImg.hidden = true
            }
            incoming.className = 'photos-ss-layer is-active'
            ssState.activeLayer = incoming.dataset.layer
            ssState.index = nextIndex
            currentIndex = item.index
            ssState.transitioning = false
            updateSsMeta()
            if (ssState.playing) scheduleSsNext()
          }
          window.setTimeout(finish, effect === 'kenburns' ? 900 : 700)
        }

        function openSlideshow(startPhotoIndex = 0) {
          ssState.queue = ssQueue()
          if (!ssState.queue.length) {
            window.alert(@json(__('スライドショーで表示できる写真がありません。')))
            return
          }
          let start = ssState.queue.findIndex(({ index }) => index === startPhotoIndex)
          if (start < 0) start = 0
          ssState.index = start
          ssState.activeLayer = 'a'
          ssState.transitioning = false
          fillSsLayer(ssLayerA, ssState.queue[start].photo)
          if (ssLayerA) ssLayerA.className = 'photos-ss-layer is-active'
          if (ssLayerB) {
            ssLayerB.className = 'photos-ss-layer'
            const img = ssLayerB.querySelector('img')
            const video = ssLayerB.querySelector('video')
            if (img) { img.hidden = true; img.removeAttribute('src') }
            if (video) { video.hidden = true; video.removeAttribute('src'); video.load() }
          }
          updateSsMeta()
          closeLightbox({ skipHistory: true })
          setSsChromeHidden(false)
          if (slideshow) slideshow.hidden = false
          document.body.style.overflow = 'hidden'
          claimPhotosOverlay('slideshow')
          setSsPlaying(true)
        }

        function closeSlideshow(opts = {}) {
          setSsPlaying(false)
          stopSsTimers()
          if (ssAudio) ssAudio.pause()
          exitPhotosFullscreen(slideshow)
          if (slideshow) {
            slideshow.classList.remove('is-fullscreen')
            slideshow.dataset.wasFullscreen = '0'
          }
          ;[ssLayerA, ssLayerB].forEach((layer) => {
            if (!layer) return
            const video = layer.querySelector('video')
            const img = layer.querySelector('img')
            if (video) {
              video.pause()
              video.removeAttribute('src')
              video.load()
              video.hidden = true
            }
            if (img) {
              img.removeAttribute('src')
              img.hidden = true
            }
            layer.className = 'photos-ss-layer'
          })
          if (slideshow) slideshow.hidden = true
          document.body.style.overflow = ''
          releasePhotosOverlay('slideshow', opts)
        }

        function setSsChromeHidden(hidden) {
          if (!slideshow) return
          const hide = !!hidden
          slideshow.classList.toggle('is-chrome-hidden', hide)
          const toggleBtn = document.getElementById('photos-ss-chrome-toggle')
          const peekBtn = document.getElementById('photos-ss-chrome-peek')
          if (toggleBtn) {
            toggleBtn.setAttribute('aria-pressed', hide ? 'true' : 'false')
            toggleBtn.textContent = hide
              ? @json(__('フッターを表示'))
              : @json(__('フッターを隠す'));
          }
          if (peekBtn) {
            const inFullscreen = slideshow.classList.contains('is-fullscreen')
              || photosFullscreenElement() === slideshow
            peekBtn.hidden = !hide || inFullscreen
          }
        }

        function syncSlideshowFullscreenLayout() {
          if (!slideshow) return
          const isFs = photosFullscreenElement() === slideshow
          const wasFs = slideshow.dataset.wasFullscreen === '1'
          if (isFs && !wasFs) {
            slideshow.dataset.chromeBeforeFs = slideshow.classList.contains('is-chrome-hidden') ? '1' : '0'
            setSsChromeHidden(true)
            slideshow.dataset.wasFullscreen = '1'
          } else if (!isFs && wasFs) {
            if (photosFsExitFromUi) photosFsExitFromUi = false
            else photosFsEatNextEscClose = true
            setSsChromeHidden(slideshow.dataset.chromeBeforeFs === '1')
            slideshow.dataset.wasFullscreen = '0'
          }
          slideshow.classList.toggle('is-fullscreen', isFs)
        }

        document.addEventListener('fullscreenchange', () => {
          syncSlideshowFullscreenLayout()
          syncPhotosFullscreenButtons()
        })
        document.addEventListener('webkitfullscreenchange', () => {
          syncSlideshowFullscreenLayout()
          syncPhotosFullscreenButtons()
        })

        try {
          const savedEffect = localStorage.getItem(SS_EFFECT_KEY)
          const savedInterval = localStorage.getItem(SS_INTERVAL_KEY)
          const savedImagesOnly = localStorage.getItem(SS_IMAGES_ONLY_KEY)
          const savedVolume = localStorage.getItem(SS_MUSIC_VOLUME_KEY)
          if (ssEffect && savedEffect) ssEffect.value = savedEffect
          if (ssInterval && savedInterval) ssInterval.value = savedInterval
          if (ssImagesOnly && savedImagesOnly !== null) ssImagesOnly.checked = savedImagesOnly === '1'
          if (ssMusicVolume && savedVolume !== null) ssMusicVolume.value = savedVolume
        } catch (_) {}
        applySsMusicSettings()
        setSsChromeHidden(false)

        document.getElementById('photos-slideshow-open')?.addEventListener('click', () => openSlideshow(0))
        document.getElementById('photos-lightbox-slideshow')?.addEventListener('click', () => openSlideshow(currentIndex))
        document.getElementById('photos-ss-close')?.addEventListener('click', closeSlideshow)
        document.getElementById('photos-ss-prev')?.addEventListener('click', () => {
          setSsPlaying(false)
          showSsSlide(ssState.index - 1, -1)
        })
        document.getElementById('photos-ss-next')?.addEventListener('click', () => {
          setSsPlaying(false)
          showSsSlide(ssState.index + 1, 1)
        })
        ssPlayBtn?.addEventListener('click', () => setSsPlaying(!ssState.playing))
        document.getElementById('photos-ss-chrome-toggle')?.addEventListener('click', () => {
          setSsChromeHidden(!slideshow?.classList.contains('is-chrome-hidden'))
        })
        document.getElementById('photos-ss-chrome-peek')?.addEventListener('click', () => setSsChromeHidden(false))
        document.getElementById('photos-ss-fs')?.addEventListener('click', () => togglePhotosFullscreen(slideshow))
        document.getElementById('photos-ss-fs-action')?.addEventListener('click', () => togglePhotosFullscreen(slideshow))
        ssInterval?.addEventListener('change', () => {
          try { localStorage.setItem(SS_INTERVAL_KEY, ssInterval.value) } catch (_) {}
          if (ssState.playing) scheduleSsNext()
        })
        ssEffect?.addEventListener('change', () => {
          try { localStorage.setItem(SS_EFFECT_KEY, ssEffect.value) } catch (_) {}
        })
        ssImagesOnly?.addEventListener('change', () => {
          try { localStorage.setItem(SS_IMAGES_ONLY_KEY, ssImagesOnly.checked ? '1' : '0') } catch (_) {}
          if (!slideshow?.hidden) {
            const currentPhotoIndex = ssState.queue[ssState.index]?.index ?? 0
            openSlideshow(currentPhotoIndex)
          }
        })
        ssMusicFile?.addEventListener('change', () => {
          const file = ssMusicFile.files?.[0]
          if (file) setSsMusicFile(file)
        })
        ssMusicClear?.addEventListener('click', () => {
          clearSsMusic()
        })
        ssMusicVolume?.addEventListener('input', () => {
          try { localStorage.setItem(SS_MUSIC_VOLUME_KEY, ssMusicVolume.value) } catch (_) {}
          applySsMusicSettings()
        })
        ssMusicLoop?.addEventListener('change', applySsMusicSettings)
        ssMusicMute?.addEventListener('change', applySsMusicSettings)

        function handlePhotosEscapeForOverlay(overlayEl, { onExitFullscreen, onClose }) {
          // ネイティブ全画面中 → 解除のみ（写真プレビューは開いたまま）
          if (overlayEl && photosFullscreenElement() === overlayEl) {
            photosFsExitFromUi = true
            exitPhotosFullscreen(overlayEl)
            return
          }
          // ブラウザが先に全画面を解除した直後（クラス残留 or 同じ Esc の後続）
          const uiStillFs = !!(overlayEl && (
            overlayEl.classList.contains('is-fullscreen')
            || overlayEl.dataset.wasFullscreen === '1'
          ))
          if (uiStillFs || photosFsEatNextEscClose) {
            photosFsEatNextEscClose = false
            photosFsExitFromUi = true
            if (overlayEl) overlayEl.classList.remove('is-fullscreen')
            if (typeof onExitFullscreen === 'function') onExitFullscreen()
            syncPhotosFullscreenButtons()
            return
          }
          if (typeof onClose === 'function') onClose()
        }

        document.addEventListener('keydown', (e) => {
          const isEsc = e.key === 'Escape' || e.key === 'Esc'
          if (slideshow && !slideshow.hidden) {
            if (isEsc) {
              e.preventDefault()
              e.stopPropagation()
              e.stopImmediatePropagation()
              handlePhotosEscapeForOverlay(slideshow, {
                onExitFullscreen: () => {
                  if (slideshow.dataset.wasFullscreen === '1') {
                    setSsChromeHidden(slideshow.dataset.chromeBeforeFs === '1')
                    slideshow.dataset.wasFullscreen = '0'
                  }
                },
                onClose: () => {
                  if (slideshow.classList.contains('is-chrome-hidden')) {
                    setSsChromeHidden(false)
                    return
                  }
                  closeSlideshow()
                },
              })
              return
            }
            if (e.key === 'f' || e.key === 'F') {
              e.preventDefault()
              togglePhotosFullscreen(slideshow)
            }
            if (e.key === 'h' || e.key === 'H') {
              e.preventDefault()
              setSsChromeHidden(!slideshow.classList.contains('is-chrome-hidden'))
            }
            if (e.key === 'ArrowLeft') {
              setSsPlaying(false)
              showSsSlide(ssState.index - 1, -1)
            }
            if (e.key === 'ArrowRight') {
              setSsPlaying(false)
              showSsSlide(ssState.index + 1, 1)
            }
            if (e.key === ' ' || e.key === 'Spacebar') {
              e.preventDefault()
              setSsPlaying(!ssState.playing)
            }
            return
          }
          if (lightbox?.hidden) return
          if (isEsc) {
            e.preventDefault()
            e.stopPropagation()
            e.stopImmediatePropagation()
            handlePhotosEscapeForOverlay(lightbox, {
              onClose: () => {
                if (lightboxEditMode) {
                  setLightboxEditMode(false)
                  return
                }
                closeLightbox()
              },
            })
            return
          }
          if (e.key === 'f' || e.key === 'F') {
            e.preventDefault()
            togglePhotosFullscreen(lightbox)
          }
          if (e.key === 'ArrowLeft') stepLightbox(-1)
          if (e.key === 'ArrowRight') stepLightbox(1)
          if (e.key === '+' || e.key === '=') setLightboxZoom(lightboxZoom + 0.25)
          if (e.key === '-') setLightboxZoom(lightboxZoom - 0.25)
        }, true)

        const albumForm = document.getElementById('photos-album-form')
        const albumModalTitle = document.getElementById('photos-album-modal-title')
        const albumNameInput = document.getElementById('photos-album-name')
        const albumDescInput = document.getElementById('photos-album-description')
        const albumVisibility = document.getElementById('photos-album-visibility')
        const albumGroupWrap = document.getElementById('photos-album-group-wrap')
        const albumGroupId = document.getElementById('photos-album-group-id')
        const albumSubmit = document.getElementById('photos-album-submit')
        const selectedAlbum = @json($selectedAlbum);

        function syncAlbumGroupVisibility() {
          if (!albumVisibility || !albumGroupWrap) return
          const showGroup = albumVisibility.value === 'group'
          albumGroupWrap.hidden = !showGroup
          if (albumGroupId) albumGroupId.required = showGroup
        }
        albumVisibility?.addEventListener('change', syncAlbumGroupVisibility)

        function openAlbumModal(mode) {
          if (!albumModal || !albumForm) return
          if (mode === 'edit' && selectedAlbum) {
            albumModalTitle.textContent = @json(__('アルバムを編集'));
            albumForm.action = `/photos/albums/${selectedAlbum.id}/update`
            albumNameInput.value = selectedAlbum.name || ''
            albumDescInput.value = selectedAlbum.description || ''
            if (albumVisibility) albumVisibility.value = selectedAlbum.visibility || 'private'
            if (albumGroupId) albumGroupId.value = selectedAlbum.groupId || ''
            albumSubmit.textContent = @json(__('保存'));
          } else {
            albumModalTitle.textContent = @json(__('アルバムを作成'));
            albumForm.action = '/photos/albums'
            albumNameInput.value = ''
            albumDescInput.value = ''
            if (albumVisibility) albumVisibility.value = 'private'
            if (albumGroupId) albumGroupId.value = ''
            albumSubmit.textContent = @json(__('作成'));
          }
          syncAlbumGroupVisibility()
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

        if (usageOpenBtn && usageModal) {
          usageOpenBtn.addEventListener('click', () => {
            usageModal.hidden = false
          })
        }
        document.querySelectorAll('[data-close-usage-modal]').forEach((el) => {
          el.addEventListener('click', () => {
            if (usageModal) usageModal.hidden = true
          })
        })

        const dupModal = document.getElementById('photos-dup-modal')
        const dupList = document.getElementById('photos-dup-list')
        const dupStatus = document.getElementById('photos-dup-status')
        const dupLead = document.getElementById('photos-dup-lead')
        let dupScanBusy = false
        let dupNeedsReload = false

        function setDupStatus(text) {
          if (dupStatus) dupStatus.textContent = text || ''
        }

        function closeDupModal() {
          if (dupModal) dupModal.hidden = true
          if (dupNeedsReload) {
            window.location.reload()
          }
        }

        function openDupModal() {
          if (!dupModal) return
          dupModal.hidden = false
          runDupScan()
        }

        function escapeHtml(value) {
          return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
        }

        function renderDupGroups(groups) {
          if (!dupList) return
          const selectExtrasBtn = document.getElementById('photos-dup-select-extras')
          const selectNoneBtn = document.getElementById('photos-dup-select-none')
          const bulkDeleteBtn = document.getElementById('photos-dup-bulk-delete')
          if (!groups.length) {
            dupList.innerHTML = `<p class="photos-dup-empty">${escapeHtml(@json(__('重複は見つかりませんでした。')))}</p>`
            if (dupLead) dupLead.textContent = @json(__('同じ内容の保存済みメディアはありません。'));
            if (selectExtrasBtn) selectExtrasBtn.hidden = true
            if (selectNoneBtn) selectNoneBtn.hidden = true
            if (bulkDeleteBtn) bulkDeleteBtn.hidden = true
            updateDupSelectionUi()
            return
          }
          if (dupLead) {
            dupLead.textContent = @json(__(':groups組（計 :items件）の重複があります。残すものを決めて、名前変更や削除ができます。'))
              .replace(':groups', String(groups.length))
              .replace(':items', String(groups.reduce((n, g) => n + (g.photos?.length || 0), 0)));
          }
          if (selectExtrasBtn) selectExtrasBtn.hidden = false
          if (selectNoneBtn) selectNoneBtn.hidden = false
          if (bulkDeleteBtn) bulkDeleteBtn.hidden = false
          dupList.innerHTML = groups.map((group, gi) => {
            const photosHtml = (group.photos || []).map((photo, pi) => {
              const isVideo = photo.mediaKind === 'video'
              const name = photo.originalName || photo.caption || ('#' + photo.id)
              const thumbSrc = photo.thumbUrl || photo.url || ''
              const media = `
                <img class="photos-dup-thumb" src="${escapeHtml(thumbSrc)}" alt="" loading="lazy" decoding="async" />
                ${isVideo ? '<span class="photos-dup-play" aria-hidden="true">▶</span>' : ''}`
              return `
                <article class="photos-dup-item" data-photo-id="${photo.id}" data-group="${gi}" data-index="${pi}">
                  <label class="photos-dup-check">
                    <input type="checkbox" class="photos-dup-check-input" value="${photo.id}" aria-label="${escapeHtml(@json(__('選択')))}" />
                  </label>
                  <div class="photos-dup-media">${media}</div>
                  <div class="photos-dup-meta">
                    <label class="photos-dup-name-label">
                      <span>${escapeHtml(@json(__('ファイル名')))}</span>
                      <input type="text" class="photos-dup-name" value="${escapeHtml(name)}" maxlength="255" />
                    </label>
                    <div class="photos-dup-item-actions">
                      <button type="button" class="photos-secondary-btn photos-dup-rename">${escapeHtml(@json(__('名前を保存')))}</button>
                      <button type="button" class="photos-secondary-btn photos-danger-btn photos-dup-delete">${escapeHtml(@json(__('削除')))}</button>
                    </div>
                    <p class="photos-dup-item-sub">${isVideo ? escapeHtml(@json(__('動画'))) : escapeHtml(@json(__('写真')))} · ID ${photo.id}${photo.takenAt ? ' · ' + escapeHtml(photo.takenAt) : ''}</p>
                  </div>
                </article>`
            }).join('')
            return `
              <section class="photos-dup-group" data-group="${gi}">
                <header class="photos-dup-group-head">
                  <label class="photos-dup-group-select">
                    <input type="checkbox" class="photos-dup-group-check" data-group="${gi}" aria-label="${escapeHtml(@json(__('この組を選択')))}" />
                    <strong>${escapeHtml(@json(__('重複グループ')))} ${gi + 1}</strong>
                  </label>
                  <span>${group.photos?.length || 0}${escapeHtml(@json(__('件')))}</span>
                </header>
                <div class="photos-dup-group-items">${photosHtml}</div>
              </section>`
          }).join('')
          updateDupSelectionUi()
        }

        function dupSelectedIds() {
          return Array.from(dupList?.querySelectorAll('.photos-dup-check-input:checked') || [])
            .map((el) => el.value)
            .filter(Boolean)
        }

        function updateDupSelectionUi() {
          const count = dupSelectedIds().length
          const bulkDeleteBtn = document.getElementById('photos-dup-bulk-delete')
          if (bulkDeleteBtn) {
            bulkDeleteBtn.textContent = count > 0
              ? @json(__('選択を一括削除（:count）')).replace(':count', String(count))
              : @json(__('選択を一括削除'));
            bulkDeleteBtn.disabled = count === 0
          }
          // グループ見出しのチェック状態を同期
          dupList?.querySelectorAll('.photos-dup-group').forEach((group) => {
            const boxes = Array.from(group.querySelectorAll('.photos-dup-check-input'))
            const head = group.querySelector('.photos-dup-group-check')
            if (!head || !boxes.length) return
            const checked = boxes.filter((b) => b.checked).length
            head.checked = checked === boxes.length
            head.indeterminate = checked > 0 && checked < boxes.length
          })
        }

        function selectDupExtras() {
          // 各組の先頭（新しい方）を残し、2件目以降を選択
          dupList?.querySelectorAll('.photos-dup-group').forEach((group) => {
            const boxes = Array.from(group.querySelectorAll('.photos-dup-check-input'))
            boxes.forEach((box, index) => {
              box.checked = index > 0
            })
          })
          updateDupSelectionUi()
        }

        function clearDupSelection() {
          dupList?.querySelectorAll('.photos-dup-check-input').forEach((box) => {
            box.checked = false
          })
          updateDupSelectionUi()
        }

        async function bulkDeleteDupSelection() {
          const ids = dupSelectedIds()
          if (!ids.length) {
            window.alert(@json(__('対象が選択されていません')));
            return
          }
          if (!window.confirm(@json(__('選択した :count 件を削除しますか？')).replace(':count', String(ids.length)))) {
            return
          }
          const bulkDeleteBtn = document.getElementById('photos-dup-bulk-delete')
          if (bulkDeleteBtn) bulkDeleteBtn.disabled = true
          setDupStatus(@json(__('削除中…')));
          try {
            const fd = new FormData()
            ids.forEach((id) => fd.append('ids[]', id))
            const res = await postUploadFormDataWithRetry('/photos/bulk/delete', fd, {
              timeoutMs: 5 * 60 * 1000,
            }, 2)
            const deleted = Number(res.data?.count) || ids.length
            ids.forEach((id) => {
              dupList?.querySelector(`.photos-dup-item[data-photo-id="${id}"]`)?.remove()
            })
            dupList?.querySelectorAll('.photos-dup-group').forEach((group) => {
              const left = group.querySelectorAll('.photos-dup-item').length
              if (left <= 1) group.remove()
            })
            dupNeedsReload = true
            if (!dupList?.querySelector('.photos-dup-group')) {
              renderDupGroups([])
              setDupStatus(@json(__('重複なし')));
            } else {
              setDupStatus(@json(__(':count件削除しました')).replace(':count', String(deleted)));
              updateDupSelectionUi()
            }
          } catch (err) {
            window.alert(err?.message || @json(__('一括削除に失敗しました。')));
            setDupStatus('');
            updateDupSelectionUi()
          }
        }

        async function runDupScan() {
          if (dupScanBusy) return
          dupScanBusy = true
          setDupStatus(@json(__('スキャン中…')));
          if (dupList) dupList.innerHTML = `<p class="photos-dup-empty">${escapeHtml(@json(__('保存済みファイルを確認しています。件数が多いと時間がかかることがあります。')))}</p>`
          try {
            const fd = new FormData()
            if (selectedAlbumId) fd.append('album_id', String(selectedAlbumId))
            const res = await postUploadFormDataWithRetry('/photos/duplicates/scan', fd, {
              timeoutMs: 10 * 60 * 1000,
            }, 2)
            const groups = Array.isArray(res.data?.groups) ? res.data.groups : []
            renderDupGroups(groups)
            setDupStatus(groups.length
              ? @json(__(':count組の重複')).replace(':count', String(groups.length))
              : @json(__('重複なし')));
          } catch (err) {
            setDupStatus('');
            if (dupList) {
              dupList.innerHTML = `<p class="photos-dup-empty">${escapeHtml(err?.message || @json(__('スキャンに失敗しました。')))}</p>`
            }
          } finally {
            dupScanBusy = false
          }
        }

        document.getElementById('photos-dup-scan-open')?.addEventListener('click', openDupModal)
        document.getElementById('photos-dup-rescan')?.addEventListener('click', () => runDupScan())
        document.getElementById('photos-dup-select-extras')?.addEventListener('click', selectDupExtras)
        document.getElementById('photos-dup-select-none')?.addEventListener('click', clearDupSelection)
        document.getElementById('photos-dup-bulk-delete')?.addEventListener('click', () => bulkDeleteDupSelection())
        document.querySelectorAll('[data-close-dup-modal]').forEach((el) => {
          el.addEventListener('click', closeDupModal)
        })

        dupList?.addEventListener('change', (e) => {
          const groupCheck = e.target.closest?.('.photos-dup-group-check')
          if (groupCheck) {
            const group = groupCheck.closest('.photos-dup-group')
            group?.querySelectorAll('.photos-dup-check-input').forEach((box) => {
              box.checked = groupCheck.checked
            })
          }
          updateDupSelectionUi()
        })

        dupList?.addEventListener('click', async (e) => {
          if (e.target.closest?.('.photos-dup-check') || e.target.closest?.('.photos-dup-group-select')) {
            return
          }
          const renameBtn = e.target.closest?.('.photos-dup-rename')
          const deleteBtn = e.target.closest?.('.photos-dup-delete')
          const item = e.target.closest?.('.photos-dup-item')
          if (!item) return
          const photoId = item.dataset.photoId
          if (!photoId) return

          if (renameBtn) {
            const input = item.querySelector('.photos-dup-name')
            const name = (input?.value || '').trim()
            if (!name) {
              window.alert(@json(__('ファイル名を入力してください。')));
              return
            }
            renameBtn.disabled = true
            try {
              const fd = new FormData()
              fd.append('original_name', name)
              await postUploadFormDataWithRetry(`/photos/${photoId}/rename`, fd, { timeoutMs: 60 * 1000 }, 2)
              setDupStatus(@json(__('名前を保存しました')));
              dupNeedsReload = true
            } catch (err) {
              window.alert(err?.message || @json(__('名前の保存に失敗しました。')));
            } finally {
              renameBtn.disabled = false
            }
            return
          }

          if (deleteBtn) {
            if (!window.confirm(@json(__('このメディアを削除しますか？')))) return
            deleteBtn.disabled = true
            try {
              const fd = new FormData()
              await postUploadFormDataWithRetry(`/photos/${photoId}/delete`, fd, { timeoutMs: 120 * 1000 }, 2)
              const group = item.closest('.photos-dup-group')
              item.remove()
              dupNeedsReload = true
              const left = group?.querySelectorAll('.photos-dup-item')?.length || 0
              if (group && left <= 1) {
                group.remove()
              }
              if (!dupList.querySelector('.photos-dup-group')) {
                renderDupGroups([])
                setDupStatus(@json(__('重複なし')));
              } else {
                setDupStatus(@json(__('削除しました')));
                updateDupSelectionUi()
              }
            } catch (err) {
              window.alert(err?.message || @json(__('削除に失敗しました。')));
              deleteBtn.disabled = false
            }
          }
        })

        const cropModal = document.getElementById('photos-crop-modal')
        const cropStage = document.getElementById('photos-crop-stage')
        const cropCanvas = document.getElementById('photos-crop-canvas')
        const cropOverlay = document.getElementById('photos-crop-overlay')
        const cropBox = document.getElementById('photos-crop-box')
        const cropCtx = cropCanvas?.getContext('2d')
        const cropState = {
          photoId: null,
          source: null,
          rotation: 0,
          box: { x: 0, y: 0, w: 0, h: 0 },
          drag: null,
        }

        function closeCropModal() {
          if (cropModal) cropModal.hidden = true
          cropState.source = null
          cropState.drag = null
          if (cropOverlay) cropOverlay.hidden = true
        }

        function naturalSize() {
          const img = cropState.source
          if (!img) return { w: 0, h: 0 }
          const rotated = cropState.rotation % 180 !== 0
          return {
            w: rotated ? img.naturalHeight : img.naturalWidth,
            h: rotated ? img.naturalWidth : img.naturalHeight,
          }
        }

        function drawCropCanvas() {
          if (!cropCanvas || !cropCtx || !cropState.source || !cropStage) return
          const size = naturalSize()
          const maxW = Math.max(280, cropStage.clientWidth || 640)
          const maxH = Math.min(520, Math.floor(window.innerHeight * 0.55))
          const scale = Math.min(1, maxW / size.w, maxH / size.h)
          const cw = Math.max(1, Math.round(size.w * scale))
          const ch = Math.max(1, Math.round(size.h * scale))
          cropCanvas.width = cw
          cropCanvas.height = ch
          cropCtx.save()
          cropCtx.clearRect(0, 0, cw, ch)
          cropCtx.translate(cw / 2, ch / 2)
          cropCtx.rotate((cropState.rotation * Math.PI) / 180)
          const img = cropState.source
          const drawW = cropState.rotation % 180 !== 0 ? ch : cw
          const drawH = cropState.rotation % 180 !== 0 ? cw : ch
          cropCtx.drawImage(img, -drawW / 2, -drawH / 2, drawW, drawH)
          cropCtx.restore()
          syncCropOverlay()
        }

        function syncCropOverlay() {
          if (!cropOverlay || !cropBox || !cropCanvas) return
          cropOverlay.hidden = false
          cropOverlay.style.width = `${cropCanvas.width}px`
          cropOverlay.style.height = `${cropCanvas.height}px`
          const b = cropState.box
          cropBox.style.left = `${b.x}px`
          cropBox.style.top = `${b.y}px`
          cropBox.style.width = `${b.w}px`
          cropBox.style.height = `${b.h}px`
          const top = cropOverlay.querySelector('.photos-crop-shade-top')
          const left = cropOverlay.querySelector('.photos-crop-shade-left')
          const right = cropOverlay.querySelector('.photos-crop-shade-right')
          const bottom = cropOverlay.querySelector('.photos-crop-shade-bottom')
          if (top) Object.assign(top.style, { left: '0', top: '0', width: '100%', height: `${b.y}px` })
          if (left) Object.assign(left.style, { left: '0', top: `${b.y}px`, width: `${b.x}px`, height: `${b.h}px` })
          if (right) Object.assign(right.style, {
            left: `${b.x + b.w}px`,
            top: `${b.y}px`,
            width: `${Math.max(0, cropCanvas.width - b.x - b.w)}px`,
            height: `${b.h}px`,
          })
          if (bottom) Object.assign(bottom.style, {
            left: '0',
            top: `${b.y + b.h}px`,
            width: '100%',
            height: `${Math.max(0, cropCanvas.height - b.y - b.h)}px`,
          })
        }

        function resetCropBox() {
          if (!cropCanvas) return
          const insetX = Math.round(cropCanvas.width * 0.08)
          const insetY = Math.round(cropCanvas.height * 0.08)
          cropState.box = {
            x: insetX,
            y: insetY,
            w: Math.max(40, cropCanvas.width - insetX * 2),
            h: Math.max(40, cropCanvas.height - insetY * 2),
          }
          syncCropOverlay()
        }

        function clampCropBox() {
          if (!cropCanvas) return
          const min = 40
          let { x, y, w, h } = cropState.box
          w = Math.max(min, Math.min(w, cropCanvas.width))
          h = Math.max(min, Math.min(h, cropCanvas.height))
          x = Math.max(0, Math.min(x, cropCanvas.width - w))
          y = Math.max(0, Math.min(y, cropCanvas.height - h))
          cropState.box = { x, y, w, h }
        }

        async function openCropModal(photoId, photoUrl) {
          if (!cropModal || !photoUrl) return
          const img = new Image()
          try {
            const res = await fetch(photoUrl, {
              credentials: 'same-origin',
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            if (!res.ok) throw new Error('fetch '+res.status)
            const blob = await res.blob()
            const objUrl = URL.createObjectURL(blob)
            await new Promise((resolve, reject) => {
              img.onload = () => resolve()
              img.onerror = reject
              img.src = objUrl
            })
          } catch (_) {
            window.alert(@json(__('画像の読み込みに失敗しました。')));
            return
          }
          if (!img.naturalWidth) {
            window.alert(@json(__('画像の読み込みに失敗しました。')));
            return
          }
          cropState.photoId = photoId
          cropState.source = img
          cropState.rotation = 0
          cropModal.hidden = false
          requestAnimationFrame(() => {
            drawCropCanvas()
            resetCropBox()
          })
        }

        document.getElementById('photos-open-crop-btn')?.addEventListener('click', () => {
          const btn = document.getElementById('photos-open-crop-btn')
          openCropModal(btn?.dataset.photoId, btn?.dataset.photoUrl)
        })
        document.querySelectorAll('[data-close-crop-modal]').forEach((el) => {
          el.addEventListener('click', closeCropModal)
        })
        document.getElementById('photos-crop-rotate-left')?.addEventListener('click', () => {
          cropState.rotation = (cropState.rotation + 270) % 360
          drawCropCanvas()
          resetCropBox()
        })
        document.getElementById('photos-crop-rotate-right')?.addEventListener('click', () => {
          cropState.rotation = (cropState.rotation + 90) % 360
          drawCropCanvas()
          resetCropBox()
        })
        document.getElementById('photos-crop-reset')?.addEventListener('click', () => {
          cropState.rotation = 0
          drawCropCanvas()
          resetCropBox()
        })

        function pointerPos(e) {
          const rect = cropOverlay.getBoundingClientRect()
          const point = e.touches ? e.touches[0] : e
          return { x: point.clientX - rect.left, y: point.clientY - rect.top }
        }

        function startCropDrag(e) {
          if (!cropOverlay || cropOverlay.hidden) return
          const handle = e.target?.dataset?.handle || 'move'
          if (e.target !== cropBox && !e.target?.dataset?.handle) return
          e.preventDefault()
          const p = pointerPos(e)
          cropState.drag = {
            handle,
            startX: p.x,
            startY: p.y,
            origin: { ...cropState.box },
          }
        }

        function moveCropDrag(e) {
          if (!cropState.drag) return
          e.preventDefault()
          const p = pointerPos(e)
          const dx = p.x - cropState.drag.startX
          const dy = p.y - cropState.drag.startY
          const o = cropState.drag.origin
          let box = { ...o }
          const h = cropState.drag.handle
          if (h === 'move') {
            box.x = o.x + dx
            box.y = o.y + dy
          } else {
            if (h.includes('n')) {
              box.y = o.y + dy
              box.h = o.h - dy
            }
            if (h.includes('s')) box.h = o.h + dy
            if (h.includes('w')) {
              box.x = o.x + dx
              box.w = o.w - dx
            }
            if (h.includes('e')) box.w = o.w + dx
          }
          cropState.box = box
          clampCropBox()
          syncCropOverlay()
        }

        function endCropDrag() {
          cropState.drag = null
        }

        cropBox?.addEventListener('mousedown', startCropDrag)
        cropBox?.addEventListener('touchstart', startCropDrag, { passive: false })
        window.addEventListener('mousemove', moveCropDrag)
        window.addEventListener('touchmove', moveCropDrag, { passive: false })
        window.addEventListener('mouseup', endCropDrag)
        window.addEventListener('touchend', endCropDrag)

        function buildFullResCropBlob() {
          const img = cropState.source
          if (!cropCanvas || !img) return null
          const displayW = cropCanvas.width
          const displayH = cropCanvas.height
          if (displayW < 1 || displayH < 1) return null

          const fullSize = naturalSize()
          const scaleX = fullSize.w / displayW
          const scaleY = fullSize.h / displayH
          const b = cropState.box
          const sx = Math.max(0, b.x * scaleX)
          const sy = Math.max(0, b.y * scaleY)
          const sw = Math.min(fullSize.w - sx, Math.max(1, b.w * scaleX))
          const sh = Math.min(fullSize.h - sy, Math.max(1, b.h * scaleY))
          const outW = Math.max(1, Math.round(sw))
          const outH = Math.max(1, Math.round(sh))

          // Preview canvas is downscaled for UI; re-render at natural resolution before crop.
          const full = document.createElement('canvas')
          full.width = fullSize.w
          full.height = fullSize.h
          const fctx = full.getContext('2d')
          if (!fctx) return null
          fctx.imageSmoothingEnabled = true
          fctx.imageSmoothingQuality = 'high'
          fctx.save()
          fctx.translate(fullSize.w / 2, fullSize.h / 2)
          fctx.rotate((cropState.rotation * Math.PI) / 180)
          fctx.drawImage(img, -img.naturalWidth / 2, -img.naturalHeight / 2)
          fctx.restore()

          const out = document.createElement('canvas')
          out.width = outW
          out.height = outH
          const ctx = out.getContext('2d')
          if (!ctx) return null
          ctx.imageSmoothingEnabled = true
          ctx.imageSmoothingQuality = 'high'
          ctx.drawImage(full, sx, sy, sw, sh, 0, 0, outW, outH)

          return new Promise((resolve) => out.toBlob(resolve, 'image/jpeg', 0.95))
        }

        document.getElementById('photos-crop-save')?.addEventListener('click', async () => {
          if (!cropCanvas || !cropState.source || !cropState.photoId) return
          const saveBtn = document.getElementById('photos-crop-save')
          if (saveBtn) {
            saveBtn.disabled = true
            saveBtn.textContent = @json(__('保存中…'));
          }
          try {
            const blob = await buildFullResCropBlob()
            if (!blob) throw new Error('blob')
            const form = document.getElementById('photos-edit-image-form')
            const input = document.getElementById('photos-edit-image-input')
            if (!form || !input) throw new Error('form')
            form.action = `/photos/${cropState.photoId}/edit-image`
            const file = new File([blob], `trim-${cropState.photoId}.jpg`, { type: 'image/jpeg' })
            const dt = new DataTransfer()
            dt.items.add(file)
            input.files = dt.files
            form.submit()
          } catch (_) {
            window.alert(@json(__('トリム画像の保存に失敗しました。')));
            if (saveBtn) {
              saveBtn.disabled = false
              saveBtn.textContent = @json(__('トリムして保存'));
            }
          }
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

        @if(!empty($cloudinaryEditorReady))
        try {
          ;(function setupCloudinaryEditor() {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
            let activeTempPublicId = null
            let activePhotoId = null
            let editorInstance = null
            let editorScriptPromise = null

            function loadMediaEditorScript() {
              if (window.cloudinary && typeof window.cloudinary.mediaEditor === 'function') {
                return Promise.resolve()
              }
              if (editorScriptPromise) return editorScriptPromise
              editorScriptPromise = new Promise((resolve, reject) => {
                const existing = document.querySelector('script[data-cloudinary-media-editor]')
                if (existing) {
                  existing.addEventListener('load', () => resolve())
                  existing.addEventListener('error', () => reject(new Error('load failed')))
                  return
                }
                const s = document.createElement('script')
                s.src = 'https://media-editor.cloudinary.com/latest/all.js'
                s.async = true
                s.dataset.cloudinaryMediaEditor = '1'
                s.onload = () => resolve()
                s.onerror = () => reject(new Error('load failed'))
                document.head.appendChild(s)
              })
              return editorScriptPromise
            }

            async function postJson(url, body) {
              const res = await fetch(url, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'Accept': 'application/json',
                  'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(body || {}),
              })
              const data = await res.json().catch(() => ({}))
              if (!res.ok || data.ok === false) {
                throw new Error(data.message || @json(__('リクエストに失敗しました')));
              }
              return data
            }

            async function cancelTemp() {
              if (!activePhotoId || !activeTempPublicId) return
              try {
                await postJson(`/photos/${activePhotoId}/cloudinary-edit/cancel`, {
                  tempPublicId: activeTempPublicId,
                })
              } catch (_) {}
              activeTempPublicId = null
              activePhotoId = null
            }

            document.getElementById('photos-cloudinary-edit-btn')?.addEventListener('click', async () => {
              const btn = document.getElementById('photos-cloudinary-edit-btn')
              const photoId = Number(btn?.dataset.photoId || 0)
              if (!photoId || !btn) return
              btn.disabled = true
              const originalText = btn.textContent
              btn.textContent = @json(__('準備中…'));
              try {
                await loadMediaEditorScript()
                if (!window.cloudinary || typeof window.cloudinary.mediaEditor !== 'function') {
                  throw new Error(@json(__('Cloudinary Media Editor の読み込みに失敗しました。')))
                }
                await cancelTemp()
                const session = await postJson(`/photos/${photoId}/cloudinary-edit/start`, {})
                activePhotoId = photoId
                activeTempPublicId = session.publicId

                if (editorInstance) {
                  try { editorInstance.destroy() } catch (_) {}
                }
                editorInstance = window.cloudinary.mediaEditor()
                editorInstance.update({
                  cloudName: session.cloudName,
                  publicIds: [session.publicId],
                  steps: ['resizeAndCrop', 'imageOverlay', 'textOverlays', 'export'],
                })
                editorInstance.on('export', async (data) => {
                  const asset = (data && data.assets && data.assets[0]) ? data.assets[0] : null
                  const exportUrl = asset ? (asset.secureUrl || asset.url || '') : ''
                  if (!exportUrl) {
                    window.alert(@json(__('編集結果を取得できませんでした。')));
                    return
                  }
                  try {
                    btn.textContent = @json(__('保存中…'));
                    await postJson(`/photos/${photoId}/cloudinary-edit/commit`, {
                      exportUrl,
                      tempPublicId: activeTempPublicId || session.publicId,
                      label: @json(__('Cloudinary編集')),
                    })
                    activeTempPublicId = null
                    activePhotoId = null
                    try { editorInstance.hide() } catch (_) {}
                    window.location.reload()
                  } catch (e) {
                    window.alert(e.message || @json(__('保存に失敗しました。')));
                  }
                })
                editorInstance.on('close', () => { cancelTemp() })
                editorInstance.show()
              } catch (e) {
                window.alert(e.message || @json(__('Cloudinary編集を開始できませんでした。')));
                await cancelTemp()
              } finally {
                btn.disabled = false
                btn.textContent = originalText
              }
            })
          })()
        } catch (err) {
          console.warn('Cloudinary editor setup skipped', err)
        }
        @endif

        @if(!empty($stabilityEnhanceReady))
        ;(function setupStabilityEnhance() {
          const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
          const btn = document.getElementById('photos-stability-enhance-btn')
          if (!btn) return
          btn.addEventListener('click', async () => {
            const photoId = Number(btn.dataset.photoId || 0)
            if (!photoId) return
            if (!window.confirm(@json(__('この写真を AI で鮮明化して R2 に保存しますか？（元画像は残ります）')))) {
              return
            }
            btn.disabled = true
            const originalText = btn.textContent
            btn.textContent = @json(__('鮮明化中…'));
            try {
              const res = await fetch(`/photos/${photoId}/stability-enhance`, {
                method: 'POST',
                headers: {
                  'Accept': 'application/json',
                  'Content-Type': 'application/json',
                  'X-CSRF-TOKEN': csrf,
                },
                body: '{}',
              })
              const data = await res.json().catch(() => ({}))
              if (!res.ok || data.ok === false) {
                throw new Error(data.message || @json(__('AI鮮明化に失敗しました。')))
              }
              window.alert(data.message || @json(__('AI鮮明化版を保存しました。')))
              window.location.reload()
            } catch (e) {
              window.alert(e.message || @json(__('AI鮮明化に失敗しました。')))
            } finally {
              btn.disabled = false
              btn.textContent = originalText
            }
          })
        })()
        @endif
      })()
    </script>
  </body>
</html>
