<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <title>マップ - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="map-page">
    @include('partials.header', ['active' => 'map'])
    <main class="page-main map-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      @if(!$hasGoogleMapsApiKey)
        <div class="banner error map-api-warning">
          Google Maps API キーが未設定です。地図表示には <code>GOOGLE_MAPS_API_KEY</code> を <code>.env</code> に設定してください。
          保存済みルートの「ナビ開始」は Google Maps アプリで利用できます。
        </div>
      @endif

      <div class="map-layout">
        <aside class="map-sidebar panel">
          <h1 class="map-page-title">マップ・ナビ</h1>

          <section class="map-route-form-section">
            <h2 class="map-section-title">ルート検索</h2>
            <div class="map-route-form">
              <label>
                出発地
                <input type="text" id="map-origin" placeholder="出発地を入力" autocomplete="off" value="{{ $selectedRoute['originLabel'] ?? '' }}" />
              </label>
              <label>
                目的地
                <input type="text" id="map-destination" placeholder="目的地を入力" autocomplete="off" value="{{ $selectedRoute['destinationLabel'] ?? '' }}" />
              </label>
              <label>
                移動手段
                <select id="map-travel-mode">
                  @foreach($travelModeLabels as $mode => $label)
                    <option value="{{ $mode }}" @selected(($selectedRoute['travelMode'] ?? 'transit') === $mode)>{{ $label }}</option>
                  @endforeach
                </select>
              </label>
              <div class="map-route-form-actions">
                <button type="button" class="button-link" id="map-show-route">ルート表示</button>
                <button type="button" class="button-link secondary" id="map-start-nav">ナビ開始</button>
              </div>
            </div>
          </section>

          <section class="map-save-section">
            <h2 class="map-section-title">よく使うルート</h2>
            <form method="post" action="/map" class="map-save-form" id="map-save-form">
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <input type="hidden" name="originLat" id="map-save-origin-lat" />
              <input type="hidden" name="originLng" id="map-save-origin-lng" />
              <input type="hidden" name="destinationLat" id="map-save-destination-lat" />
              <input type="hidden" name="destinationLng" id="map-save-destination-lng" />
              <label>
                ルート名
                <input type="text" name="name" id="map-save-name" placeholder="例: 自宅→博多駅" required />
              </label>
              <input type="hidden" name="originLabel" id="map-save-origin-label" />
              <input type="hidden" name="destinationLabel" id="map-save-destination-label" />
              <input type="hidden" name="travelMode" id="map-save-travel-mode" value="{{ $selectedRoute['travelMode'] ?? 'transit' }}" />
              <button type="submit" class="button-link secondary">現在のルートを保存</button>
            </form>

            @if(count($routes) === 0)
              <p class="hint">保存されたルートはありません。</p>
            @else
              <div class="map-route-list">
                @foreach($routes as $route)
                  <article
                    class="map-route-card @if(!empty($selectedRoute) && $selectedRoute['id'] === $route['id']) is-selected @endif"
                    data-route='@json($route)'
                  >
                    <a href="/map?route={{ $route['id'] }}" class="map-route-card-link">
                      <strong>{{ $route['name'] }}</strong>
                      <span>{{ $route['originLabel'] }} → {{ $route['destinationLabel'] }}</span>
                      <span class="map-route-mode">{{ $route['travelModeLabel'] }}</span>
                    </a>
                    <div class="map-route-card-actions">
                      <button type="button" class="text-btn map-load-route-btn">表示</button>
                      <a href="{{ $route['googleNavUrl'] }}" target="_blank" rel="noopener noreferrer" class="text-btn">ナビ</a>
                      <form method="post" action="/map/{{ $route['id'] }}/delete" class="map-inline-form" onsubmit="return confirm('このルートを削除しますか？')">
                        @csrf
                        <input type="hidden" name="returnTo" value="/map" />
                        <button type="submit" class="text-btn danger">削除</button>
                      </form>
                    </div>
                  </article>
                @endforeach
              </div>
            @endif
          </section>
        </aside>

        <section class="map-canvas-panel panel">
          <div id="map-canvas" class="map-canvas" aria-label="Google Map">
            @unless($hasGoogleMapsApiKey)
              <div class="map-canvas-placeholder">
                <p>地図を表示するには Google Maps API キーが必要です。</p>
                <p class="hint">保存済みルートの「ナビ」ボタンで Google Maps アプリを開けます。</p>
              </div>
            @endunless
          </div>
          <div id="map-directions-panel" class="map-directions-panel" hidden>
            <h2 class="map-section-title">ルート案内</h2>
            <div id="map-directions-steps"></div>
          </div>
        </section>
      </div>
    </main>

    <script>
      window.MAP_PAGE_CONFIG = {
        apiKey: @json($googleMapsApiKey),
        hasApiKey: @json($hasGoogleMapsApiKey),
        defaultCenter: @json($defaultCenter),
        selectedRoute: @json($selectedRoute),
        travelModeLabels: @json($travelModeLabels),
      }
    </script>
    @if($hasGoogleMapsApiKey)
      <script async defer src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&libraries=places&callback=initMapPage"></script>
    @endif
    <script src="{{ asset('map.js') }}"></script>
  </body>
</html>
