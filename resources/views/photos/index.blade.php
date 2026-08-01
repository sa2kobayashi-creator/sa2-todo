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
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ @filemtime(public_path('app.css')) ?: time() }}" />
  </head>
  <body class="photos-page">
    @include('partials.header', ['active' => 'photos'])
    <main class="page-main photos-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <section class="photos-hero">
        <div class="photos-hero-copy">
          <p class="photos-kicker">{{ __('Album') }}</p>
          <h1 class="photos-title" id="photos-title-tap" title="{{ __('隠しアルバムの表示切替: 7回タップ') }}">{{ $selectedAlbum['name'] ?? __('Photos') }}</h1>
          @if(!empty($revealHiddenAlbums))
            <p class="photos-hidden-reveal-note" id="photos-hidden-reveal-note">{{ __('隠しアルバムを表示中（タイトルを7回タップで非表示）') }}</p>
          @endif
          <p class="photos-lead">
            @if($selectedAlbum)
              {{ __(':count枚', ['count' => $selectedAlbum['photoCount']]) }} · {{ __('表紙を選んでアルバムらしく') }}
              @if(!empty($selectedAlbum['hasPassword']))
                · {{ __('鍵付き') }}
              @endif
              @if(!empty($selectedAlbum['isHidden']))
                · {{ __('隠し') }}
              @endif
            @else
              {{ __('旅・日常・お気に入りを、見ていて気持ちよく残す場所。') }}
            @endif
          </p>
        </div>
        <div class="photos-hero-actions">
          @if(empty($selectedAlbum) || !empty($canManageSelected))
            <button type="button" class="photos-upload-btn" id="photos-add-open" title="{{ __('写真・動画を追加（複数可）') }}">
              <span class="photos-upload-btn-label">{{ __('写真・動画を追加') }}</span>
            </button>
            {{-- accept なし: Android のファイル画面（このデバイス / Pictures / Messenger・LINE 等）を開く --}}
            <input
              type="file"
              id="photos-files-input"
              class="photos-file-input-hidden"
              multiple
            />
            {{-- ギャラリー用（フォトピッカー）。空表示になる端末があるためサブ扱い --}}
            <input
              type="file"
              id="photos-file-input"
              class="photos-file-input-hidden"
              accept="image/*,video/*,image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,.heic,.heif,.mp4"
              multiple
            />
            <input
              type="file"
              id="photos-camera-input"
              class="photos-file-input-hidden"
              accept="image/*"
              capture="environment"
            />
            <input
              type="file"
              id="photos-camera-video-input"
              class="photos-file-input-hidden"
              accept="video/*"
              capture="environment"
            />
            <button type="button" class="photos-secondary-btn" id="photos-folder-watch-btn" hidden>{{ __('フォルダを監視') }}</button>
            <button type="button" class="photos-secondary-btn" id="photos-folder-watch-stop" hidden>{{ __('監視を停止') }}</button>
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
        <div class="photos-pending-bar" id="photos-pending-bar" hidden>
          <span class="photos-pending-count" id="photos-pending-count"></span>
          @if(!empty($ownedAlbums))
            <label class="photos-upload-album">
              <span>{{ __('追加先') }}</span>
              <select id="photos-pending-album" data-upload-album>
                <option value="">{{ __('標準（アルバムなし）') }}</option>
                @foreach($ownedAlbums as $album)
                  <option value="{{ $album['id'] }}" @selected($selectedAlbumId === $album['id'])>{{ $album['name'] }}</option>
                @endforeach
              </select>
            </label>
          @endif
          <button type="button" class="photos-secondary-btn" id="photos-pending-more">{{ __('さらに選ぶ') }}</button>
          <button type="button" class="photos-upload-btn" id="photos-pending-add">{{ __('まとめて追加') }}</button>
          <button type="button" class="photos-secondary-btn" id="photos-pending-cancel">{{ __('やめる') }}</button>
        </div>
      </section>

      <div class="photos-add-sheet" id="photos-add-sheet" hidden>
        <button type="button" class="photos-add-sheet-backdrop" id="photos-add-sheet-backdrop" aria-label="{{ __('閉じる') }}"></button>
        <div class="photos-add-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="photos-add-sheet-title">
          <h2 id="photos-add-sheet-title">{{ __('写真・動画を追加') }}</h2>
          <p class="photos-add-sheet-hint">{{ __('Messenger や LINE の写真は「フォルダから探す」→ このデバイス → Pictures → Messenger / Line を開いてください。ギャラリーが空でもフォルダ側にあります。') }}</p>
          @if(!empty($ownedAlbums))
            <label class="photos-upload-album photos-add-sheet-album">
              <span>{{ __('追加先アルバム') }}</span>
              <select id="photos-add-sheet-album" data-upload-album>
                <option value="">{{ __('標準（アルバムなし）') }}</option>
                @foreach($ownedAlbums as $album)
                  <option value="{{ $album['id'] }}" @selected($selectedAlbumId === $album['id'])>{{ $album['name'] }}</option>
                @endforeach
              </select>
            </label>
          @endif
          <button type="button" class="photos-upload-btn photos-add-sheet-action" id="photos-add-sheet-files">{{ __('フォルダから探す（推奨）') }}</button>
          <button type="button" class="photos-secondary-btn photos-add-sheet-action" id="photos-add-sheet-pick">{{ __('ギャラリーから選ぶ') }}</button>
          <button type="button" class="photos-secondary-btn photos-add-sheet-action" id="photos-add-sheet-camera">{{ __('カメラで撮る') }}</button>
          <button type="button" class="photos-secondary-btn photos-add-sheet-action" id="photos-add-sheet-video">{{ __('動画を撮る') }}</button>
          <button type="button" class="photos-secondary-btn photos-add-sheet-action" id="photos-add-sheet-cancel">{{ __('キャンセル') }}</button>
        </div>
      </div>

      <aside class="photos-storage" aria-label="保存容量">
        <div class="photos-storage-head">
          <strong>{{ __('保存容量') }}</strong>
          <span class="photos-storage-head-actions">
            <span>
              {{ __('合計') }} {{ $storageStats['formattedTotalUsed'] }}
              / {{ $storageStats['formattedDisplayCapacity'] ?? $storageStats['formattedCombinedQuota'] }}
              {{ __('（:count枚）', ['count' => $storageStats['photoCount']]) }}
              · {{ __('無料枠') }} {{ $storageStats['formattedCombinedQuota'] }}
              @if(!empty($storageStats['overFreeTier']))
                <em class="photos-storage-over">{{ __('無料枠超過') }}</em>
              @endif
            </span>
            <button type="button" class="photos-secondary-btn photos-storage-usage-btn" id="photos-usage-open">{{ __('使用状況') }}</button>
            <button type="button" class="photos-secondary-btn photos-storage-usage-btn" id="photos-sim-open">{{ __('料金シミュレーション') }}</button>
            @if(!empty($storageStats['archiveEnabled']))
              @php
                $archiveCapacityMode = $storageStats['capacityMode'] ?? 'age_archive';
                $hotQuotaLabel = $storageStats['formattedQuota'] ?? '10 GB';
                if ($archiveCapacityMode === 'r2_cap') {
                  $archiveBtnTitle = __('常用（R2）が約 :quota を超えないよう、古い原本を B2 へ分割移動します', ['quota' => $hotQuotaLabel]);
                  $archiveConfirm = __('常用（R2）の使用量が約 :quota 以下になるまで、古い原本を Backblaze B2 へ分割して移します（1回あたり最大 :batch 件）。完了まで数分かかることがあります。ページを離れる前に確認が出ます。実行しますか？', [
                    'quota' => $hotQuotaLabel,
                    'batch' => (string) ((int) config('photos.archive_cold_batch_size', 40)),
                  ]);
                } else {
                  $archiveBtnTitle = __('古い常用原本を Backblaze B2 へ分割移動します（photos:archive-cold）');
                  $archiveConfirm = __('常用（R2）の古い原本を Backblaze B2 へ分割して移します（1回あたり最大 :batch 件）。完了まで数分かかることがあります。ページを離れる前に確認が出ます。実行しますか？', [
                    'batch' => (string) ((int) config('photos.archive_cold_batch_size', 40)),
                  ]);
                }
              @endphp
              <button
                type="button"
                class="photos-secondary-btn photos-storage-usage-btn"
                id="photos-archive-cold-btn"
                title="{{ $archiveBtnTitle }}"
              >{{ __('B2へアーカイブ') }}</button>
              <span class="hint" id="photos-archive-cold-status" aria-live="polite"></span>
            @endif
          </span>
        </div>
        <div class="photos-storage-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) min(100, $storageStats['percent']) }}" aria-label="{{ __('表示容量に対する使用量') }}">
          <span class="photos-storage-bar-fill{{ ($storageStats['percent'] >= 90 || !empty($storageStats['overFreeTier'])) ? ' is-warn' : '' }}" style="width: {{ min(100, max(0, (float) $storageStats['percent'])) }}%"></span>
          @if(isset($storageStats['freeMarkPercent']))
            <span class="photos-storage-bar-free-mark" style="left: {{ min(100, max(0, (float) $storageStats['freeMarkPercent'])) }}%" title="{{ __('無料枠') }} {{ $storageStats['formattedCombinedQuota'] }}"></span>
          @endif
        </div>
        @if(!empty($storageStats['uploadsBlocked']))
          <p class="photos-storage-block-note" role="alert">
            {{ __('無料枠（:free）を超えているため、新規の写真・動画の追加はできません。超過分の見込は約 :bill です。設定 → ストレージで「有料枠（無料枠超過）を許可する」をオンにすると追加できます。', [
              'free' => $storageStats['formattedCombinedQuota'],
              'bill' => $storageStats['estimatedTotalBillLabel'] ?? '—',
            ]) }}
          </p>
        @endif
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
          {{ __('ユーザーごとの無料枠は合計') }} {{ $storageStats['formattedCombinedQuota'] }}{{ __('です（超過分は見込表示。有料課金は今後対応予定）。') }}
          @if(!empty($storageStats['pipelineEnabled']))
            {{ __('構成:') }}
            {{ __('常用原本') }} = {{ $storageStats['primaryLabel'] }}
            @if(!empty($storageStats['cloudinaryEditor']))
              · {{ __('編集') }} = Cloudinary（{{ __('一時のみ・常設保管なし') }}）
            @endif
            @if(!empty($storageStats['enhanceReady']))
              · {{ __('AI鮮明化') }} = {{ $storageStats['enhanceProviderLabel'] ?? 'Stability AI' }}
            @endif
            @if(!empty($storageStats['archiveEnabled']))
              · {{ __('長期保存') }} = Backblaze B2
              （{{ __('内訳目安') }} {{ $storageStats['primaryLabel'] }} {{ $storageStats['formattedQuota'] }} + B2 {{ $storageStats['formattedB2Quota'] }}）
            @endif
            。
            {{ __('超過見込単価') }} {{ $storageStats['overagePriceLabel'] }}。
          @else
            {{ __('保存先:') }} {{ $storageStats['diskLabel'] }}。
            {{ __('超過見込単価') }} {{ $storageStats['overagePriceLabel'] }}。
          @endif
        </p>
      </aside>

      <script>
        window.PhotosPageJob = (function () {
          let kind = null
          let abortFn = null
          let allowUnload = false
          const i18n = {
            leaveUpload: @json(__('取り込み処理中です。このまま離れると処理が中断されます。\n\nOK = 中断して離れる\nキャンセル = 完了まで待つ')),
            leaveArchive: @json(__('B2へのアーカイブ処理中です。このまま離れると処理が中断されます。\n\nOK = 中断して離れる\nキャンセル = 完了まで待つ')),
          }

          function message() {
            if (kind === 'upload') return i18n.leaveUpload
            if (kind === 'archive') return i18n.leaveArchive
            return @json(__('処理中です。このまま離れると処理が中断されます。\n\nOK = 中断して離れる\nキャンセル = 完了まで待つ'))
          }

          function isBusy() {
            return !!kind
          }

          function getKind() {
            return kind
          }

          function start(nextKind, nextAbort) {
            kind = nextKind
            abortFn = typeof nextAbort === 'function' ? nextAbort : null
            allowUnload = false
          }

          function end(expectedKind) {
            if (expectedKind && kind !== expectedKind) return
            kind = null
            abortFn = null
          }

          function requestLeave() {
            if (!isBusy() || allowUnload) return true
            if (!window.confirm(message())) return false
            allowUnload = true
            try { if (abortFn) abortFn() } catch (_) {}
            kind = null
            abortFn = null
            setTimeout(function () { allowUnload = false }, 3000)
            return true
          }

          window.addEventListener('beforeunload', function (e) {
            if (!isBusy() || allowUnload) return
            e.preventDefault()
            e.returnValue = ''
          })

          document.addEventListener('click', function (e) {
            if (!isBusy()) return
            const a = e.target.closest('a[href]')
            if (!a) return
            const href = a.getAttribute('href') || ''
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return
            if (a.target === '_blank' || a.hasAttribute('download')) return
            e.preventDefault()
            e.stopPropagation()
            if (requestLeave()) {
              window.location.assign(a.href)
            }
          }, true)

          document.addEventListener('submit', function (e) {
            if (!isBusy()) return
            const form = e.target
            if (!form || form.id === 'photos-upload-form') return
            e.preventDefault()
            e.stopPropagation()
            if (requestLeave()) {
              allowUnload = true
              form.submit()
            }
          }, true)

          return { isBusy: isBusy, getKind: getKind, start: start, end: end, requestLeave: requestLeave }
        })()
      </script>

      @if(!empty($storageStats['archiveEnabled']))
      @php
        $archiveBatchSize = (int) config('photos.archive_cold_batch_size', 40);
        $archiveTimeoutSec = (int) config('photos.archive_cold_request_timeout_seconds', 900);
        $archiveMaxBatches = (int) config('photos.archive_cold_max_batches', 50);
      @endphp
      <script>
        (function () {
          const btn = document.getElementById('photos-archive-cold-btn')
          const statusEl = document.getElementById('photos-archive-cold-status')
          if (!btn) return

          const batchSize = {{ $archiveBatchSize }}
          const timeoutMs = {{ $archiveTimeoutSec }} * 1000
          const maxBatches = {{ $archiveMaxBatches }}
          const job = window.PhotosPageJob

          const i18n = {
            confirm: @json($archiveConfirm),
            running: @json(__('アーカイブ中…')),
            done: @json(__('アーカイブが完了しました')),
            fail: @json(__('アーカイブに失敗しました')),
            timeout: @json(__('タイムアウトしました。PHOTO_ARCHIVE_COLD_TIMEOUT_SECONDS を延ばすか、しばらくして再実行してください。')),
            cancelled: @json(__('アーカイブを中断しました')),
            idle: btn.textContent || @json(__('B2へアーカイブ')),
            batch: @json(__('アーカイブ中… :done件移動（バッチ :batch/:max）')),
          }

          function setStatus(text) {
            if (statusEl) statusEl.textContent = text || ''
          }

          function csrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]')
            return (meta && meta.content) || ''
          }

          function xsrfToken() {
            const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)
            if (!match) return ''
            try { return decodeURIComponent(match[1]) } catch (_) { return match[1] || '' }
          }

          function formatBatchStatus(done, batch, max) {
            return i18n.batch
              .replace(':done', String(done))
              .replace(':batch', String(batch))
              .replace(':max', String(max))
          }

          btn.addEventListener('click', function (e) {
            e.preventDefault()
            e.stopPropagation()
            if (btn.disabled) return
            if (job && job.isBusy()) {
              if (job.getKind() === 'archive') return
              if (!job.requestLeave()) return
            }
            if (!window.confirm(i18n.confirm)) return

            let cancelled = false
            let controller = null
            const abortCurrent = function () {
              cancelled = true
              try { if (controller) controller.abort() } catch (_) {}
            }

            btn.disabled = true
            btn.textContent = i18n.running
            setStatus('')
            if (job) job.start('archive', abortCurrent)

            const headers = {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': csrfToken(),
            }
            const xsrf = xsrfToken()
            if (xsrf) headers['X-XSRF-TOKEN'] = xsrf

            ;(async function () {
              let totalArchived = 0
              let totalSkipped = 0
              let totalErrors = 0
              let lastMessage = i18n.done
              let ok = true

              try {
                for (let batch = 1; batch <= maxBatches; batch++) {
                  if (cancelled) break
                  controller = typeof AbortController !== 'undefined' ? new AbortController() : null
                  const timer = controller ? setTimeout(function () { controller.abort() }, timeoutMs) : null
                  const body = new FormData()
                  body.append('limit', String(batchSize))
                  body.append('_token', csrfToken())

                  btn.textContent = formatBatchStatus(totalArchived, batch, maxBatches)
                  setStatus(formatBatchStatus(totalArchived, batch, maxBatches))

                  let res
                  try {
                    const response = await fetch('/photos/archive-cold', {
                      method: 'POST',
                      headers: headers,
                      body: body,
                      credentials: 'same-origin',
                      signal: controller ? controller.signal : undefined,
                    })
                    const text = await response.text()
                    let data = null
                    try { data = text ? JSON.parse(text) : null } catch (_) {}
                    res = { ok: response.ok, status: response.status, data: data }
                  } catch (err) {
                    if (cancelled || (err && err.name === 'AbortError')) {
                      lastMessage = cancelled ? i18n.cancelled : i18n.timeout
                      ok = false
                      break
                    }
                    throw err
                  } finally {
                    if (timer) clearTimeout(timer)
                    controller = null
                  }

                  const archived = Number(res.data && res.data.archived) || 0
                  const skipped = Number(res.data && res.data.skipped) || 0
                  const errors = Number(res.data && res.data.errors) || 0
                  totalArchived += archived
                  totalSkipped += skipped
                  totalErrors += errors
                  lastMessage = (res.data && res.data.message)
                    ? res.data.message
                    : (res.ok ? i18n.done : i18n.fail)
                  if (!res.ok && archived === 0) {
                    ok = false
                    break
                  }
                  const hasMore = !!(res.data && res.data.has_more)
                  if (!hasMore) {
                    ok = res.ok && totalErrors === 0
                    lastMessage = @json(__('アーカイブ完了: 移動 :archived 件 · スキップ :skipped 件 · エラー :errors 件'))
                      .replace(':archived', String(totalArchived))
                      .replace(':skipped', String(totalSkipped))
                      .replace(':errors', String(totalErrors))
                    break
                  }
                  if (batch === maxBatches) {
                    lastMessage = @json(__('上限バッチに達しました（移動 :archived 件）。必要なら再度「B2へアーカイブ」を実行してください。'))
                      .replace(':archived', String(totalArchived))
                    ok = true
                  }
                }
              } catch (_) {
                ok = false
                lastMessage = i18n.fail
              }

              setStatus(lastMessage)
              if (cancelled) return
              window.alert(lastMessage)
              if (ok || totalArchived > 0) {
                if (job) job.end('archive')
                window.location.reload()
              }
            })().finally(function () {
              if (job) job.end('archive')
              btn.disabled = false
              btn.textContent = i18n.idle
            })
          })
        })()
      </script>
      @endif

      <aside class="photos-sync-tip" aria-label="スマホからの追加・PWA">
        <div class="photos-sync-tip-copy">
          <strong>{{ __('スマホ同期 / アプリ化') }}</strong>
          <span>{{ __('「写真・動画を追加」→「フォルダから探す」で Pictures / Messenger / LINE などから選べます。ギャラリーが空でもフォルダ側に写真があることがあります。') }}</span>
          <span class="photos-folder-watch-status" id="photos-folder-watch-status" hidden></span>
        </div>
        <button type="button" class="photos-secondary-btn photos-pwa-tip-btn" id="photos-pwa-install">{{ __('ホーム画面に追加') }}</button>
      </aside>

      <section class="photos-album-covers" aria-label="アルバム">
        <a
          href="/photos{{ request()->filled('sort') || request()->filled('year') ? '?'.http_build_query(array_filter(['sort' => request('sort'), 'year' => request('year')])) : '' }}"
          @class(['photos-cover-card', 'is-all', 'is-active' => !$selectedAlbumId])
        >
          <span class="photos-cover-all-label">{{ __('すべて') }}</span>
          <span class="photos-cover-meta">{{ count($photos) }}{{ __('枚を表示中') }}</span>
        </a>
        @foreach($albums as $album)
          @php
            $albumQuery = array_filter([
              'album' => $album['id'],
              'sort' => request('sort'),
              'year' => request('year'),
            ], fn ($v) => $v !== null && $v !== '');
          @endphp
          <a
            href="/photos?{{ http_build_query($albumQuery) }}"
            @class(['photos-cover-card', 'is-active' => $selectedAlbumId === $album['id'], 'is-hidden-album' => !empty($album['isHidden'])])
          >
            @if(!empty($album['coverUrl']))
              <img src="{{ $album['coverUrl'] }}" alt="" class="photos-cover-image" loading="lazy" />
              @if(($album['coverMediaKind'] ?? '') === 'video')
                <span class="photos-cover-play" aria-hidden="true">▶</span>
              @endif
            @else
              <span class="photos-cover-placeholder" aria-hidden="true"></span>
            @endif
            <span class="photos-cover-shade"></span>
            <span class="photos-cover-text">
              <strong>
                @if(!empty($album['hasPassword']))🔒 @endif
                @if(!empty($album['isHidden']))👁‍🗨 @endif
                {{ $album['name'] }}
              </strong>
              <span>
                {{ __(':count枚', ['count' => $album['photoCount']]) }}
                @if(!empty($album['visibilityLabel']))
                  · {{ $album['visibilityLabel'] }}
                @endif
                @if(!empty($album['isHidden']))
                  · {{ __('隠し') }}
                @endif
              </span>
            </span>
          </a>
        @endforeach
      </section>

      <form method="post" action="/photos" enctype="multipart/form-data" id="photos-upload-form" class="photos-upload-form">
        @csrf
        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
        <input type="hidden" name="album_id" id="photos-form-album-id" value="{{ $selectedAlbumId }}" />
        <input type="file" name="photos[]" id="photos-form-files" accept="image/*,video/mp4,.heic,.heif,.mp4" multiple hidden />
        <input type="file" name="video_thumbs[]" id="photos-form-thumbs" accept="image/jpeg" multiple hidden />
        <input type="hidden" name="video_thumb_for" id="photos-form-thumb-for" value="" />
      </form>

      @if(!empty($albumLocked) && $selectedAlbum)
        <div class="photos-album-lock" id="photos-album-lock">
          <div class="photos-album-lock-card">
            <h2>{{ __('このアルバムは鍵付きです') }}</h2>
            <p>{{ __('パスワードを入力すると写真を表示できます。') }}</p>
            <form method="post" action="/photos/albums/{{ $selectedAlbum['id'] }}/unlock" class="modal-form photos-album-lock-form">
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <label>
                {{ __('パスワード') }}
                <input type="password" name="password" required minlength="4" autocomplete="current-password" />
              </label>
              <div class="modal-actions">
                <button type="submit">{{ __('ロック解除') }}</button>
              </div>
            </form>
          </div>
        </div>
      @elseif(count($photos) === 0)
        <div class="photos-empty" id="photos-dropzone">
          <div class="photos-empty-frame">
            <p class="photos-empty-title">{{ __('まだメディアがありません') }}</p>
            <p class="photos-empty-text">
              {{ __('このアプリにまだ保存されていません。') }}<br />
              {{ __('Messenger / LINE などは「フォルダから探す」→ Pictures から選んでください。') }}
            </p>
            <div class="photos-empty-actions">
              <button type="button" class="photos-upload-btn photos-upload-btn-large" id="photos-empty-add">
                {{ __('写真・動画を追加') }}
              </button>
            </div>
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
          <label class="photos-toolbar-select">
            <span class="visually-hidden">{{ __('並び替え') }}</span>
            <select id="photos-sort-select" aria-label="{{ __('並び替え') }}">
              <option value="taken_desc" @selected(($photosSort ?? 'taken_desc') === 'taken_desc')>{{ __('撮影日（新しい順）') }}</option>
              <option value="taken_asc" @selected(($photosSort ?? '') === 'taken_asc')>{{ __('撮影日（古い順）') }}</option>
              <option value="name_asc" @selected(($photosSort ?? '') === 'name_asc')>{{ __('名前（A→Z）') }}</option>
              <option value="name_desc" @selected(($photosSort ?? '') === 'name_desc')>{{ __('名前（Z→A）') }}</option>
              <option value="size_desc" @selected(($photosSort ?? '') === 'size_desc')>{{ __('サイズ（大きい順）') }}</option>
              <option value="size_asc" @selected(($photosSort ?? '') === 'size_asc')>{{ __('サイズ（小さい順）') }}</option>
            </select>
          </label>
          <label class="photos-toolbar-select">
            <span class="visually-hidden">{{ __('年で絞り込み') }}</span>
            <select id="photos-year-select" aria-label="{{ __('年で絞り込み') }}">
              <option value="" @selected(empty($photosYear))>{{ __('すべての年') }}</option>
              @foreach(($photoYears ?? []) as $yearOpt)
                <option value="{{ $yearOpt }}" @selected((int) ($photosYear ?? 0) === (int) $yearOpt)>{{ __(':year年', ['year' => $yearOpt]) }}</option>
              @endforeach
            </select>
          </label>
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
            <button type="button" class="photos-secondary-btn" id="photos-bulk-download">{{ __('ダウンロード') }}</button>
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
                        <img
                          class="photos-tile-video"
                          src="{{ $photo['thumbUrl'] }}"
                          alt="{{ $photo['caption'] ?: ($photo['originalName'] ?: __('動画')) }}"
                          loading="lazy"
                          decoding="async"
                        />
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
                      <button
                        type="button"
                        class="photos-secondary-btn photos-list-download"
                        data-photo-id="{{ $photo['id'] }}"
                        data-photo-index="{{ $flatIndex }}"
                        title="{{ __('ダウンロード') }}"
                        aria-label="{{ __('ダウンロード') }}"
                      >{{ __('ダウンロード') }}</button>
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
                <button
                  type="button"
                  class="photos-secondary-btn"
                  id="photos-stability-enhance-btn"
                  hidden
                  data-label-idle="{{ __('AIで鮮明化') }}"
                  data-label-busy="{{ __('鮮明化中…') }}"
                >{{ __('AIで鮮明化') }}</button>
                <button
                  type="button"
                  class="photos-secondary-btn photos-danger-btn"
                  id="photos-stability-enhance-cancel-btn"
                  hidden
                  data-label-idle="{{ __('鮮明化を中止') }}"
                  data-label-busy="{{ __('中止中…') }}"
                >{{ __('鮮明化を中止') }}</button>
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
            {{ __('無料枠') }}: {{ $storageStats['formattedCombinedQuota'] }}
            @if(!empty($storageStats['archiveEnabled']))
              （{{ __('内訳目安') }} R2 {{ $storageStats['formattedQuota'] }} + B2 {{ $storageStats['formattedB2Quota'] }}）
            @endif
            · {{ __('現在の使用') }} {{ $storageStats['formattedTotalUsed'] ?? $storageStats['formattedUsed'] }}
            · {{ __('超過課金見込') }} {{ $storageStats['estimatedTotalBillLabel'] }}
            @if(($storageStats['billingMode'] ?? '') === 'estimate')
              · {{ __('現在は見込表示のみ（実課金は今後）') }}
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
                      <dt>{{ ($provider['id'] ?? '') === 'stability' ? __('クレジット残高') : __('使用量') }}</dt>
                      <dd>{{ $provider['usedLabel'] }}</dd>
                    </div>
                    <div>
                      <dt>{{ __('無料枠') }}</dt>
                      <dd>{{ $provider['quotaLabel'] }}</dd>
                    </div>
                    @if(($provider['meter'] ?? 'bytes') === 'bytes')
                      <div>
                        <dt>{{ __('件数') }}</dt>
                        <dd>{{ __('（:count枚）', ['count' => $provider['count']]) }}</dd>
                      </div>
                      <div>
                        <dt>{{ __('使用率') }}</dt>
                        <dd>{{ $provider['percent'] }}%</dd>
                      </div>
                    @elseif(in_array(($provider['id'] ?? ''), ['stability', 'realesrgan', 'swinir'], true))
                      <div>
                        <dt>{{ __('鮮明化件数') }}</dt>
                        <dd>{{ $provider['countLabel'] ?? __('鮮明化 :count 件', ['count' => $provider['count']]) }}</dd>
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
                  @if(!empty($provider['billingBreakdown']) && is_array($provider['billingBreakdown']))
                    <dl class="photos-usage-dl photos-usage-breakdown">
                      @foreach($provider['billingBreakdown'] as $row)
                        <div>
                          <dt>{{ $row['label'] ?? '' }}</dt>
                          <dd>{{ $row['amount'] ?? '' }}</dd>
                        </div>
                      @endforeach
                    </dl>
                  @endif
                  <p class="photos-usage-note">{{ $provider['billingNote'] }}</p>
                  @if(($provider['meter'] ?? 'bytes') === 'bytes')
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

    <div class="modal modal-centered" id="photos-sim-modal" hidden>
      <div class="modal-backdrop" data-close-sim-modal></div>
      <div class="modal-dialog photos-usage-dialog photos-sim-dialog" role="dialog" aria-labelledby="photos-sim-modal-title">
        <div class="modal-header">
          <h2 id="photos-sim-modal-title">{{ __('料金シミュレーション') }}</h2>
          <button type="button" class="modal-close" data-close-sim-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <div class="photos-usage-body photos-sim-body">
          <p class="photos-usage-lead">
            {{ __('全体容量を R2 / B2 に振り分け、転送・操作の仮条件から月額概算を出します。実際の請求とは異なる場合があります。') }}
          </p>

          <div class="photos-sim-layout">
            <form id="photos-sim-form" class="photos-sim-form" autocomplete="off">
              <fieldset class="photos-sim-fieldset">
                <legend>{{ __('全体容量') }}</legend>
                <div class="photos-sim-row">
                  <label class="photos-sim-grow">
                    <span>{{ __('容量') }}</span>
                    <input type="number" id="photos-sim-total" name="total" min="0" step="0.1" value="1" />
                  </label>
                  <label>
                    <span>{{ __('単位') }}</span>
                    <select id="photos-sim-unit" name="unit">
                      <option value="gb">GB</option>
                      <option value="tb" selected>TB</option>
                    </select>
                  </label>
                </div>
                <div class="photos-sim-presets" role="group" aria-label="{{ __('容量プリセット') }}">
                  <button type="button" class="photos-secondary-btn" data-sim-total-gb="100">100 GB</button>
                  <button type="button" class="photos-secondary-btn" data-sim-total-gb="500">500 GB</button>
                  <button type="button" class="photos-secondary-btn" data-sim-total-gb="1024">1 TB</button>
                  <button type="button" class="photos-secondary-btn" data-sim-total-gb="5120">5 TB</button>
                </div>
              </fieldset>

              <fieldset class="photos-sim-fieldset">
                <legend>{{ __('振り分け') }}</legend>
                <label>
                  <span>{{ __('方法') }}</span>
                  <select id="photos-sim-split" name="split">
                    <option value="r2_free_rest_b2">{{ __('R2無料枠(10GB)＋残りをB2') }}</option>
                    <option value="free_then_b2">{{ __('R2/B2無料枠(各10GB)＋残りをB2') }}</option>
                    <option value="free_then_r2">{{ __('R2/B2無料枠(各10GB)＋残りをR2') }}</option>
                    <option value="fifty_fifty">{{ __('半々（50% / 50%）') }}</option>
                    <option value="r2_only">{{ __('すべて R2') }}</option>
                    <option value="b2_only">{{ __('すべて B2') }}</option>
                    <option value="custom">{{ __('手動（R2比率）') }}</option>
                  </select>
                </label>
                <label id="photos-sim-r2-pct-wrap" hidden>
                  <span>{{ __('R2 に置く割合') }}: <strong id="photos-sim-r2-pct-label">50</strong>%</span>
                  <input type="range" id="photos-sim-r2-pct" name="r2_pct" min="0" max="100" step="1" value="50" />
                </label>
                <p class="photos-sim-split-summary" id="photos-sim-split-summary" aria-live="polite"></p>
              </fieldset>

              <fieldset class="photos-sim-fieldset">
                <legend>{{ __('転送・操作（仮入力・月）') }}</legend>
                <label>
                  <span>{{ __('B2 からの転送量') }} (GB)</span>
                  <input type="number" id="photos-sim-b2-egress" name="b2_egress_gb" min="0" step="0.1" value="0" />
                </label>
                <p class="hint photos-sim-hint">{{ __('一覧サムネは通常 R2 側。原本を B2 から開く量だけを入れてください。保管量の約3倍まで無料です。') }}</p>
                <div class="photos-sim-row">
                  <label class="photos-sim-grow">
                    <span>{{ __('B2 Class B（読取）') }}</span>
                    <input type="number" id="photos-sim-class-b" name="class_b" min="0" step="1" value="0" />
                  </label>
                  <label class="photos-sim-grow">
                    <span>{{ __('B2 Class A（書込）') }}</span>
                    <input type="number" id="photos-sim-class-a" name="class_a" min="0" step="1" value="0" />
                  </label>
                </div>
                <p class="hint photos-sim-hint">{{ __('現行の従量プランでは Class A/B/C は無料（単価0）です。将来単価が戻った場合の感度確認用です。') }}</p>
              </fieldset>
            </form>

            <aside class="photos-sim-result" aria-live="polite">
              <h3>{{ __('月額概算') }}</h3>
              <p class="photos-sim-total" id="photos-sim-total-usd">$0/月</p>
              <p class="photos-sim-total-jpy" id="photos-sim-total-jpy">約 ¥0/月</p>
              <dl class="photos-sim-result-dl">
                <div>
                  <dt>R2 {{ __('保管料') }}</dt>
                  <dd id="photos-sim-r2-storage">$0/月</dd>
                </div>
                <div>
                  <dt>B2 {{ __('保管料') }}</dt>
                  <dd id="photos-sim-b2-storage">$0/月</dd>
                </div>
                <div>
                  <dt>R2 {{ __('転送料') }}</dt>
                  <dd id="photos-sim-r2-egress-usd">$0/月</dd>
                </div>
                <div>
                  <dt>B2 {{ __('転送料') }}</dt>
                  <dd id="photos-sim-b2-egress-usd">$0/月</dd>
                </div>
                <div>
                  <dt>B2 {{ __('操作料') }}</dt>
                  <dd id="photos-sim-b2-ops-usd">$0/月</dd>
                </div>
              </dl>
              <p class="photos-usage-note" id="photos-sim-fx-note"></p>
              <p class="photos-usage-note" id="photos-sim-result-note"></p>
            </aside>
          </div>
        </div>
        <div class="modal-actions">
          <button type="submit" class="button-link" form="photos-sim-form" id="photos-sim-run">{{ __('計算する') }}</button>
          <button type="button" class="secondary" data-close-sim-modal>{{ __('閉じる') }}</button>
        </div>
      </div>
    </div>

    @php
      $usdToJpyFallback = max(1, (float) config('photos.usd_to_jpy_fallback', 150));
      try {
          $usdToJpy = (float) \Illuminate\Support\Facades\Cache::remember('photos_sim_usd_jpy', now()->addHours(6), function () use ($usdToJpyFallback) {
              $res = \Illuminate\Support\Facades\Http::timeout(4)
                  ->acceptJson()
                  ->get('https://api.frankfurter.app/latest', [
                      'from' => 'USD',
                      'to' => 'JPY',
                  ]);
              if ($res->successful()) {
                  $rate = (float) data_get($res->json(), 'rates.JPY', 0);
                  if ($rate > 0) {
                      return $rate;
                  }
              }

              return $usdToJpyFallback;
          });
      } catch (\Throwable $e) {
          $usdToJpy = $usdToJpyFallback;
      }
      if ($usdToJpy <= 0) {
          $usdToJpy = $usdToJpyFallback;
      }

      $photosSimRates = [
        'r2FreeGb' => round(((int) config('photos.user_quota_bytes', 10 * 1024 * 1024 * 1024)) / (1024 * 1024 * 1024), 4),
        'b2FreeGb' => round(((int) config('photos.b2_quota_bytes', 10 * 1024 * 1024 * 1024)) / (1024 * 1024 * 1024), 4),
        'r2StoragePerGb' => (float) config('photos.overage_price_per_gb_month_usd', 0.015),
        'b2StoragePerGb' => (float) config('photos.b2_overage_price_per_gb_month_usd', 0.006),
        'b2EgressPerGb' => (float) config('photos.b2_egress_price_per_gb_usd', 0.01),
        'b2FreeEgressMult' => (float) config('photos.b2_free_egress_storage_multiplier', 3),
        'b2ClassAPer10k' => (float) config('photos.b2_class_a_price_per_10k_usd', 0),
        'b2ClassBPer10k' => (float) config('photos.b2_class_b_price_per_10k_usd', 0),
        'b2ClassBFreePerDay' => (int) config('photos.b2_class_b_free_per_day', 2500),
        'daysInMonth' => (int) now()->daysInMonth,
        'usdToJpy' => round($usdToJpy, 4),
        'i18n' => [
          'splitSummary' => __('振り分け結果: R2 :r2 · B2 :b2'),
          'resultNote' => __('R2 転送は無料。B2 転送の無料枠は保管 :stored の約 :mult 倍（:free）。'),
          'perMonth' => __('/月'),
          'fxNote' => __('換算レート: 1 USD ≒ ¥:rate（計算時）'),
          'approxYen' => __('約 ¥:yen/月'),
        ],
      ];
    @endphp
    <script type="application/json" id="photos-sim-rates">{!! json_encode($photosSimRates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    <script>
      (function () {
        const simModal = document.getElementById('photos-sim-modal')
        const simOpenBtn = document.getElementById('photos-sim-open')
        const form = document.getElementById('photos-sim-form')
        const ratesEl = document.getElementById('photos-sim-rates')
        if (!simModal || !form) return

        const fallbackRates = {
          r2FreeGb: 10,
          b2FreeGb: 10,
          r2StoragePerGb: 0.015,
          b2StoragePerGb: 0.006,
          b2EgressPerGb: 0.01,
          b2FreeEgressMult: 3,
          b2ClassAPer10k: 0,
          b2ClassBPer10k: 0,
          b2ClassBFreePerDay: 2500,
          daysInMonth: 30,
          usdToJpy: 150,
          i18n: {
            splitSummary: '振り分け結果: R2 :r2 · B2 :b2',
            resultNote: 'R2 転送は無料。B2 転送の無料枠は保管 :stored の約 :mult 倍（:free）。',
            perMonth: '/月',
            fxNote: '換算レート: 1 USD ≒ ¥:rate（計算時）',
            approxYen: '約 ¥:yen/月'
          }
        }

        let rates = fallbackRates
        try {
          const parsed = JSON.parse((ratesEl && ratesEl.textContent) || '{}')
          if (parsed && typeof parsed === 'object') {
            rates = Object.assign({}, fallbackRates, parsed, {
              i18n: Object.assign({}, fallbackRates.i18n, parsed.i18n || {})
            })
          }
        } catch (_) {}

        let usdJpy = Number(rates.usdToJpy) > 0 ? Number(rates.usdToJpy) : 150
        let fxFetch = null

        const totalInput = document.getElementById('photos-sim-total')
        const unitSelect = document.getElementById('photos-sim-unit')
        const splitSelect = document.getElementById('photos-sim-split')
        const r2PctWrap = document.getElementById('photos-sim-r2-pct-wrap')
        const r2PctInput = document.getElementById('photos-sim-r2-pct')
        const r2PctLabel = document.getElementById('photos-sim-r2-pct-label')
        const splitSummary = document.getElementById('photos-sim-split-summary')
        const egressInput = document.getElementById('photos-sim-b2-egress')
        const classBInput = document.getElementById('photos-sim-class-b')
        const classAInput = document.getElementById('photos-sim-class-a')
        const outTotal = document.getElementById('photos-sim-total-usd')
        const outTotalJpy = document.getElementById('photos-sim-total-jpy')
        const outR2Storage = document.getElementById('photos-sim-r2-storage')
        const outB2Storage = document.getElementById('photos-sim-b2-storage')
        const outB2Egress = document.getElementById('photos-sim-b2-egress-usd')
        const outB2Ops = document.getElementById('photos-sim-b2-ops-usd')
        const outR2Egress = document.getElementById('photos-sim-r2-egress-usd')
        const outNote = document.getElementById('photos-sim-result-note')
        const outFxNote = document.getElementById('photos-sim-fx-note')
        const perMonth = (rates.i18n && rates.i18n.perMonth) || '/月'

        function num(el, fallback) {
          const v = parseFloat(el && el.value !== undefined ? el.value : '')
          return Number.isFinite(v) ? Math.max(0, v) : (fallback || 0)
        }

        function fmtYen(usd) {
          const yen = Math.round((Number.isFinite(usd) ? usd : 0) * usdJpy)
          const yenText = yen.toLocaleString('ja-JP')
          const tpl = (rates.i18n && rates.i18n.approxYen) || '約 ¥:yen/月'
          return tpl.replace(':yen', yenText)
        }

        function money(v) {
          let usdPart = '$0' + perMonth
          if (Number.isFinite(v) && v > 0) {
            const s = v.toFixed(4).replace(/\.?0+$/, '')
            usdPart = '$' + s + perMonth
          }
          return usdPart + '（' + fmtYen(v) + '）'
        }

        function fmtGb(v) {
          if (v >= 1024) {
            const tb = v / 1024
            return tb.toFixed(tb >= 10 ? 1 : 2).replace(/\.?0+$/, '') + ' TB'
          }
          return v.toFixed(v >= 100 ? 0 : 1).replace(/\.?0+$/, '') + ' GB'
        }

        function totalGb() {
          const n = num(totalInput, 0)
          return (unitSelect && unitSelect.value === 'tb') ? n * 1024 : n
        }

        function splitGb(total) {
          const mode = (splitSelect && splitSelect.value) || 'r2_free_rest_b2'
          const r2Free = Number(rates.r2FreeGb) || 10
          const b2Free = Number(rates.b2FreeGb) || 10
          if (mode === 'r2_only') return { r2: total, b2: 0 }
          if (mode === 'b2_only') return { r2: 0, b2: total }
          if (mode === 'fifty_fifty') return { r2: total / 2, b2: total / 2 }
          if (mode === 'custom') {
            const pct = Math.min(100, Math.max(0, num(r2PctInput, 50)))
            return { r2: total * (pct / 100), b2: total * (1 - pct / 100) }
          }
          if (mode === 'free_then_r2') {
            const freeBoth = Math.min(total, r2Free + b2Free)
            const r2Part = Math.min(r2Free, freeBoth)
            const b2Part = Math.min(b2Free, Math.max(0, freeBoth - r2Part))
            return { r2: r2Part + Math.max(0, total - freeBoth), b2: b2Part }
          }
          if (mode === 'free_then_b2') {
            const freeBoth = Math.min(total, r2Free + b2Free)
            const r2Part = Math.min(r2Free, freeBoth)
            const b2Part = Math.min(b2Free, Math.max(0, freeBoth - r2Part))
            return { r2: r2Part, b2: b2Part + Math.max(0, total - freeBoth) }
          }
          const r2 = Math.min(r2Free, total)
          return { r2: r2, b2: Math.max(0, total - r2) }
        }

        function ensureFx() {
          if (fxFetch) return fxFetch
          fxFetch = fetch('https://api.frankfurter.app/latest?from=USD&to=JPY')
            .then(function (res) { return res.ok ? res.json() : Promise.reject() })
            .then(function (data) {
              const rate = Number(data && data.rates && data.rates.JPY)
              if (Number.isFinite(rate) && rate > 0) usdJpy = rate
              return usdJpy
            })
            .catch(function () { return usdJpy })
          return fxFetch
        }

        function calc() {
          const total = totalGb()
          const parts = splitGb(total)
          const r2 = parts.r2
          const b2 = parts.b2
          const r2Free = Number(rates.r2FreeGb) || 10
          const b2Free = Number(rates.b2FreeGb) || 10
          const r2Storage = Math.max(0, r2 - r2Free) * (Number(rates.r2StoragePerGb) || 0)
          const b2Storage = Math.max(0, b2 - b2Free) * (Number(rates.b2StoragePerGb) || 0)
          const egressGb = num(egressInput, 0)
          const freeEgress = b2 * (Number(rates.b2FreeEgressMult) || 3)
          const b2EgressUsd = Math.max(0, egressGb - freeEgress) * (Number(rates.b2EgressPerGb) || 0)
          const classA = num(classAInput, 0)
          const classB = num(classBInput, 0)
          const classAPrice = Number(rates.b2ClassAPer10k) || 0
          const classBPrice = Number(rates.b2ClassBPer10k) || 0
          const freeB = classBPrice > 0
            ? (Number(rates.b2ClassBFreePerDay) || 0) * (Number(rates.daysInMonth) || 30)
            : Number.POSITIVE_INFINITY
          const b2OpsUsd = (classA / 10000) * classAPrice + (Math.max(0, classB - freeB) / 10000) * classBPrice
          const grand = r2Storage + b2Storage + b2EgressUsd + b2OpsUsd

          if (outTotal) {
            if (Number.isFinite(grand) && grand > 0) {
              const s = grand.toFixed(4).replace(/\.?0+$/, '')
              outTotal.textContent = '$' + s + perMonth
            } else {
              outTotal.textContent = '$0' + perMonth
            }
          }
          if (outTotalJpy) outTotalJpy.textContent = fmtYen(grand)
          if (outR2Storage) outR2Storage.textContent = money(r2Storage)
          if (outB2Storage) outB2Storage.textContent = money(b2Storage)
          if (outB2Egress) outB2Egress.textContent = money(b2EgressUsd)
          if (outB2Ops) outB2Ops.textContent = money(b2OpsUsd)
          if (outR2Egress) outR2Egress.textContent = money(0)
          if (splitSummary) {
            const tpl = (rates.i18n && rates.i18n.splitSummary) || 'R2 :r2 · B2 :b2'
            splitSummary.textContent = tpl.replace(':r2', fmtGb(r2)).replace(':b2', fmtGb(b2))
          }
          if (outFxNote) {
            const tpl = (rates.i18n && rates.i18n.fxNote) || '1 USD ≒ ¥:rate'
            outFxNote.textContent = tpl.replace(':rate', usdJpy.toFixed(2).replace(/\.?0+$/, ''))
          }
          if (outNote) {
            const tpl = (rates.i18n && rates.i18n.resultNote) || ''
            outNote.textContent = tpl
              .replace(':stored', fmtGb(b2))
              .replace(':mult', String(rates.b2FreeEgressMult || 3))
              .replace(':free', fmtGb(freeEgress))
          }
          if (r2PctLabel && r2PctInput) r2PctLabel.textContent = String(Math.round(num(r2PctInput, 50)))
          if (r2PctWrap) r2PctWrap.hidden = !(splitSelect && splitSelect.value === 'custom')
          return false
        }

        function calcWithFx() {
          calc()
          ensureFx().then(function () { calc() })
        }

        function openSim() {
          simModal.hidden = false
          calcWithFx()
        }

        if (simOpenBtn) simOpenBtn.addEventListener('click', openSim)
        document.querySelectorAll('[data-close-sim-modal]').forEach(function (el) {
          el.addEventListener('click', function () { simModal.hidden = true })
        })

        form.addEventListener('submit', function (e) {
          e.preventDefault()
          calcWithFx()
        })

        ;[totalInput, unitSelect, splitSelect, r2PctInput, egressInput, classBInput, classAInput].forEach(function (el) {
          if (!el) return
          el.addEventListener('input', calc)
          el.addEventListener('change', calc)
        })

        document.querySelectorAll('[data-sim-total-gb]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const gb = parseFloat(btn.getAttribute('data-sim-total-gb') || '0')
            if (!Number.isFinite(gb) || gb <= 0) return
            if (gb >= 1024 && gb % 1024 === 0) {
              if (unitSelect) unitSelect.value = 'tb'
              if (totalInput) totalInput.value = String(gb / 1024)
            } else {
              if (unitSelect) unitSelect.value = 'gb'
              if (totalInput) totalInput.value = String(gb)
            }
            calcWithFx()
          })
        })
      })()
    </script>

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
          <label>
            {{ __('パスワード（任意）') }}
            <input type="password" name="password" id="photos-album-password" minlength="4" maxlength="72" placeholder="{{ __('4文字以上・空欄でなし') }}" autocomplete="new-password" />
          </label>
          <p class="hint" id="photos-album-password-hint">{{ __('設定すると、開くときにパスワードが必要です。') }}</p>
          <label class="inline-check" id="photos-album-clear-password-wrap" hidden>
            <input type="checkbox" name="clear_password" value="1" id="photos-album-clear-password" />
            <span>{{ __('パスワードを解除する') }}</span>
          </label>
          <label class="inline-check">
            <input type="checkbox" name="is_hidden" value="1" id="photos-album-hidden" />
            <span>{{ __('隠しアルバム（一覧に出さない・本人のみ）') }}</span>
          </label>
          <p class="hint">{{ __('隠しアルバムはタイトル「Photos」を7回タップすると表示／非表示を切り替えられます。') }}</p>
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
        const uploadsBlocked = @json(!empty($storageStats['uploadsBlocked']));
        const uploadsBlockedMessage = @json(__('無料枠を超えているため追加できません。有料プラン連携後に超過利用が可能になります。'));
        const selectedAlbumId = @json($selectedAlbumId);
        const coverPhotoId = @json($selectedAlbum['coverPhotoId'] ?? null);
        const fileInput = document.getElementById('photos-file-input')
        const filesBrowseInput = document.getElementById('photos-files-input')
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
        const cameraInput = document.getElementById('photos-camera-input')
        const cameraVideoInput = document.getElementById('photos-camera-video-input')
        const folderWatchBtn = document.getElementById('photos-folder-watch-btn')
        const folderWatchStop = document.getElementById('photos-folder-watch-stop')
        const folderWatchStatus = document.getElementById('photos-folder-watch-status')
        const uploadLabel = document.querySelector('.photos-hero-actions .photos-upload-btn-label')
          || document.querySelector('#photos-add-open .photos-upload-btn-label')
        const addOpenBtn = document.getElementById('photos-add-open')
        const addSheet = document.getElementById('photos-add-sheet')
        const pendingBar = document.getElementById('photos-pending-bar')
        const pendingCount = document.getElementById('photos-pending-count')
        const pendingAddBtn = document.getElementById('photos-pending-add')
        const pendingMoreBtn = document.getElementById('photos-pending-more')
        const pendingCancelBtn = document.getElementById('photos-pending-cancel')
        const uploadAlbumSelects = Array.from(document.querySelectorAll('[data-upload-album]'))
        const uploadAlbumField = document.getElementById('photos-form-album-id')
        let pendingFiles = null
        let lastPickMode = 'files'
        const photosSortSelect = document.getElementById('photos-sort-select')
        const photosYearSelect = document.getElementById('photos-year-select')
        let currentIndex = 0
        let deferredPrompt = null
        let uploading = false
        let uploadCancelRequested = false
        let activeUploadXhr = null
        const pageJob = window.PhotosPageJob
        let folderWatch = {
          handle: null,
          timer: null,
          seen: new Set(),
          added: 0,
          name: '',
        }

        const takeoutTakenAtByFile = new WeakMap()

        function takenAtHintFromFile(file) {
          const ms = Number(file?.lastModified) || 0
          if (!ms) return ''
          try {
            return new Date(ms).toISOString()
          } catch (_) {
            return ''
          }
        }

        /** Google Takeout: video.mp4.supplemental-metadata.json や短縮 .suppl.json など */
        function isTakeoutMetadataJsonFileName(mediaName, jsonName) {
          if (!mediaName || !jsonName) return false
          const json = String(jsonName)
          if (!/\.json$/i.test(json)) return false
          const media = String(mediaName)
          if (json.length < media.length + 5) return false
          if (!json.toLowerCase().startsWith(media.toLowerCase())) return false
          const suffix = json.slice(media.length)
          if (suffix === '.json') return true
          if (/\.suppl/i.test(suffix)) return true
          return false
        }

        function takenAtIsoFromTakeoutMetadata(data) {
          if (!data || typeof data !== 'object') return ''
          const keys = ['photoTakenTime', 'creationTime', 'modificationTime']
          for (const key of keys) {
            const raw = data[key]?.timestamp
            if (raw == null || String(raw).trim() === '') continue
            const sec = parseInt(String(raw).trim(), 10)
            if (!Number.isFinite(sec) || sec <= 0) continue
            try {
              return new Date(sec * 1000).toISOString()
            } catch (_) {}
          }
          return ''
        }

        async function readTakeoutTakenAtFromJsonFile(jsonFile) {
          try {
            const text = await jsonFile.text()
            const data = JSON.parse(text)
            return takenAtIsoFromTakeoutMetadata(data)
          } catch (_) {
            return ''
          }
        }

        function findTakeoutJsonForMedia(mediaName, jsonFiles) {
          for (const json of jsonFiles) {
            if (isTakeoutMetadataJsonFileName(mediaName, json.name || '')) {
              return json
            }
          }
          return null
        }

        async function buildTakeoutTakenAtMap(files) {
          const map = new Map()
          const list = Array.from(files || [])
          const jsonFiles = list.filter((f) => /\.json$/i.test(f.name || '') && !isMediaFile(f))
          for (const file of list) {
            if (!isMediaFile(file)) continue
            const cached = takeoutTakenAtByFile.get(file)
            if (cached) {
              map.set(file, cached)
              continue
            }
            const json = findTakeoutJsonForMedia(file.name, jsonFiles)
            if (!json) continue
            const hint = await readTakeoutTakenAtFromJsonFile(json)
            if (hint) map.set(file, hint)
          }
          return map
        }

        function takenAtHintForUpload(file, takeoutMap) {
          const fromTakeout = takeoutMap?.get(file) || takeoutTakenAtByFile.get(file)
          if (fromTakeout) return fromTakeout
          return takenAtHintFromFile(file)
        }

        function applyPhotosListQuery(patch = {}) {
          if (pageJob?.isBusy() && !pageJob.requestLeave()) return
          const url = new URL(window.location.href)
          const next = {
            album: url.searchParams.get('album') || '',
            sort: photosSortSelect?.value || 'taken_desc',
            year: photosYearSelect?.value || '',
            ...patch,
          }
          ;['album', 'sort', 'year'].forEach((key) => url.searchParams.delete(key))
          if (next.album) url.searchParams.set('album', next.album)
          if (next.sort && next.sort !== 'taken_desc') url.searchParams.set('sort', next.sort)
          if (next.year) url.searchParams.set('year', next.year)
          window.location.assign(url.pathname + url.search + url.hash)
        }

        photosSortSelect?.addEventListener('change', () => applyPhotosListQuery())
        photosYearSelect?.addEventListener('change', () => applyPhotosListQuery())

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
              'chunkBytes' => 8388608,
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
            activeUploadXhr = xhr
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
            const clearActive = () => {
              if (activeUploadXhr === xhr) activeUploadXhr = null
            }
            xhr.onload = () => {
              clearActive()
              let data = null
              try { data = JSON.parse(xhr.responseText || '') } catch (_) {}
              resolve({
                ok: xhr.status >= 200 && xhr.status < 300,
                status: xhr.status,
                data,
              })
            }
            xhr.onerror = () => { clearActive(); reject(new Error('network')) }
            xhr.ontimeout = () => { clearActive(); reject(new Error('timeout')) }
            xhr.onabort = () => { clearActive(); reject(new Error('aborted')) }
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

        async function uploadFileChunked(file, { albumId, returnTo, thumb, fileLabel, onProgress, allowDuplicates, takeoutMap, contentHash }) {
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
          const takenHint = takenAtHintForUpload(file, takeoutMap)
          if (takenHint) done.append('taken_at', takenHint)
          if (contentHash) done.append('content_hash', contentHash)
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

        async function uploadFileSimple(file, { albumId, returnTo, thumb, fileLabel, onProgress, allowDuplicates, takeoutMap, contentHash }) {
          const fd = new FormData()
          fd.append('returnTo', returnTo)
          if (albumId) fd.append('album_id', albumId)
          if (allowDuplicates) fd.append('allow_duplicates', '1')
          fd.append('photos[]', file, file.name)
          const takenHint = takenAtHintForUpload(file, takeoutMap)
          if (takenHint) fd.append('taken_ats[]', takenHint)
          if (contentHash) fd.append('content_hashes[]', contentHash)
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

        async function submitFiles(fileList, options = {}) {
          const soft = !!options.soft
          if (!fileList?.length || !form || uploading) return { created: 0, skipped: 0, failed: 0 }
          if (uploadsBlocked) {
            if (!soft) window.alert(uploadsBlockedMessage)
            return { created: 0, skipped: 0, failed: fileList.length }
          }
          if (!soft && pageJob?.isBusy()) {
            if (pageJob.getKind() === 'upload') return { created: 0, skipped: 0, failed: 0 }
            if (!pageJob.requestLeave()) return { created: 0, skipped: 0, failed: 0 }
          }
          uploading = true
          uploadCancelRequested = false
          if (!soft && pageJob) {
            pageJob.start('upload', () => {
              uploadCancelRequested = true
              try { activeUploadXhr?.abort() } catch (_) {}
            })
          }
          const list = Array.from(fileList)
          const rejected = []
          const mediaList = []
          let jsonCompanionCount = 0
          for (const file of list) {
            const displayName = file.name || 'file'
            if (/\.json$/i.test(displayName) && !isMediaFile(file)) {
              // Takeout の付属 JSON は日付取得用。取り込み対象外として件数に出さない
              jsonCompanionCount += 1
              continue
            }
            if (!isMediaFile(file)) {
              rejected.push(`${displayName}: 対象外の形式`)
              continue
            }
            const isVideo = file.type.startsWith('video/') || /\.(mp4|mov|webm|avi|mkv|m4v)$/i.test(displayName)
            const isMp4 = file.type === 'video/mp4' || /\.mp4$/i.test(displayName)
            if (isVideo && !isMp4) {
              rejected.push(`${displayName}: 動画はMP4のみ対応`)
              continue
            }
            mediaList.push(file)
          }
          const takeoutMap = await buildTakeoutTakenAtMap(list)
          const totalSelected = mediaList.length
          const albumId = currentUploadAlbumId()
          const returnTo = uploadReturnTo(albumId)
          const allowDuplicates = allowDuplicatesChecked()
          let totalCreated = 0
          let totalSkipped = 0
          const failed = []
          const duplicateNames = []

          const videoMax = Number(uploadLimits.videoMaxBytes) || (800 * 1024 * 1024)
          try {
            if (totalSelected === 0 && rejected.length === 0 && list.length > 0) {
              setUploadProgress(@json(__('取り込み対象の写真・動画がありません')))
            } else if (totalSelected === 0) {
              setUploadProgress(@json(__('対象ファイルを確認中…')))
            } else {
              setUploadProgress(`重複チェック中… 0/${totalSelected}`)
            }
            const hashed = []
            for (let i = 0; i < mediaList.length; i++) {
              const file = mediaList[i]
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
                  duplicateNames.push(item.displayName)
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
              if (uploadCancelRequested) break
              const { file, displayName, hash } = queue[i]
              const fileLabel = `${i + 1}/${queue.length}`

              setUploadProgress(`準備中… ${fileLabel}`)
              // 動画サムネは時間をかけない（失敗時はサーバー側プレースホルダ）
              let thumb = null
              if (isVideoFile(file)) {
                setUploadProgress(`サムネ準備… ${fileLabel}`)
                thumb = await captureVideoThumb(file, 800)
              }

              try {
                const onProgress = (_pct, text) => setUploadProgress(text)
                let result = { count: 0, skipped: 0 }
                let lastErr = null
                for (let attempt = 1; attempt <= 2; attempt++) {
                  if (uploadCancelRequested) break
                  try {
                    result = shouldUseChunkedUpload(file)
                      ? await uploadFileChunked(file, { albumId, returnTo, thumb, fileLabel, onProgress, allowDuplicates, takeoutMap, contentHash: hash })
                      : await uploadFileSimple(file, { albumId, returnTo, thumb, fileLabel, onProgress, allowDuplicates, takeoutMap, contentHash: hash })
                    lastErr = null
                    break
                  } catch (err) {
                    lastErr = err
                    if (err?.message === 'aborted' || uploadCancelRequested) break
                    if (attempt < 2) {
                      setUploadProgress(`再試行… ${fileLabel}`)
                      await sleep(1000)
                    }
                  }
                }
                if (uploadCancelRequested || lastErr?.message === 'aborted') break
                if (lastErr) throw lastErr
                totalCreated += result.count
                if (result.skipped > 0) {
                  totalSkipped += result.skipped
                  duplicateNames.push(`${displayName}（サーバー側）`)
                }
                setUploadProgress(`完了… 追加${totalCreated} / スキップ${totalSkipped}`)
              } catch (err) {
                if (err?.message === 'aborted' || uploadCancelRequested) break
                const reason = err?.message === 'timeout'
                  ? 'タイムアウト'
                  : (err?.message === 'network' ? '通信エラー' : (err?.message || '失敗'))
                failed.push(`${displayName}: ${reason}`)
                setUploadProgress(`失敗… ${fileLabel} → 次へ`)
                await sleep(300)
              }
            }

            const formatDetailBlock = (title, rows, limit = 8) => {
              if (!rows.length) return ''
              const shown = rows.slice(0, limit)
              const more = rows.length > limit ? `\n…他 ${rows.length - limit}件` : ''
              return `\n\n${title}:\n- ${shown.join('\n- ')}${more}`
            }

            const buildResultNotice = () => {
              const parts = []
              if (totalCreated > 0) parts.push(`${totalCreated}件追加`)
              if (totalSkipped > 0) parts.push(`重複スキップ ${totalSkipped}件`)
              if (rejected.length > 0) parts.push(`対象外 ${rejected.length}件`)
              if (failed.length > 0) parts.push(`失敗 ${failed.length}件`)
              if (parts.length) return parts.join(' · ')
              if (list.length === 0) return 'ファイルが選択されていません'
              if (jsonCompanionCount > 0 && mediaList.length === 0) {
                return '写真・動画がありません（メタデータJSONのみ）'
              }
              return '追加できるファイルがありませんでした'
            }

            const showResultAlert = (notice) => {
              let body = notice
              body += formatDetailBlock('重複（既に登録済み）', duplicateNames)
              body += formatDetailBlock('対象外', rejected)
              body += formatDetailBlock('失敗', failed)
              if (!totalCreated && !totalSkipped && !rejected.length && !failed.length) {
                if (jsonCompanionCount > 0) {
                  body += '\n\n選択されたのは付属のメタデータJSONのみです。写真・動画ファイルを選んでください。'
                } else {
                  body += '\n\n対応形式: 画像（JPEG/PNG/WebP/GIF/HEIC 等）と動画（MP4）'
                }
              }
              window.alert(body)
            }

            if (soft) {
              if (totalCreated > 0 || totalSkipped > 0 || failed.length || rejected.length) {
                setUploadProgress(buildResultNotice() || @json(__('写真・動画を追加')))
              }
              return { created: totalCreated, skipped: totalSkipped, failed: failed.length }
            }

            if (uploadCancelRequested) {
              return { created: totalCreated, skipped: totalSkipped, failed: failed.length }
            }

            const url = new URL(returnTo, window.location.origin)
            const notice = buildResultNotice()
            const hasDetails = totalSkipped > 0 || rejected.length > 0 || failed.length > 0 || totalCreated === 0

            if (hasDetails) {
              showResultAlert(notice)
            }

            if (totalCreated > 0 || totalSkipped > 0 || rejected.length > 0) {
              url.searchParams.set('notice', notice)
            } else if (failed.length) {
              url.searchParams.set('error', failed[0])
            } else {
              url.searchParams.set('notice', notice)
            }
            if (pageJob) pageJob.end('upload')
            window.location.assign(url.pathname + url.search + url.hash)
            return { created: totalCreated, skipped: totalSkipped, failed: failed.length }
          } catch (_) {
            if (!soft && !uploadCancelRequested) {
              window.alert(@json(__('ファイルの処理に失敗しました。別の形式で試してください。')));
            }
            return { created: totalCreated, skipped: totalSkipped, failed: failed.length + 1 }
          } finally {
            uploading = false
            uploadCancelRequested = false
            activeUploadXhr = null
            if (!soft && pageJob) pageJob.end('upload')
            if (!soft) {
              setUploadProgress(@json(__('写真・動画を追加')));
            } else if (!folderWatch.timer) {
              setUploadProgress(@json(__('写真・動画を追加')));
            }
          }
        }

        /** 追加先アルバム。空文字なら標準（アルバムなし）。 */
        function currentUploadAlbumId() {
          if (uploadAlbumSelects.length) return uploadAlbumSelects[0].value || ''
          return uploadAlbumField?.value || ''
        }

        function syncUploadAlbumId(value) {
          const next = value || ''
          uploadAlbumSelects.forEach((select) => {
            if (select.value !== next) select.value = next
          })
          if (uploadAlbumField) uploadAlbumField.value = next
        }

        uploadAlbumSelects.forEach((select) => {
          select.addEventListener('change', () => syncUploadAlbumId(select.value))
        })
        syncUploadAlbumId(currentUploadAlbumId())

        /** 追加した先が今見ている場所と違うときは、そのアルバムを開き直す */
        function uploadReturnTo(albumId) {
          const base = form?.querySelector('input[name="returnTo"]')?.value || '/photos'
          const target = String(albumId || '')
          if (target === String(selectedAlbumId || '')) return base
          return target ? `/photos?album=${encodeURIComponent(target)}` : '/photos'
        }

        function openAddSheet() {
          if (!addSheet) {
            triggerFilesBrowse()
            return
          }
          addSheet.hidden = false
        }

        function closeAddSheet() {
          if (addSheet) addSheet.hidden = true
        }

        function triggerUpload() {
          openAddSheet()
        }

        function triggerFilesBrowse() {
          const input = filesBrowseInput || fileInput
          if (!input) return
          lastPickMode = 'files'
          closeAddSheet()
          input.value = ''
          input.click()
        }

        function triggerGalleryBrowse() {
          if (!fileInput) return
          lastPickMode = 'gallery'
          closeAddSheet()
          fileInput.value = ''
          fileInput.click()
        }

        function triggerCameraCapture() {
          if (!cameraInput) return
          closeAddSheet()
          cameraInput.value = ''
          cameraInput.click()
        }

        function triggerVideoCapture() {
          if (!cameraVideoInput) return
          closeAddSheet()
          cameraVideoInput.value = ''
          cameraVideoInput.click()
        }

        function triggerLastPickBrowse() {
          if (lastPickMode === 'gallery') {
            triggerGalleryBrowse()
            return
          }
          triggerFilesBrowse()
        }

        function filePickKey(file) {
          return `${file?.name || ''}|${file?.size || 0}|${file?.lastModified || 0}`
        }

        function isMediaFile(file) {
          return !!file && (
            file.type.startsWith('image/') ||
            file.type === 'video/mp4' ||
            file.type.startsWith('video/') ||
            /\.(heic|heif|mp4|mov|jpeg|jpg|png|webp|gif)$/i.test(file.name || '')
          )
        }

        function fileWatchKey(file) {
          return `${file.name}|${file.size}|${file.lastModified}`
        }

        function setFolderWatchStatus(text, visible = true) {
          if (!folderWatchStatus) return
          folderWatchStatus.hidden = !visible
          folderWatchStatus.textContent = text || ''
        }

        function stopFolderWatch() {
          if (folderWatch.timer) {
            clearInterval(folderWatch.timer)
            folderWatch.timer = null
          }
          folderWatch.handle = null
          if (folderWatchBtn) folderWatchBtn.hidden = !(typeof window.showDirectoryPicker === 'function')
          if (folderWatchStop) folderWatchStop.hidden = true
          setFolderWatchStatus('', false)
        }

        async function collectNewFilesFromDir(dirHandle, { markSeen = false, depth = 0, maxDepth = 4 } = {}) {
          const files = []
          const jsonCandidates = []
          const mediaCandidates = []
          for await (const entry of dirHandle.values()) {
            if (entry.kind === 'directory') {
              if (depth < maxDepth) {
                try {
                  const nested = await collectNewFilesFromDir(entry, { markSeen, depth: depth + 1, maxDepth })
                  files.push(...nested)
                } catch (_) {}
              }
              continue
            }
            if (entry.kind !== 'file') continue
            try {
              const file = await entry.getFile()
              if (/\.json$/i.test(file.name || '') && !isMediaFile(file)) {
                jsonCandidates.push(file)
                continue
              }
              if (!isMediaFile(file)) continue
              if (file.type.startsWith('video/') && file.type !== 'video/mp4' && !/\.mp4$/i.test(file.name || '')) {
                continue
              }
              mediaCandidates.push(file)
            } catch (_) {}
          }

          const takeoutMap = await buildTakeoutTakenAtMap([...mediaCandidates, ...jsonCandidates])
          for (const file of mediaCandidates) {
            const hint = takeoutMap.get(file)
            if (hint) takeoutTakenAtByFile.set(file, hint)
            const key = fileWatchKey(file)
            if (folderWatch.seen.has(key)) continue
            if (markSeen) folderWatch.seen.add(key)
            files.push(file)
          }
          return files
        }

        async function pollFolderWatch() {
          if (!folderWatch.handle || uploading) return
          try {
            if (typeof folderWatch.handle.requestPermission === 'function') {
              const permission = await folderWatch.handle.queryPermission?.({ mode: 'read' })
              if (permission !== 'granted') {
                const next = await folderWatch.handle.requestPermission({ mode: 'read' })
                if (next !== 'granted') {
                  setFolderWatchStatus(@json(__('フォルダ権限が切れました。監視を停止しました。')))
                  stopFolderWatch()
                  if (folderWatchBtn) folderWatchBtn.hidden = false
                  return
                }
              }
            }
            const newcomers = await collectNewFilesFromDir(folderWatch.handle, { markSeen: false })
            if (!newcomers.length) {
              setFolderWatchStatus(
                @json(__('監視中: :name（追加 :count 件）'))
                  .replace(':name', folderWatch.name || 'folder')
                  .replace(':count', String(folderWatch.added))
              )
              return
            }
            setFolderWatchStatus(
              @json(__('監視中: 新規 :n 件を追加中…')).replace(':n', String(newcomers.length))
            )
            const result = await submitFiles(newcomers, { soft: true })
            newcomers.forEach((file) => folderWatch.seen.add(fileWatchKey(file)))
            folderWatch.added += result.created || 0
            setFolderWatchStatus(
              @json(__('監視中: :name（追加 :count 件）'))
                .replace(':name', folderWatch.name || 'folder')
                .replace(':count', String(folderWatch.added))
            )
            if ((result.created || 0) > 0) {
              window.location.reload()
            }
          } catch (err) {
            setFolderWatchStatus(@json(__('フォルダ監視でエラーが発生しました。')))
          }
        }

        async function startFolderWatch() {
          if (typeof window.showDirectoryPicker !== 'function') {
            window.alert(@json(__('このブラウザではフォルダ監視に対応していません。Chrome などの PC ブラウザでお試しください。スマホでは「写真・動画を追加」を使ってください。')))
            return
          }
          try {
            const handle = await window.showDirectoryPicker({ mode: 'read' })
            folderWatch.handle = handle
            folderWatch.name = handle.name || 'folder'
            folderWatch.added = 0
            folderWatch.seen = new Set()
            await collectNewFilesFromDir(handle, { markSeen: true })
            if (folderWatchBtn) folderWatchBtn.hidden = true
            if (folderWatchStop) folderWatchStop.hidden = false
            setFolderWatchStatus(
              @json(__('監視中: :name（サブフォルダ含む・追加 :count 件）'))
                .replace(':name', folderWatch.name)
                .replace(':count', '0')
            )
            if (folderWatch.timer) clearInterval(folderWatch.timer)
            folderWatch.timer = setInterval(() => { void pollFolderWatch() }, 5000)
            void pollFolderWatch()
          } catch (err) {
            if (err && err.name === 'AbortError') return
            window.alert(@json(__('フォルダを開けませんでした。')))
          }
        }

        document.getElementById('photos-empty-add')?.addEventListener('click', (e) => {
          e.preventDefault()
          openAddSheet()
        })
        addOpenBtn?.addEventListener('click', (e) => {
          e.preventDefault()
          openAddSheet()
        })
        document.getElementById('photos-add-sheet-files')?.addEventListener('click', () => triggerFilesBrowse())
        document.getElementById('photos-add-sheet-pick')?.addEventListener('click', () => triggerGalleryBrowse())
        document.getElementById('photos-add-sheet-camera')?.addEventListener('click', () => triggerCameraCapture())
        document.getElementById('photos-add-sheet-video')?.addEventListener('click', () => triggerVideoCapture())
        document.getElementById('photos-add-sheet-cancel')?.addEventListener('click', () => closeAddSheet())
        document.getElementById('photos-add-sheet-backdrop')?.addEventListener('click', () => closeAddSheet())

        function prefersUploadConfirm() {
          try {
            return window.matchMedia('(pointer: coarse)').matches
              || window.matchMedia('(max-width: 820px)').matches
          } catch (_) {
            return true
          }
        }

        function clearPendingFiles() {
          pendingFiles = null
          if (pendingBar) pendingBar.hidden = true
          if (pendingCount) pendingCount.textContent = ''
          if (fileInput) fileInput.value = ''
          if (filesBrowseInput) filesBrowseInput.value = ''
        }

        function renderPendingBar() {
          const files = pendingFiles || []
          if (!files.length) {
            clearPendingFiles()
            return
          }
          if (pendingCount) {
            const names = files.slice(0, 3).map((f) => f.name || 'file')
            const more = files.length > 3 ? ` …他${files.length - 3}件` : ''
            pendingCount.textContent = @json(__(':count件選択中')).replace(':count', String(files.length))
              + (names.length ? `（${names.join('、')}${more}）` : '')
          }
          if (pendingBar) {
            pendingBar.hidden = false
            try { pendingBar.scrollIntoView({ behavior: 'smooth', block: 'nearest' }) } catch (_) {}
          }
        }

        function showPendingFiles(fileList, { append = false } = {}) {
          const incoming = Array.from(fileList || [])
          if (!incoming.length) {
            if (!append) clearPendingFiles()
            return
          }
          if (append && Array.isArray(pendingFiles) && pendingFiles.length) {
            const seen = new Set(pendingFiles.map(filePickKey))
            for (const file of incoming) {
              const key = filePickKey(file)
              if (seen.has(key)) continue
              seen.add(key)
              pendingFiles.push(file)
            }
          } else {
            pendingFiles = incoming
          }
          renderPendingBar()
        }

        function enqueueSelectedFiles(fileList, { fromCamera = false } = {}) {
          const files = Array.from(fileList || [])
          if (!files.length) return
          if (fromCamera) {
            clearPendingFiles()
            void submitFiles(files)
            return
          }
          if (!prefersUploadConfirm() && !(pendingFiles && pendingFiles.length)) {
            clearPendingFiles()
            void submitFiles(files)
            return
          }
          showPendingFiles(files, { append: true })
          if (fileInput) fileInput.value = ''
          if (filesBrowseInput) filesBrowseInput.value = ''
        }

        fileInput?.addEventListener('change', () => {
          if (!fileInput.files?.length) return
          const copied = Array.from(fileInput.files)
          enqueueSelectedFiles(copied)
        })

        filesBrowseInput?.addEventListener('change', () => {
          if (!filesBrowseInput.files?.length) return
          const copied = Array.from(filesBrowseInput.files)
          enqueueSelectedFiles(copied)
        })

        cameraInput?.addEventListener('change', () => {
          if (!cameraInput.files?.length) return
          enqueueSelectedFiles(Array.from(cameraInput.files), { fromCamera: true })
          cameraInput.value = ''
        })

        cameraVideoInput?.addEventListener('change', () => {
          if (!cameraVideoInput.files?.length) return
          enqueueSelectedFiles(Array.from(cameraVideoInput.files), { fromCamera: true })
          cameraVideoInput.value = ''
        })

        pendingAddBtn?.addEventListener('click', () => {
          const files = pendingFiles
          if (!files?.length || uploading) return
          clearPendingFiles()
          void submitFiles(files)
        })

        pendingMoreBtn?.addEventListener('click', () => {
          triggerLastPickBrowse()
        })

        pendingCancelBtn?.addEventListener('click', () => {
          clearPendingFiles()
          setUploadProgress(@json(__('写真・動画を追加')))
        })

        if (typeof window.showDirectoryPicker === 'function' && folderWatchBtn) {
          folderWatchBtn.hidden = false
          folderWatchBtn.addEventListener('click', () => { void startFolderWatch() })
        }
        folderWatchStop?.addEventListener('click', () => {
          stopFolderWatch()
          if (folderWatchBtn && typeof window.showDirectoryPicker === 'function') {
            folderWatchBtn.hidden = false
          }
          setUploadProgress(@json(__('写真・動画を追加')))
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
          submitFiles(files)
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
              lightboxVideo.src = photo.fileUrl || photo.url || (`/photos/${photo.id}/file`)
            }
          } else {
            if (lightboxImage) {
              lightboxImage.hidden = false
              lightboxImage.src = photo.url || photo.fileUrl || (`/photos/${photo.id}/file`)
              lightboxImage.alt = photo.caption || photo.originalName || '写真'
            }
          }
          lightboxCaption.textContent = photo.caption || photo.originalName || ''
          lightboxDate.textContent = (isVideo ? '動画 · ' : '') + (photo.takenAt || '')
          if (photo.editLabel) {
            lightboxDate.textContent += (lightboxDate.textContent ? ' · ' : '') + photo.editLabel
          }
          if (photo.width && photo.height) {
            lightboxDate.textContent += (lightboxDate.textContent ? ' · ' : '') + `${photo.width}×${photo.height}`
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
          const stabilityEnhanceCancelBtn = document.getElementById('photos-stability-enhance-cancel-btn')
          if (stabilityEnhanceCancelBtn && !window.__photosEnhanceInFlight) {
            stabilityEnhanceCancelBtn.hidden = true
            stabilityEnhanceCancelBtn.disabled = true
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
        ;(function openPhotoFromQuery() {
          const params = new URLSearchParams(window.location.search)
          const focusId = Number(params.get('photo') || 0)
          const zoom = Number(params.get('zoom') || 0)
          if (!focusId || !Array.isArray(photos)) return
          const idx = photos.findIndex((p) => Number(p.id) === focusId)
          if (idx < 0) return
          openLightbox(idx)
          if (zoom > 1) setLightboxZoom(zoom)
          const url = new URL(window.location.href)
          url.searchParams.delete('photo')
          url.searchParams.delete('zoom')
          window.history.replaceState({}, '', url.pathname + url.search + url.hash)
        })()

        async function fetchPhotoFileByIndex(index) {
          const photo = photos[index]
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

        async function fetchCurrentMediaFile() {
          return fetchPhotoFileByIndex(currentIndex)
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

        async function downloadPhotosByIds(ids, triggerBtn = null) {
          const idList = (ids || []).map((id) => String(id)).filter(Boolean)
          if (!idList.length) {
            window.alert(@json(__('ダウンロードする写真を選択してください。')))
            return
          }
          const originalText = triggerBtn?.textContent
          if (triggerBtn) {
            triggerBtn.disabled = true
            triggerBtn.textContent = @json(__('ダウンロード中…'))
          }
          let ok = 0
          let failed = 0
          try {
            for (const id of idList) {
              const index = photos.findIndex((p) => String(p.id) === id)
              if (index < 0) {
                failed += 1
                continue
              }
              try {
                const file = await fetchPhotoFileByIndex(index)
                downloadFile(file)
                ok += 1
                if (idList.length > 1) {
                  await new Promise((resolve) => setTimeout(resolve, 350))
                }
              } catch (_) {
                failed += 1
              }
            }
            if (ok === 0) {
              window.alert(@json(__('ダウンロードに失敗しました。')))
            } else if (failed > 0) {
              window.alert(@json(__(':ok件ダウンロードしました（:failed件失敗）')).replace(':ok', String(ok)).replace(':failed', String(failed)))
            }
          } finally {
            if (triggerBtn) {
              triggerBtn.disabled = false
              triggerBtn.textContent = originalText
            }
          }
        }

        document.getElementById('photos-bulk-download')?.addEventListener('click', () => {
          downloadPhotosByIds(selectedPhotoIds(), document.getElementById('photos-bulk-download'))
        })

        document.querySelectorAll('.photos-list-download').forEach((btn) => {
          btn.addEventListener('click', (e) => {
            e.preventDefault()
            e.stopPropagation()
            const id = btn.dataset.photoId
            downloadPhotosByIds(id ? [id] : [], btn)
          })
        })

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
            video.src = photo.fileUrl || photo.url || (`/photos/${photo.id}/file`)
          } else if (img) {
            img.hidden = false
            img.src = photo.thumbUrl || photo.url || photo.fileUrl || (`/photos/${photo.id}/file`)
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
        const albumPassword = document.getElementById('photos-album-password')
        const albumPasswordHint = document.getElementById('photos-album-password-hint')
        const albumClearPasswordWrap = document.getElementById('photos-album-clear-password-wrap')
        const albumClearPassword = document.getElementById('photos-album-clear-password')
        const albumHidden = document.getElementById('photos-album-hidden')
        const albumSubmit = document.getElementById('photos-album-submit')
        const selectedAlbum = @json($selectedAlbum);
        const revealHiddenAlbums = @json(!empty($revealHiddenAlbums));

        function syncAlbumGroupVisibility() {
          if (!albumVisibility || !albumGroupWrap) return
          const showGroup = albumVisibility.value === 'group' && !(albumHidden && albumHidden.checked)
          albumGroupWrap.hidden = !showGroup
          if (albumGroupId) albumGroupId.required = showGroup
          if (albumVisibility) albumVisibility.disabled = !!(albumHidden && albumHidden.checked)
        }
        albumVisibility?.addEventListener('change', syncAlbumGroupVisibility)
        albumHidden?.addEventListener('change', syncAlbumGroupVisibility)

        function openAlbumModal(mode) {
          if (!albumModal || !albumForm) return
          if (mode === 'edit' && selectedAlbum) {
            albumModalTitle.textContent = @json(__('アルバムを編集'));
            albumForm.action = `/photos/albums/${selectedAlbum.id}/update`
            albumNameInput.value = selectedAlbum.name || ''
            albumDescInput.value = selectedAlbum.description || ''
            if (albumVisibility) albumVisibility.value = selectedAlbum.visibility || 'private'
            if (albumGroupId) albumGroupId.value = selectedAlbum.groupId || ''
            if (albumPassword) albumPassword.value = ''
            if (albumPasswordHint) {
              albumPasswordHint.textContent = selectedAlbum.hasPassword
                ? @json(__('新しいパスワードを入れると上書き。空欄のままなら変更しません。'))
                : @json(__('設定すると、開くときにパスワードが必要です。'));
            }
            if (albumClearPasswordWrap) albumClearPasswordWrap.hidden = !selectedAlbum.hasPassword
            if (albumClearPassword) albumClearPassword.checked = false
            if (albumHidden) albumHidden.checked = !!selectedAlbum.isHidden
            if (albumSubmit) albumSubmit.textContent = @json(__('保存'));
          } else {
            albumModalTitle.textContent = @json(__('アルバムを作成'));
            albumForm.action = '/photos/albums'
            albumNameInput.value = ''
            albumDescInput.value = ''
            if (albumVisibility) albumVisibility.value = 'private'
            if (albumGroupId) albumGroupId.value = ''
            if (albumPassword) albumPassword.value = ''
            if (albumPasswordHint) albumPasswordHint.textContent = @json(__('設定すると、開くときにパスワードが必要です。'));
            if (albumClearPasswordWrap) albumClearPasswordWrap.hidden = true
            if (albumClearPassword) albumClearPassword.checked = false
            if (albumHidden) albumHidden.checked = false
            if (albumSubmit) albumSubmit.textContent = @json(__('作成'));
          }
          syncAlbumGroupVisibility()
          albumModal.hidden = false
          albumNameInput?.focus()
        }

        document.getElementById('photos-album-open')?.addEventListener('click', () => openAlbumModal('create'))
        document.getElementById('photos-album-edit')?.addEventListener('click', () => openAlbumModal('edit'))
        document.querySelectorAll('[data-close-album-modal]').forEach((el) => {
          el.addEventListener('click', () => {
            if (albumModal) albumModal.hidden = true
          })
        })

        // 隠しアルバム表示切替: タイトルを7回タップ
        ;(function setupHiddenAlbumReveal() {
          const title = document.getElementById('photos-title-tap')
          if (!title) return
          let taps = 0
          let timer = null
          title.style.cursor = 'pointer'
          title.addEventListener('click', async (e) => {
            // アルバム選択中はタイトルがアルバム名なので、ルートの Photos 相当でも動作させる
            e.preventDefault()
            taps += 1
            if (timer) clearTimeout(timer)
            timer = setTimeout(() => { taps = 0 }, 1600)
            if (taps < 7) return
            taps = 0
            const fd = new FormData()
            fd.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '')
            fd.append('returnTo', window.location.pathname + window.location.search)
            try {
              const res = await fetch('/photos/albums/reveal-hidden', {
                method: 'POST',
                headers: {
                  'Accept': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: fd,
                credentials: 'same-origin',
              })
              const data = await res.json().catch(() => ({}))
              window.alert(data.message || (revealHiddenAlbums
                ? @json(__('隠しアルバムを切り替えました。'))
                : @json(__('隠しアルバムを表示しています。'))));
              window.location.reload()
            } catch (_) {
              window.alert(@json(__('隠しアルバムの切替に失敗しました。')));
            }
          })
        })()

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
        try {
          ;(function setupStabilityEnhance() {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
            const btn = document.getElementById('photos-stability-enhance-btn')
            const cancelBtn = document.getElementById('photos-stability-enhance-cancel-btn')
            if (!btn) return

            let enhanceAbort = null
            window.__photosEnhanceInFlight = false

            const label = (el, key) => (el && el.dataset[key]) ? el.dataset[key] : ''

            const setEnhancing = (on, photoId) => {
              window.__photosEnhanceInFlight = !!on
              btn.disabled = !!on
              if (on) {
                btn.textContent = label(btn, 'labelBusy') || btn.textContent
              } else {
                btn.textContent = label(btn, 'labelIdle') || btn.textContent
              }
              if (cancelBtn) {
                cancelBtn.hidden = !on
                cancelBtn.disabled = !on
                cancelBtn.dataset.photoId = on ? String(photoId || 0) : ''
                cancelBtn.textContent = label(cancelBtn, 'labelIdle') || cancelBtn.textContent
              }
            }

            cancelBtn?.addEventListener('click', () => {
              if (!window.__photosEnhanceInFlight) return
              const photoId = Number(cancelBtn.dataset.photoId || btn.dataset.photoId || 0)
              cancelBtn.disabled = true
              cancelBtn.textContent = label(cancelBtn, 'labelBusy') || cancelBtn.textContent
              try { enhanceAbort?.abort() } catch (_) {}
              if (photoId) {
                fetch('/photos/' + photoId + '/stability-enhance/cancel', {
                  method: 'POST',
                  headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                  },
                  body: '{}',
                }).catch(() => null)
              }
            })

            btn.addEventListener('click', async () => {
              const photoId = Number(btn.dataset.photoId || 0)
              if (!photoId || window.__photosEnhanceInFlight) return
              if (!window.confirm(@json(__('この写真を AI で鮮明化して R2 に保存しますか？（元画像は残ります）')))) {
                return
              }
              enhanceAbort = new AbortController()
              setEnhancing(true, photoId)
              try {
                const res = await fetch('/photos/' + photoId + '/stability-enhance', {
                  method: 'POST',
                  headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                  },
                  body: '{}',
                  signal: enhanceAbort.signal,
                })
                const data = await res.json().catch(() => ({}))
                if (data.cancelled || res.status === 499) {
                  window.alert(data.message || @json(__('鮮明化を中止しました。')))
                  return
                }
                if (!res.ok || data.ok === false) {
                  throw new Error(data.message || @json(__('AI鮮明化に失敗しました。')))
                }
                window.alert(data.message || @json(__('AI鮮明化版を保存しました。解像度が上がっているので、拡大して確認してください。')))
                const newId = data.photo && data.photo.id
                if (newId) {
                  const url = new URL(window.location.href)
                  url.searchParams.set('photo', String(newId))
                  url.searchParams.set('zoom', String(data.zoom || 2))
                  window.location.href = url.toString()
                } else {
                  window.location.reload()
                }
              } catch (e) {
                if (e && e.name === 'AbortError') {
                  window.alert(@json(__('鮮明化を中止しました。')))
                } else {
                  window.alert((e && e.message) || @json(__('AI鮮明化に失敗しました。')))
                }
              } finally {
                enhanceAbort = null
                setEnhancing(false, 0)
              }
            })
          })()
        } catch (err) {
          console.warn('Stability enhance setup skipped', err)
        }
        @endif
      })()
    </script>
  </body>
</html>
