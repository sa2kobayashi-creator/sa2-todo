<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('翻訳') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body class="gt-app">
    @include('partials.header', ['active' => 'translate'])
    <main class="gt-shell">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
      @if(empty($configured))
        <div class="banner error">{{ __('翻訳APIキーが未設定です。設定 → AI設定から DeepL キーを登録してください。') }}</div>
      @endif

      <div class="gt-modes" role="tablist" aria-label="{{ __('翻訳モード') }}">
        <button type="button" class="gt-mode is-active" data-mode="text" role="tab" aria-selected="true">
          <span class="gt-mode-ico" aria-hidden="true">文</span>{{ __('テキスト') }}
        </button>
        <button type="button" class="gt-mode" data-mode="image" role="tab" aria-selected="false">
          <span class="gt-mode-ico" aria-hidden="true">▣</span>{{ __('画像') }}
        </button>
        <button type="button" class="gt-mode" data-mode="document" role="tab" aria-selected="false">
          <span class="gt-mode-ico" aria-hidden="true">▤</span>{{ __('ドキュメント') }}
        </button>
        <button type="button" class="gt-mode" data-mode="website" role="tab" aria-selected="false">
          <span class="gt-mode-ico" aria-hidden="true">◉</span>{{ __('ウェブサイト') }}
        </button>
      </div>

      <div class="gt-langs">
        <div class="gt-lang-side" id="gt-source-chips">
          <button type="button" class="gt-chip" data-lang="AUTO">{{ __('言語を検出') }}</button>
          <button type="button" class="gt-chip is-active" data-lang="JA">{{ __('日本語') }}</button>
          <button type="button" class="gt-chip" data-lang="EN">{{ __('英語') }}</button>
          <button type="button" class="gt-chip" data-lang="KO">{{ __('韓国語') }}</button>
          <select id="translate-source" class="gt-lang-more" aria-label="{{ __('原文の言語') }}">
            <option value="AUTO">{{ __('言語を検出') }}</option>
            <option value="JA" selected>{{ __('日本語') }}</option>
            <option value="EN">English</option>
            <option value="KO">한국어</option>
            <option value="TL">Filipino</option>
            <option value="ZH">中文</option>
          </select>
        </div>
        <button type="button" class="gt-swap" id="translate-swap" title="{{ __('言語を入替') }}" aria-label="{{ __('言語を入替') }}">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M7 8h11l-3-3" /><path d="M17 16H6l3 3" />
          </svg>
        </button>
        <div class="gt-lang-side" id="gt-target-chips">
          <button type="button" class="gt-chip is-active" data-lang="EN">{{ __('英語') }}</button>
          <button type="button" class="gt-chip" data-lang="JA">{{ __('日本語') }}</button>
          <button type="button" class="gt-chip" data-lang="KO">{{ __('韓国語') }}</button>
          <select id="translate-target" class="gt-lang-more" aria-label="{{ __('訳文の言語') }}">
            <option value="EN" selected>English</option>
            <option value="JA">{{ __('日本語') }}</option>
            <option value="KO">한국어</option>
            <option value="TL">Filipino</option>
            <option value="ZH">中文</option>
          </select>
        </div>
      </div>

      <section class="gt-board" data-panel="text">
        <div class="gt-panes">
          <div class="gt-pane">
            <label class="gt-pane-label" id="gt-source-label">{{ __('日本語') }}</label>
            <textarea id="translate-input" maxlength="50000" placeholder="{{ __('翻訳したい文章') }}">{{ $input ?? '' }}</textarea>
            <div class="gt-pane-foot">
              <button type="button" class="gt-mic" id="translate-mic" hidden>{{ __('マイクで話す') }}</button>
              <button type="button" class="gt-go" id="translate-submit" @disabled(empty($configured))>{{ __('翻訳する') }}</button>
            </div>
          </div>
          <div class="gt-pane gt-pane-out">
            <label class="gt-pane-label" id="gt-target-label">{{ __('英語') }}</label>
            <textarea id="translate-output" readonly placeholder="{{ __('翻訳結果がここに表示されます') }}">{{ $output ?? '' }}</textarea>
          </div>
        </div>
      </section>

      <section class="gt-board" data-panel="image" hidden>
        <div class="gt-upload">
          <div class="gt-drop" id="gt-image-drop">
            <div class="gt-cloud" aria-hidden="true">⬆</div>
            <p>{{ __('ドラッグ＆ドロップ') }}</p>
            <img id="gt-image-preview" class="gt-preview" alt="" hidden />
          </div>
          <div class="gt-upload-side">
            <p>{{ __('またはファイルを選択') }}</p>
            <label class="gt-file-btn">
              {{ __('ファイルを見る') }}
              <input type="file" id="gt-image-input" accept="image/*" hidden />
            </label>
            <p class="gt-hint">JPG, PNG, WEBP, GIF</p>
            <p class="gt-hint">{{ __('画像内の文字を読み取って翻訳します') }}</p>
          </div>
        </div>
        <div class="gt-panes gt-panes-compact" id="gt-image-result" hidden>
          <div class="gt-pane"><label class="gt-pane-label">{{ __('読み取り') }}</label><textarea id="gt-image-source" readonly></textarea></div>
          <div class="gt-pane gt-pane-out"><label class="gt-pane-label">{{ __('翻訳') }}</label><textarea id="gt-image-output" readonly></textarea></div>
        </div>
      </section>

      <section class="gt-board" data-panel="document" hidden>
        <div class="gt-upload">
          <div class="gt-drop" id="gt-doc-drop">
            <div class="gt-cloud" aria-hidden="true">⬆</div>
            <p>{{ __('ドラッグ＆ドロップ') }}</p>
          </div>
          <div class="gt-upload-side">
            <p>{{ __('またはファイルを選択') }}</p>
            <label class="gt-file-btn">
              {{ __('ファイルを見る') }}
              <input type="file" id="gt-doc-input" accept=".txt,.md,.csv,.html,.htm,.docx,.json" hidden />
            </label>
            <p class="gt-hint">.docx, .txt, .md, .html</p>
          </div>
        </div>
        <div class="gt-panes gt-panes-compact" id="gt-doc-result" hidden>
          <div class="gt-pane"><label class="gt-pane-label">{{ __('原文') }}</label><textarea id="gt-doc-source" readonly></textarea></div>
          <div class="gt-pane gt-pane-out"><label class="gt-pane-label">{{ __('翻訳') }}</label><textarea id="gt-doc-output" readonly></textarea></div>
        </div>
      </section>

      <section class="gt-board" data-panel="website" hidden>
        <div class="gt-web">
          <input type="url" id="gt-web-url" placeholder="https://example.com" />
          <button type="button" class="gt-file-btn" id="gt-web-go" @disabled(empty($configured))>{{ __('翻訳する') }}</button>
        </div>
        <div class="gt-panes gt-panes-compact" id="gt-web-result" hidden>
          <div class="gt-pane"><label class="gt-pane-label" id="gt-web-title">{{ __('原文') }}</label><textarea id="gt-web-source" readonly></textarea></div>
          <div class="gt-pane gt-pane-out"><label class="gt-pane-label">{{ __('翻訳') }}</label><textarea id="gt-web-output" readonly></textarea></div>
        </div>
      </section>

      <p class="gt-status" id="translate-live-status" hidden></p>

      <div class="gt-utils">
        <button type="button" class="gt-util" id="gt-history-open">
          <span class="gt-util-ico" aria-hidden="true">↺</span>{{ __('履歴') }}
        </button>
        <button type="button" class="gt-util" id="gt-saved-open">
          <span class="gt-util-ico" aria-hidden="true">★</span>{{ __('保存済み') }}
        </button>
      </div>
    </main>

    <aside class="gt-drawer" id="gt-drawer" hidden>
      <div class="gt-drawer-head">
        <h2 id="gt-drawer-title">{{ __('履歴') }}</h2>
        <button type="button" class="gt-drawer-close" id="gt-drawer-close" aria-label="{{ __('閉じる') }}">×</button>
      </div>
      <div class="gt-drawer-body" id="gt-drawer-body"></div>
    </aside>
    <div class="gt-drawer-backdrop" id="gt-drawer-backdrop" hidden></div>

    @php
      $trI18n = [
        'translating' => __('翻訳中…'),
        'failed' => __('翻訳に失敗しました。'),
        'live' => __('リアルタイム翻訳中'),
        'stop' => __('停止'),
        'mic' => __('マイクで話す'),
        'listening' => __('音声認識中…'),
        'swapped' => __('入れ替えました'),
        'ocr' => __('画像を読み取り中…'),
        'detect' => __('言語を検出'),
        'emptyHistory' => __('履歴はまだありません'),
        'saved' => __('保存済み'),
        'history' => __('履歴'),
      ];
      $langLabels = [
        'AUTO' => __('言語を検出'),
        'JA' => __('日本語'),
        'EN' => __('英語'),
        'KO' => __('韓国語'),
        'TL' => 'Filipino',
        'ZH' => '中文',
      ];
    @endphp
    <script type="application/json" id="translate-i18n">@json($trI18n)</script>
    <script type="application/json" id="translate-lang-labels">@json($langLabels)</script>
    <script
      id="translate-page-boot"
      src="{{ asset('translate-page.js') }}?v=gt-2"
      defer
      data-configured="{{ !empty($configured) ? '1' : '0' }}"
    ></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js" defer></script>
  </body>
</html>
