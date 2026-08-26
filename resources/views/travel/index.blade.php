<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('航空') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ @filemtime(public_path('app.css')) ?: time() }}" />
  </head>
  <body class="travel-page">
    @include('partials.header', ['active' => 'travel'])
    <main class="page-main travel-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <header class="travel-page-head">
        <div>
          <h1 class="travel-page-title">{{ __('航空') }}</h1>
          <p class="travel-page-lead">
            {{ __('区間・日付・航空会社からフライトの目安運賃を出します。金額はキャッシュの目安です。便と税込金額は予約前に航空会社で確認してください。') }}
          </p>
        </div>
      </header>

      @php
        $sd = $searchDefaults ?? [];
        $ft = $fareTable ?? null;
        $selectedFlights = $selectedFlights ?? [];
      @endphp

      <section class="panel travel-panel" id="travel-fare-table">
        <h2 class="travel-section-title">{{ __('フライトを探す') }}</h2>
        <p class="hint">{{ __('出発・到着と期間を指定すると、日付ごとのフライト目安（航空会社・運賃）が一覧になります。運賃は目安です。') }}</p>

        <form method="post" action="/travel/fares/table" class="travel-form" id="travel-fare-table-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <div class="travel-form-grid travel-search-airports">
            @include('travel.partials.airport-field', [
              'name' => 'origin',
              'label' => __('出発空港'),
              'value' => $sd['origin'] ?? '',
              'required' => true,
            ])
            <div class="travel-search-swap-wrap">
              <button type="button" class="travel-places-swap" id="travel-places-swap" title="{{ __('出発と到着を入れ替え') }}" aria-label="{{ __('出発と到着を入れ替え') }}">⇄</button>
            </div>
            @include('travel.partials.airport-field', [
              'name' => 'destination',
              'label' => __('到着空港'),
              'value' => $sd['destination'] ?? '',
              'required' => true,
            ])
            <label>{{ __('検索形態') }}
              <select name="tableMode" id="travel-fare-table-mode">
                <option value="ow" @selected(($sd['mode'] ?? 'ow') === 'ow')>{{ __('片道') }}</option>
                <option value="rt" @selected(($sd['mode'] ?? '') === 'rt')>{{ __('往復') }}</option>
              </select>
            </label>
            <label>{{ __('表示通貨') }}
              <select name="tableCurrency">
                <option value="JPY" @selected(($sd['currency'] ?? 'JPY') === 'JPY')>JPY</option>
                <option value="PHP" @selected(($sd['currency'] ?? '') === 'PHP')>PHP</option>
              </select>
            </label>
            <label>{{ __('航空会社（任意）') }}
              <input type="text" name="airlineCode" value="{{ $sd['airlineCode'] ?? '' }}" maxlength="8" placeholder="{{ __('例: NH / JL / 空欄で全社') }}" />
            </label>
            <label>{{ __('出発期間（開始）') }}
              <input type="date" name="departFrom" value="{{ $sd['departFrom'] ?? '' }}" required />
            </label>
            <label>{{ __('出発期間（終了）') }}
              <input type="date" name="departTo" value="{{ $sd['departTo'] ?? '' }}" required />
            </label>
            <label class="travel-fare-return-field">{{ __('帰国期間（開始）') }}
              <input type="date" name="returnFrom" value="{{ $sd['returnFrom'] ?? '' }}" />
            </label>
            <label class="travel-fare-return-field">{{ __('帰国期間（終了）') }}
              <input type="date" name="returnTo" value="{{ $sd['returnTo'] ?? '' }}" />
            </label>
          </div>
          <p class="hint">{{ __('各期間は最大62日です。データがない日は「—」になります。表示金額は目安です。航空会社を空欄にすると複数社の候補が出ます。') }}</p>
          <div class="travel-form-actions">
            <button type="submit" class="button-link">{{ __('フライトを表示') }}</button>
          </div>
        </form>
        @if(is_array($ft))
          <form method="post" action="/travel/fares/table/clear" class="inline-form travel-fare-clear-form">
            @csrf
            <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
            <button type="submit" class="secondary">{{ __('クリア') }}</button>
          </form>
        @endif
        @include('travel.partials.fare-table-result', ['ft' => $ft, 'returnTo' => $returnTo])
      </section>

      <section class="panel travel-panel" id="travel-planned-flights">
        <div class="travel-section-head">
          <h2 class="travel-section-title">{{ __('フライト予定') }}</h2>
          @if(count($selectedFlights) > 0)
            <form method="post" action="/travel/fares/select/clear">
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <button type="submit" class="secondary">{{ __('すべて外す') }}</button>
            </form>
          @endif
        </div>
        @if(count($selectedFlights) === 0)
          <p class="hint">{{ __('候補フライト（目安）から「表示する」を選ぶと、ここに出します。') }}</p>
        @else
          <div class="travel-trip-list">
            @foreach($selectedFlights as $flight)
              <article class="travel-trip-card">
                <header class="travel-trip-card-head">
                  <div>
                    <h3>
                      {{ $flight['airlineLabel'] ?? \App\Support\AirlineName::label((string) ($flight['airline'] ?? '')) }}
                      <span class="travel-badge">{{ ($flight['mode'] ?? '') === 'rt' ? __('往復') : __('片道') }}</span>
                    </h3>
                    <p class="travel-trip-route">
                      {{ $flight['origin'] }}→{{ $flight['destination'] }}
                      {{ $flight['departOn'] }}
                      @if(!empty($flight['returnOn']))
                        / {{ $flight['destination'] }}→{{ $flight['origin'] }} {{ $flight['returnOn'] }}
                      @endif
                    </p>
                  </div>
                  <form method="post" action="/travel/fares/select/{{ $flight['id'] }}/delete">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <button type="submit" class="secondary">{{ __('外す') }}</button>
                  </form>
                </header>
                <p class="travel-flight-candidate-fare">
                  <span class="travel-estimate-label">{{ __('目安') }}</span>
                  @if(($flight['currency'] ?? 'JPY') === 'JPY' && ($flight['priceJpy'] ?? null) !== null)
                    ¥{{ number_format((int) $flight['priceJpy']) }}
                  @elseif(($flight['pricePhp'] ?? null) !== null)
                    ₱{{ number_format((int) $flight['pricePhp']) }}
                  @else
                    —
                  @endif
                  @if(!empty($flight['officialUrl']))
                    · <a href="{{ $flight['officialUrl'] }}" target="_blank" rel="noopener noreferrer">{{ $flight['officialLabel'] ?: __('航空会社で確認') }}</a>
                  @endif
                  @if(!empty($flight['searchUrl']))
                    · <a href="{{ $flight['searchUrl'] }}" target="_blank" rel="noopener noreferrer">{{ __('比較サイトで確認') }}</a>
                  @endif
                </p>
              </article>
            @endforeach
          </div>
        @endif
      </section>

      @include('partials.ai-assist', [
        'aiId' => 'travel-ai',
        'aiEndpoint' => '/travel/ai-ask',
        'aiTitle' => $aiTopic['label'],
        'aiHint' => $aiTopic['hint'],
        'aiIcon' => $aiTopic['icon'],
        'aiSamples' => $aiTopic['samples'],
        'aiReady' => $aiReady,
        'aiContextHook' => 'travelAiContext',
      ])
    </main>
    <script src="{{ asset('travel.js') }}?v={{ @filemtime(public_path('travel.js')) ?: time() }}"></script>
  </body>
</html>
