<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('路線検索') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="transit-page">
    @include('partials.header', ['active' => 'transit'])
    <main class="page-main transit-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="transit-top-bar">
        <h1 class="transit-page-title">{{ __('路線検索') }}</h1>
        <p class="hint">{{ __('福岡エリアの路線を中心に、よく使う経路を登録してすぐ検索できます。') }}</p>
      </div>

      <div class="transit-category-tabs" role="tablist" aria-label="{{ __('交通機関') }}">
        @foreach($tabLabels as $key => $label)
          <a
            href="{{ $buildTransitQuery(['category' => $key]) }}"
            class="transit-category-tab @if($filters['category'] === $key) is-active @endif"
            role="tab"
          >
            <span class="transit-category-icon" aria-hidden="true">{{ $tabIcons[$key] }}</span>
            {{ $label }}
          </a>
        @endforeach
      </div>

      <section class="panel transit-search-panel">
        <h2 class="transit-section-title">{{ $tabLabels[$filters['category']] }} {{ __('を検索') }}</h2>
        @if($isAll)
          <p class="hint">{{ __('出発・到着を入力すると、電車・バス・渡船などを乗り継いだ最適ルートを検索します。') }}</p>
        @endif
        <form class="transit-search-form" id="transit-search-form">
          <label>
            {{ __('出発（バス停・駅）') }}
            <input type="text" id="transit-from" placeholder="{{ __('例: 天神') }}" autocomplete="off" />
            <div class="pac-holder" id="transit-from-holder"></div>
          </label>
          <label>
            {{ __('到着（任意）') }}
            <input type="text" id="transit-to" placeholder="{{ __('例: 博多') }}" autocomplete="off" />
            <div class="pac-holder" id="transit-to-holder"></div>
          </label>
          <div class="transit-time-block">
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
            <label>
              {{ __('検索の好み') }}
              <select id="transit-preference">
                @foreach($preferenceLabels as $key => $label)
                  <option value="{{ $key }}" @selected($key === 'fastest')>{{ $label }}</option>
                @endforeach
              </select>
            </label>
            <label class="inline-check transit-nishitetsu-prefer">
              <input type="checkbox" id="transit-prefer-nishitetsu" checked />
              {{ __('福岡は西鉄バスを優先') }}
            </label>
          </div>
          <div class="transit-search-actions">
            <button type="submit" class="button-link" id="transit-search-run">{{ __('RAPTORで検索') }}</button>
          </div>
          <p class="hint">{{ __('福岡都心の簡易ダイヤで RAPTOR 検索します（乗換2〜10分・待ち時間・乗換回数を評価）。外部の Yahoo! / Google も併用できます。') }}</p>
        </form>

        <div class="transit-search-results" id="transit-search-results" hidden>
          <h3 class="transit-search-results-title" id="transit-search-results-title"></h3>
          <div class="transit-itineraries" id="transit-itineraries"></div>
          <div class="transit-search-links" id="transit-search-links"></div>
          <div class="transit-search-save">
            <button type="button" class="text-btn" id="transit-save-search">{{ __('＋ この経路をよく使う路線に登録') }}</button>
          </div>
        </div>

        @if(!empty($externalSearch[$filters['category']]))
          <p class="hint inline-hint">
            {{ __('公式:') }}
            <a href="{{ $externalSearch[$filters['category']]['url'] }}" target="_blank" rel="noopener noreferrer">
              {{ $externalSearch[$filters['category']]['label'] }}
            </a>
          </p>
        @endif
      </section>

      <section class="panel transit-favorites-panel">
        <div class="transit-section-head">
          <h2 class="transit-section-title">{{ __('よく使う路線') }}（{{ $tabLabels[$filters['category']] }}）</h2>
          <button type="button" class="button-link" id="transit-open-add">{{ __('＋ 登録') }}</button>
        </div>

        @if(count($favorites) === 0)
          <p class="hint">{{ __('登録された路線はありません。よく使う経路を追加してください。') }}</p>
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

      <section class="panel transit-all-panel">
        <h2 class="transit-section-title">{{ __('すべての登録路線') }}</h2>
        <div class="transit-all-grid">
          @foreach($categoryLabels as $key => $label)
            <div class="transit-all-group">
              <h3 class="transit-all-group-title">{{ $categoryIcons[$key] }} {{ $label }}</h3>
              @forelse($groupedFavorites[$key] ?? [] as $item)
                <a href="{{ $buildTransitQuery(['category' => $key]) }}" class="transit-all-item">{{ $item['name'] }}</a>
              @empty
                <p class="hint">{{ __('未登録') }}</p>
              @endforelse
            </div>
          @endforeach
        </div>
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

          <label>
            {{ __('交通機関') }}
            <select name="category" id="transit-favorite-category">
              @foreach($categoryLabels as $key => $label)
                <option value="{{ $key }}" @selected($filters['category'] === $key)>{{ $label }}</option>
              @endforeach
            </select>
          </label>
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

    <script>
      window.TRANSIT_CONFIG = {
        category: @json($filters['category']),
        csrfToken: @json(csrf_token()),
        datetimeUnits: @json($datetimeUnitLabels),
      };
    </script>
    <script src="{{ asset('transit.js') }}?v=9"></script>
    @if($hasGoogleMapsApiKey)
      <script async defer src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&libraries=places&callback=initTransitAutocomplete"></script>
    @endif
  </body>
</html>
