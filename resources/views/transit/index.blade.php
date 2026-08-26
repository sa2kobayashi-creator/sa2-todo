<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#0f766e" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('路線検索') }} - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet" />
    @include('partials.app-css')
  </head>
  <body class="transit-page">
    @include('partials.header', ['active' => 'transit'])
    <main class="page-main transit-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <header class="transit-hero">
        <div class="transit-hero-copy">
          <p class="transit-kicker">{{ __('公共交通') }}</p>
          <h1 class="transit-page-title">{{ __('路線検索') }}</h1>
          <p class="transit-hero-lead">{{ __('出発と到着を入れると、電車・バスなどの経路を検索できます。よく使う経路は登録してすぐ呼び出せます。') }}</p>
        </div>
        <span class="transit-hero-mark" aria-hidden="true">🚇</span>
      </header>

      <section class="panel transit-search-panel">
        <header class="transit-panel-head">
          <h2 class="transit-section-title">{{ __('経路を検索') }}</h2>
          <p class="hint">{{ __('出発・到着を入力すると、電車・バスなどを乗り継いだルートを検索します。') }}</p>
        </header>
        <form class="transit-search-form" id="transit-search-form">
          <div class="transit-places">
            <label class="transit-field">
              <span class="transit-field-label-row">
                <span class="transit-field-label">{{ __('出発（バス停・駅）') }}</span>
                <button type="button" class="transit-place-mic" id="transit-from-mic" aria-pressed="false" title="{{ __('出発を音声で入力') }}" aria-label="{{ __('出発を音声で入力') }}">
                  <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                  </svg>
                </button>
              </span>
              <span class="transit-field-control">
                <input type="text" id="transit-from" placeholder="{{ __('例: 天神') }}" autocomplete="off" />
                <div class="pac-holder" id="transit-from-holder"></div>
              </span>
            </label>
            <button type="button" class="transit-places-swap" id="transit-places-swap" title="{{ __('出発と到着を入れ替え') }}" aria-label="{{ __('出発と到着を入れ替え') }}">
              <span aria-hidden="true">⇄</span>
            </button>
            <label class="transit-field">
              <span class="transit-field-label-row">
                <span class="transit-field-label">{{ __('到着（任意）') }}</span>
                <button type="button" class="transit-place-mic" id="transit-to-mic" aria-pressed="false" title="{{ __('到着を音声で入力') }}" aria-label="{{ __('到着を音声で入力') }}">
                  <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                  </svg>
                </button>
              </span>
              <span class="transit-field-control">
                <input type="text" id="transit-to" placeholder="{{ __('例: 博多') }}" autocomplete="off" />
                <div class="pac-holder" id="transit-to-holder"></div>
              </span>
            </label>
          </div>
          <p class="hint transit-voice-status" id="transit-voice-status" aria-live="polite"></p>
          <div class="transit-time-block">
            <p class="transit-time-label">{{ __('日時') }}</p>
            <div class="transit-datetime-selects" id="transit-datetime-selects">
              <select id="transit-year" aria-label="{{ $datetimeAriaLabels['year'] }}"></select>
              <select id="transit-month" aria-label="{{ $datetimeAriaLabels['month'] }}"></select>
              <select id="transit-day" aria-label="{{ $datetimeAriaLabels['day'] }}"></select>
              <select id="transit-hour" aria-label="{{ $datetimeAriaLabels['hour'] }}"></select>
              <select id="transit-minute" aria-label="{{ $datetimeAriaLabels['minute'] }}"></select>
            </div>
            <div class="transit-time-types" role="radiogroup" aria-label="{{ __('時刻の基準') }}">
              <label><input type="radio" name="transit-time-type" value="1" checked /> {{ __('出発') }}</label>
              <label><input type="radio" name="transit-time-type" value="4" /> {{ __('到着') }}</label>
              <label><input type="radio" name="transit-time-type" value="2" /> {{ __('始発') }}</label>
              <label><input type="radio" name="transit-time-type" value="3" /> {{ __('終電') }}</label>
              <label><input type="radio" name="transit-time-type" value="0" /> {{ __('指定なし') }}</label>
            </div>
          </div>
          <div class="transit-preference-row">
            <label class="transit-field">
              <span class="transit-field-label">{{ __('検索の好み') }}</span>
              <select id="transit-preference">
                @foreach($preferenceLabels as $key => $label)
                  <option value="{{ $key }}" @selected($key === 'fastest')>{{ $label }}</option>
                @endforeach
              </select>
            </label>
            <div class="transit-field transit-operator-field">
              <span class="transit-field-label">{{ __('地域の優先') }}</span>
              <button
                type="button"
                class="transit-operator-btn"
                id="transit-operator-open"
                aria-haspopup="dialog"
                aria-expanded="false"
                aria-controls="transit-operator-modal"
              >
                <span class="transit-operator-value" id="transit-operator-label">{{ __('指定なし') }}</span>
              </button>
              <input type="hidden" id="transit-preferred-operator" value="" />
            </div>
          </div>
          <div class="transit-search-actions">
            <button type="submit" class="button-link transit-search-btn" id="transit-search-run">{{ __('経路を検索') }}</button>
          </div>
          @if(!empty($routeEngineIsExternal))
            <p class="hint">{{ __(':engine の時刻表で経路・運賃・乗換を検索します。取得できないときは内蔵エンジン（福岡都心の簡易ダイヤ）に切り替えます。外部の Yahoo! / Google も併用できます。', ['engine' => $routeEngineLabel]) }}</p>
          @else
            <p class="hint">{{ __('福岡都心の簡易ダイヤで RAPTOR 検索します（乗換2〜10分・待ち時間・乗換回数を評価）。外部の Yahoo! / Google も併用できます。') }}</p>
          @endif
        </form>

        <div class="transit-search-results" id="transit-search-results" hidden>
          <h3 class="transit-search-results-title" id="transit-search-results-title"></h3>
          <div class="transit-itineraries" id="transit-itineraries"></div>
          <div class="transit-search-links" id="transit-search-links"></div>
          <div class="transit-search-save">
            <button type="button" class="text-btn" id="transit-save-search">{{ __('＋ この経路をよく使う路線に登録') }}</button>
            <button type="button" class="text-btn" id="transit-share-search">{{ __('グループやメンバーに共有') }}</button>
          </div>
        </div>
      </section>

      @include('partials.ai-assist', [
        'aiId' => 'transit-ai',
        'aiEndpoint' => '/transit/ai-ask',
        'aiTitle' => $aiTopic['label'],
        'aiHint' => $aiTopic['hint'],
        'aiIcon' => $aiTopic['icon'],
        'aiSamples' => $aiTopic['samples'],
        'aiReady' => $aiReady,
        'aiContextHook' => 'transitAiContext',
        'aiResultHook' => 'transitAiResult',
      ])

      <section class="panel transit-favorites-panel">
        <div class="transit-section-head">
          <h2 class="transit-section-title">{{ __('よく使う路線') }}</h2>
          <button type="button" class="button-link transit-add-btn" id="transit-open-add">{{ __('＋ 登録') }}</button>
        </div>

        @if(count($favorites) === 0)
          <div class="transit-empty">
            <span class="transit-empty-icon" aria-hidden="true">🚏</span>
            <h3>{{ __('まだ登録がありません') }}</h3>
            <p class="hint">{{ __('登録された路線はありません。よく使う経路を追加してください。') }}</p>
          </div>
        @else
          <div class="transit-favorite-list">
            @foreach($favorites as $favorite)
              <article class="transit-favorite-card" data-favorite='@json($favorite)'>
                <div class="transit-favorite-main">
                  <h3 class="transit-favorite-name">{{ $favorite['name'] }}</h3>
                  @if($favorite['fromPlace'] || $favorite['toPlace'])
                    <p class="transit-favorite-route">{{ $favorite['fromPlace'] }} → {{ $favorite['toPlace'] }}</p>
                  @endif
                  @if($favorite['lineName'])
                    <p class="transit-favorite-line">{{ $favorite['lineName'] }}</p>
                  @endif
                  @if($favorite['notes'])
                    <p class="transit-favorite-notes">{{ $favorite['notes'] }}</p>
                  @endif
                </div>
                <div class="transit-favorite-actions">
                  <a href="{{ $favorite['yahooTransitUrl'] }}" target="_blank" rel="noopener noreferrer" class="text-btn">Yahoo!</a>
                  <a href="{{ $favorite['googleMapsUrl'] }}" target="_blank" rel="noopener noreferrer" class="text-btn">Google</a>
                  <button type="button" class="text-btn transit-edit-btn">{{ __('編集') }}</button>
                  <form method="post" action="/transit/{{ $favorite['id'] }}/delete" class="transit-inline-form" onsubmit='return confirm(@json(__('この路線を削除しますか？')))'>
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
                  </form>
                </div>
              </article>
            @endforeach
          </div>
        @endif
      </section>
    </main>

    <div class="modal modal-centered" id="transit-favorite-modal" hidden>
      <div class="modal-backdrop" data-close-transit-modal></div>
      <div class="modal-dialog transit-modal-dialog" role="dialog" aria-labelledby="transit-modal-title">
        <div class="modal-header">
          <h2 id="transit-modal-title">{{ __('よく使う路線を登録') }}</h2>
          <button type="button" class="modal-close" data-close-transit-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" action="/transit" id="transit-favorite-form" class="modal-form transit-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <input type="hidden" name="favorite_id" id="transit-favorite-id" value="" />
          <input type="hidden" name="category" id="transit-favorite-category" value="nishitetsu_bus" />

          <label>
            {{ __('名称') }}
            <input type="text" name="name" id="transit-favorite-name" placeholder="{{ __('例: 自宅→会社') }}" required />
          </label>
          <label>
            {{ __('出発') }}
            <input type="text" name="fromPlace" id="transit-favorite-from" placeholder="{{ __('例: 西新') }}" />
          </label>
          <label>
            {{ __('到着') }}
            <input type="text" name="toPlace" id="transit-favorite-to" placeholder="{{ __('例: 天神') }}" />
          </label>
          <label>
            {{ __('路線名（任意）') }}
            <input type="text" name="lineName" id="transit-favorite-line" placeholder="{{ __('例: 地下鉄空港線') }}" />
          </label>
          <label>
            {{ __('メモ（任意）') }}
            <input type="text" name="notes" id="transit-favorite-notes" />
          </label>
          <div class="transit-form-actions">
            <button type="button" class="secondary" data-close-transit-modal>{{ __('キャンセル') }}</button>
            <button type="submit" class="button-link" id="transit-favorite-submit">{{ __('保存') }}</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal modal-centered" id="transit-operator-modal" hidden>
      <div class="modal-backdrop" data-close-transit-operator></div>
      <div class="modal-dialog transit-modal-dialog" role="dialog" aria-labelledby="transit-operator-title">
        <div class="modal-header">
          <h2 id="transit-operator-title">{{ __('優先する交通機関') }}</h2>
          <button type="button" class="modal-close" data-close-transit-operator aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <div class="transit-operator-modal-body">
          <p class="hint">{{ __('よく使う会社を選ぶと、その経路を上に出します。指定なしでも検索できます。') }}</p>
          <label class="transit-field">
            <span class="visually-hidden">{{ __('交通機関を検索') }}</span>
            <input type="search" id="transit-operator-filter" placeholder="{{ __('例: 西鉄、東京メトロ、阪急') }}" autocomplete="off" />
          </label>
          <div class="transit-operator-list" id="transit-operator-list">
            <button type="button" class="transit-operator-item is-none" data-operator-id="">
              <strong>{{ __('指定なし') }}</strong>
              <span>{{ __('どの会社も優先しない') }}</span>
            </button>
            @foreach($operatorGroups ?? [] as $region => $operators)
              <h3 class="transit-operator-region" data-operator-region>{{ $region }}</h3>
              @foreach($operators as $operator)
                <button
                  type="button"
                  class="transit-operator-item"
                  data-operator-id="{{ $operator['id'] }}"
                  data-operator-name="{{ $operator['name'] }}"
                  data-operator-search="{{ $operator['name'] }} {{ $region }} {{ $operator['area'] }} {{ implode(' ', $operator['keywords']) }}"
                >
                  <strong>{{ $operator['name'] }}</strong>
                  <span>{{ $operator['area'] }}</span>
                </button>
              @endforeach
            @endforeach
          </div>
          <p class="hint transit-operator-empty" id="transit-operator-empty" hidden>{{ __('該当する交通機関がありません') }}</p>
        </div>
      </div>
    </div>

    <div class="modal modal-centered" id="transit-share-modal" hidden>
      <div class="modal-backdrop" data-close-transit-share></div>
      <div class="modal-dialog transit-modal-dialog" role="dialog" aria-labelledby="transit-share-title">
        <div class="modal-header">
          <h2 id="transit-share-title">{{ __('経路を共有') }}</h2>
          <button type="button" class="modal-close" data-close-transit-share aria-label="{{ __('閉じる') }}">×</button>
        </div>
        @if(($shareTargets ?? []) === [])
          <p class="hint transit-share-empty">{{ __('共有できるグループがありません。グループを作ってメンバーを招待すると、チャットに経路を送れます。') }}</p>
          @if(!empty($canOpenGroups))
            <p class="transit-form-actions">
              <a class="button-link" href="/groups">{{ __('グループへ') }}</a>
            </p>
          @endif
        @else
          <form id="transit-share-form" class="modal-form transit-form">
            <label class="transit-field">
              <span class="transit-field-label">{{ __('グループ') }}</span>
              <select id="transit-share-group" required>
                @foreach($shareTargets as $group)
                  <option value="{{ $group['id'] }}">{{ $group['name'] }}</option>
                @endforeach
              </select>
            </label>
            <label class="transit-field">
              <span class="transit-field-label">{{ __('送り先') }}</span>
              <select id="transit-share-peer">
                <option value="">{{ __('グループ全体') }}</option>
              </select>
            </label>
            <label class="transit-field">
              <span class="transit-field-label">{{ __('どの経路') }}</span>
              <select id="transit-share-itinerary"></select>
            </label>
            <label class="transit-field">
              <span class="transit-field-label">{{ __('ひとこと（任意）') }}</span>
              <input type="text" id="transit-share-note" maxlength="500" placeholder="{{ __('例: 明日の集合はこの便で') }}" />
            </label>
            <p class="hint" id="transit-share-status"></p>
            <div class="transit-form-actions">
              <button type="button" class="secondary" data-close-transit-share>{{ __('キャンセル') }}</button>
              <button type="submit" class="button-link transit-search-btn" id="transit-share-submit">{{ __('チャットに送る') }}</button>
            </div>
          </form>
        @endif
      </div>
    </div>

    <script>
      window.TRANSIT_CONFIG = {
        csrfToken: @json(csrf_token()),
        datetimeUnits: @json($datetimeUnitLabels),
        shareTargets: @json($shareTargets ?? []),
        noneOperator: @json(__('指定なし')),
        strings: {
          addFavorite: @json(__('よく使う路線を登録')),
          editFavorite: @json(__('路線を編集')),
          save: @json(__('保存')),
          update: @json(__('更新')),
          noRoute: @json(__('経路が見つかりませんでした')),
          noMatchingRoute: @json(__('条件に合う経路がありません。地名を「天神」「博多」「姪浜」「福岡空港」などに変えて試してください。')),
          nishitetsuPreferred: @json(__('優先')),
          operatorPreferred: @json(__('優先')),
          pickOperator: @json(__('地域の優先')),
          walk: @json(__('徒歩')),
          min: @json(app()->getLocale() === 'en' ? 'min' : '分'),
          wait: @json(__('待ち')),
          waitPrefix: @json(__('待ち')),
          transfers: @json(__('乗換')),
          times: @json(app()->getLocale() === 'en' ? '' : '回'),
          externalTitle: @json(__('外部サービスでも確認')),
          googleMapsRoute: @json(__('Google Maps でルート')),
          yahooRoute: @json(__('Yahoo!路線でルート')),
          needFrom: @json(__('出発（バス停・駅）を入力してください')),
          searching: @json(__('経路を検索中…')),
          searchFailed: @json(__('検索に失敗しました。再読み込みして再度お試しください。')),
          timetableFor: @json(__(':place の時刻表・地図')),
          enterDestinationHint: @json(__('到着地も入力すると乗換経路を出せます。')),
          googleMapsOpen: @json(__('Google Maps で開く')),
          yahooTimetable: @json(__('Yahoo!路線で時刻表')),
          zeroMin: @json(__('0分')),
          share: @json(__('共有')),
          shareNeedResult: @json(__('先に経路を検索してください。')),
          shareSent: @json(__('チャットに送りました')),
          shareFailed: @json(__('共有に失敗しました')),
          shareOpenChat: @json(__('チャットを開く')),
          groupAll: @json(__('グループ全体')),
          swapPlaces: @json(__('出発と到着を入れ替え')),
          voiceListening: @json(__('聞いています…')),
          voiceUnsupported: @json(__('このブラウザでは音声入力に対応していません。')),
          voiceHttps: @json(__('音声入力は HTTPS（または localhost）でのみ利用できます。')),
          voiceStartFailed: @json(__('音声認識を開始できませんでした。')),
          voiceMicDenied: @json(__('マイクの使用が許可されていません。')),
        },
      };
    </script>
    <script src="{{ asset('transit.js') }}?v={{ @filemtime(public_path('transit.js')) ?: time() }}"></script>
    @if($hasGoogleMapsApiKey)
      <script async defer src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&libraries=places&callback=initTransitAutocomplete"></script>
    @endif
  </body>
</html>
