<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="referrer" content="strict-origin-when-cross-origin" />
    <title>{{ __('動画') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body class="media-player-page video-page">
    @include('partials.header', ['active' => 'video'])
    @php
      $nowPlaying = $nowPlaying ?? null;
      $searchQuery = $searchQuery ?? '';
      $searchResults = $searchResults ?? [];
      $popular = $popular ?? [];
      $playingSource = is_array($nowPlaying) ? (string) ($nowPlaying['source'] ?? '') : '';
    @endphp
    <main class="page-main media-player-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <section class="panel media-player-hero">
        <div>
          <h1 class="media-player-title">{{ __('動画') }}</h1>
          <p class="hint">{{ __('キーワードで YouTube を探すか、YouTube / TikTok のURLを貼ってすぐ再生できます。結果はライブラリに入れなくてもプレビューできます。') }}</p>
        </div>
        <div class="media-video-add-actions">
          <form method="post" action="/video" enctype="multipart/form-data" class="media-upload-form">
            @csrf
            <input type="hidden" name="returnTo" value="/video" />
            <label class="button-link media-upload-btn">
              <input type="file" name="videos[]" accept="video/mp4,video/quicktime,.mp4,.mov" multiple required hidden />
              <span>{{ __('動画を追加') }}</span>
            </label>
            <p class="hint">{{ __('1ファイル最大 :size', ['size' => $maxUploadLabel ?? '1 GB']) }}</p>
          </form>
        </div>
      </section>

      <section
        class="panel media-player-stage media-player-stage-video {{ $nowPlaying ? 'is-playing' : 'is-idle' }}"
        id="video-player-stage"
      >
        @if(is_array($nowPlaying) && $playingSource === 'upload')
          <div class="media-video-frame" id="video-local-wrap">
            <video
              id="video-player"
              class="media-video-el"
              src="{{ $nowPlaying['url'] ?? '' }}"
              controls
              playsinline
              autoplay
            ></video>
          </div>
        @elseif(is_array($nowPlaying) && $playingSource === 'tiktok')
          <div class="media-video-frame media-tiktok-frame" id="video-tiktok-wrap">
            <iframe
              id="tiktok-player"
              class="media-tiktok-el"
              title="{{ $nowPlaying['title'] ?? 'TikTok' }}"
              src="{{ $nowPlaying['embedUrl'] ?? '' }}"
              allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"
              allowfullscreen
              referrerpolicy="strict-origin-when-cross-origin"
            ></iframe>
          </div>
        @elseif(is_array($nowPlaying))
          <div class="media-video-frame media-youtube-frame" id="video-youtube-wrap">
            <iframe
              id="youtube-player"
              class="media-youtube-el"
              title="{{ $nowPlaying['title'] ?? 'YouTube' }}"
              src="{{ $nowPlaying['embedUrl'] ?? '' }}"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen"
              allowfullscreen
              referrerpolicy="strict-origin-when-cross-origin"
            ></iframe>
          </div>
        @endif
        <div class="media-now-playing">
          <div class="media-now-playing-text">
            <strong id="video-now-title">{{ is_array($nowPlaying) ? ($nowPlaying['title'] ?? __('再生中')) : __('動画を選択してください') }}</strong>
            <span class="hint" id="video-now-meta">
              @if(is_array($nowPlaying))
                {{ $playingSource === 'upload' ? __('アップロード') : ($playingSource === 'tiktok' ? 'TikTok' : 'YouTube') }}
              @endif
            </span>
          </div>
        </div>
      </section>

      <section class="panel media-youtube-search-panel" id="youtube-search-panel">
        <h2 class="media-list-title">{{ __('探す・再生する') }}</h2>
        <p class="hint">{{ __('キーワード検索は YouTube。URL なら YouTube も TikTok もそのまま再生します（TikTok の検索用APIは使いません）。') }}</p>
        @if(empty($youtubeSearchReady))
          <p class="hint">
            {{ __('キーワードで探すには YouTube Data API キーが必要です。URL の貼り付け再生はキーなしで使えます。') }}
            <a href="/settings?section=enhance#youtube-api-settings">{{ __('API設定へ') }}</a>
          </p>
        @endif
        <form class="media-youtube-search-form" id="youtube-search-form" method="get" action="/video">
          <input type="hidden" name="library" value="{{ $currentLibraryId }}" />
          <input
            type="search"
            id="youtube-search-q"
            name="q"
            value="{{ $searchQuery }}"
            placeholder="{{ __('キーワード、または YouTube / TikTok のURL') }}"
            autocomplete="off"
          />
          <button type="submit" class="button-link">{{ __('検索') }}</button>
        </form>
        @if($searchQuery !== '')
          <p class="hint {{ !empty($searchIsError) ? 'is-error' : '' }}" id="youtube-search-status">{{ $searchMessage }}</p>
          @if($searchResults !== [])
            <div class="media-youtube-results" id="youtube-search-results">
              @include('video.partials.cards', ['items' => $searchResults, 'libraryId' => $currentLibraryId])
            </div>
          @endif
          @if(!empty($searchPrevUrl) || !empty($searchNextUrl))
            <nav class="media-youtube-pager" id="youtube-search-pager" aria-label="{{ __('検索結果のページ') }}">
              @if(!empty($searchPrevUrl))
                <a class="button-link secondary" href="{{ $searchPrevUrl }}">{{ __('‹ 前へ') }}</a>
              @endif
              <span class="media-pager-status">{{ $searchMessage }}</span>
              @if(!empty($searchNextUrl))
                <a class="button-link secondary" href="{{ $searchNextUrl }}">{{ __('次へ ›') }}</a>
              @endif
            </nav>
          @endif
        @endif
        @if($popular !== [])
          <div id="youtube-popular-wrap">
            <h3 class="media-list-subtitle">{{ __('人気の動画') }}</h3>
            <p class="hint">{{ __('日本でよく見られている YouTube。タップですぐ再生できます。') }}</p>
            <div class="media-youtube-results" id="youtube-popular-results">
              @include('video.partials.cards', ['items' => $popular, 'libraryId' => $currentLibraryId])
            </div>
          </div>
        @endif
      </section>

      <section class="panel media-youtube-add">
        <h2 class="media-list-title">{{ __('リンクをライブラリに保存') }}</h2>
        <form method="post" action="/video/youtube" class="media-youtube-form">
          @csrf
          <input type="hidden" name="returnTo" value="/video?library={{ $currentLibraryId }}" />
          <input type="hidden" name="library_id" value="{{ $currentLibraryId }}" />
          <label>
            {{ __('YouTube / TikTok URL') }}
            <input
              type="url"
              name="youtube_url"
              class="media-field-input"
              required
              placeholder="https://www.youtube.com/watch?v=...  /  https://www.tiktok.com/@.../video/..."
              autocomplete="off"
            />
          </label>
          <label>
            {{ __('タイトル（任意）') }}
            <input type="text" name="title" class="media-field-input" maxlength="255" placeholder="{{ __('未入力なら自動取得') }}" autocomplete="off" />
          </label>
          <button type="submit" class="button-link media-youtube-submit">{{ __('リンクを追加') }}</button>
        </form>
        <p class="hint">{{ __('追加先: :name', ['name' => $currentLibrary['name'] ?? __('マイリスト')]) }}</p>
      </section>

      <section class="panel media-library-panel">
        <div class="media-library-head">
          <h2 class="media-list-title">{{ __('ライブラリ') }}</h2>
          <form method="post" action="/video/libraries" class="media-library-create">
            @csrf
            <input type="hidden" name="returnTo" value="/video?library={{ $currentLibraryId }}" />
            <input type="text" name="name" class="media-field-input" maxlength="120" required placeholder="{{ __('新しいライブラリ名') }}" />
            <button type="submit" class="button-link">{{ __('ライブラリを作成') }}</button>
          </form>
        </div>

        <div class="media-library-tabs" role="tablist" aria-label="{{ __('ライブラリ') }}">
          @foreach($libraries as $lib)
            <a
              href="/video?library={{ $lib['id'] }}"
              class="media-library-tab {{ (int) $lib['id'] === (int) $currentLibraryId ? 'is-active' : '' }}"
              role="tab"
              aria-selected="{{ (int) $lib['id'] === (int) $currentLibraryId ? 'true' : 'false' }}"
            >
              <span>{{ $lib['name'] }}</span>
              <span class="hint">{{ $lib['videoCount'] }}</span>
            </a>
          @endforeach
        </div>

        @if(empty($currentLibrary['isDefault']))
          <div class="media-library-manage">
            <form method="post" action="/video/libraries/{{ $currentLibraryId }}/update" class="media-library-rename">
              @csrf
              <input type="hidden" name="returnTo" value="/video?library={{ $currentLibraryId }}" />
              <input type="text" name="name" class="media-field-input" value="{{ $currentLibrary['name'] ?? '' }}" maxlength="120" required />
              <button type="submit" class="secondary">{{ __('名前を変更') }}</button>
            </form>
            <form method="post" action="/video/libraries/{{ $currentLibraryId }}/delete" onsubmit='return confirm(@json(__('このライブラリを削除しますか？動画はマイリストへ移動します。')))'>
              @csrf
              <input type="hidden" name="returnTo" value="/video" />
              <button type="submit" class="text-btn danger">{{ __('ライブラリを削除') }}</button>
            </form>
          </div>
        @else
          <p class="hint">{{ __('マイリストにはアップロードした動画も表示されます。') }}</p>
        @endif

        <h3 class="media-list-subtitle" id="library-list-panel">{{ $currentLibrary['name'] ?? __('マイリスト') }}</h3>
        @if(count($playlist) === 0)
          <p class="hint">{{ ($libraryFilter ?? '') !== '' ? __('一致する動画がありません。') : __('このライブラリに動画がありません。') }}</p>
        @else
          <form method="get" action="/video" class="media-library-filter">
            <input type="hidden" name="library" value="{{ $currentLibraryId }}" />
            <label class="media-library-filter-label">
              {{ __('ライブラリ内を絞り込み') }}
              <input type="search" id="library-filter-q" name="lq" class="media-field-input" value="{{ $libraryFilter ?? '' }}" placeholder="{{ __('タイトルで絞り込み') }}" autocomplete="off" />
            </label>
            <button type="submit" class="secondary">{{ __('絞り込み') }}</button>
          </form>
          <ul class="media-track-list media-video-list" id="video-track-list">
            @foreach($playlist as $index => $item)
              <li class="media-track-item" data-index="{{ $index }}" data-title="{{ $item['title'] }}">
                <a
                  class="media-track-play media-video-play {{ is_array($nowPlaying) && ($nowPlaying['source'] ?? '') === ($item['source'] ?? '') && (string) ($nowPlaying['youtubeId'] ?? $nowPlaying['id'] ?? '') === (string) ($item['youtubeId'] ?? $item['id'] ?? '') ? 'is-active' : '' }}"
                  href="{{ $item['playHref'] }}"
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
                      @elseif(($item['source'] ?? '') === 'tiktok')
                        TikTok
                      @else
                        {{ __('アップロード') }}
                      @endif
                      @if(!empty($item['meta']) || !empty($item['createdAt']))
                        · {{ $item['meta'] ?? $item['createdAt'] }}
                      @endif
                    </span>
                  </span>
                </a>
                @if(in_array($item['source'] ?? '', ['youtube', 'tiktok'], true))
                  @if(count($libraries) > 1)
                    <form method="post" action="/video/youtube/{{ $item['id'] }}/move" class="media-move-form">
                      @csrf
                      <input type="hidden" name="returnTo" value="{{ $libraryReturnTo }}" />
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
                  <form method="post" action="/video/youtube/{{ $item['id'] }}/delete" onsubmit='return confirm(@json(__('この動画をライブラリから削除しますか？')))'>
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $libraryReturnTo }}" />
                    <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
                  </form>
                @else
                  <a class="text-btn" href="/photos?photo={{ $item['photoId'] ?? $item['id'] }}">{{ __('Photosで開く') }}</a>
                @endif
              </li>
            @endforeach
          </ul>
          @if(($libraryTotalPages ?? 1) > 1)
            <nav class="media-youtube-pager" id="library-pager" aria-label="{{ __('ライブラリのページ') }}">
              @if(!empty($libraryPrevUrl))
                <a class="button-link secondary" href="{{ $libraryPrevUrl }}">{{ __('‹ 前へ') }}</a>
              @endif
              <span class="media-pager-status">{{ $libraryPageLabel }}</span>
              @if(!empty($libraryNextUrl))
                <a class="button-link secondary" href="{{ $libraryNextUrl }}">{{ __('次へ ›') }}</a>
              @endif
            </nav>
          @endif
        @endif
      </section>
    </main>
    <script>
      (function () {
        const uploadInput = document.querySelector('.media-upload-form input[type="file"]')
        uploadInput?.addEventListener('change', () => {
          if (uploadInput.files?.length) uploadInput.closest('form')?.submit()
        })
      })()
    </script>
  </body>
</html>
