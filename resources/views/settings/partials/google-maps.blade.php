@php
  $gm = $googleMapsSettings ?? [];
@endphp

<div class="panel storage-settings" id="google-maps-api-settings">
  <h2>{{ __('Google マップ（Map / Transit）') }}</h2>
  <p class="hint">{{ __('Map と乗換案内の地図・場所の入力候補に使います。接続テストは Places API (New) でマップ用キーを確認します。Routes API の成功とは別です。キーはアプリ全体で1セットです。') }}</p>
  <p class="hint">{{ __('マップ用キーを発行したプロジェクトで、次を有効にしてください。') }}
    <a href="https://console.cloud.google.com/apis/library/maps-backend.googleapis.com" target="_blank" rel="noopener">{{ __('Maps JavaScript API') }}</a>
    /
    <a href="https://console.cloud.google.com/apis/library/places-backend.googleapis.com" target="_blank" rel="noopener">{{ __('Places API') }}</a>
    /
    <a href="https://console.cloud.google.com/apis/library/places.googleapis.com" target="_blank" rel="noopener">{{ __('Places API (New)') }}</a>
    /
    <a href="https://console.cloud.google.com/apis/library/geocoding-backend.googleapis.com" target="_blank" rel="noopener">{{ __('Geocoding API') }}</a>
  </p>
  <p class="hint">{{ __('地図上の車・徒歩・自転車のルート線は、下の Google Maps Routes API を使います。公共交通は路線検索と同じエンジンです。Google は日本の交通機関ルートが API の提供対象外です。') }}</p>
  <p class="hint">{{ __('キーに「API の制限」がある場合は、上の API を許可リストに含めてください。アプリケーション制限は HTTP リファラ（サイトの URL）です。IP 制限は Routes 用キー向けです。予算アラートも設定してください。') }}</p>

  <div class="panel-inset maps-referrer-box" style="margin:12px 0;padding:14px 16px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc;">
    <h3 style="margin:0 0 8px;font-size:1rem;">{{ __('必須: HTTP リファラ制限') }}</h3>
    <p class="hint" style="margin:0 0 8px;">{{ __('マップ用キーはブラウザに載るため、Google Cloud コンソールで「アプリケーションの制限」→「HTTP リファラ」を設定してください。未設定のまま公開するとキー盗用による請求リスクがあります。') }}</p>
    <p class="hint" style="margin:0 0 6px;">
      <a href="{{ $gm['credentials_url'] ?? 'https://console.cloud.google.com/apis/credentials' }}" target="_blank" rel="noopener">{{ __('Google Cloud の認証情報を開く') }}</a>
    </p>
    <p class="hint" style="margin:0 0 4px;">{{ __('許可するリファラ（コピーしてコンソールへ）') }}</p>
    <ul class="legal-list" style="margin:0 0 8px;">
      @foreach(($gm['referrer_patterns'] ?? []) as $pattern)
        <li><code>{{ $pattern }}</code></li>
      @endforeach
    </ul>
    <p class="hint" style="margin:0;">{{ __('あわせて Cloud コンソールで予算アラートを設定してください。') }}</p>
  </div>

  @if(!empty($gm['uses_env_fallback']))
    <p class="hint">{{ __('DB に未保存のため、いまは .env の GOOGLE_MAPS_API_KEY を使用しています。ここに保存すると管理画面の設定が優先されます。') }}</p>
  @endif
  @if(empty($gm['referrer_restriction_confirmed']))
    <p class="hint storage-test-result is-fail">{{ __('HTTP リファラ制限の確認チェックがまだオフです。コンソール設定後に下のチェックを入れて保存してください。') }}</p>
  @elseif(!empty($gm['referrer_restriction_confirmed_at']))
    <p class="hint storage-test-result is-ok">{{ __('リファラ制限の確認済み') }} ({{ \Illuminate\Support\Carbon::parse($gm['referrer_restriction_confirmed_at'])->format('Y-m-d H:i') }})</p>
  @endif
  @if(!empty($gm['last_test_message']))
    <p class="hint storage-test-result {{ ($gm['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $gm['last_test_message'] }}
      @if(!empty($gm['last_tested_at']))
        <span class="inline-hint">({{ $gm['last_tested_at'] }})</span>
      @endif
    </p>
  @endif
  <form method="post" action="/settings/api/google-maps" class="storage-provider-form" id="google-maps-api-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($gm['enabled'])) />
      {{ __('有効にする') }}
    </label>
    <label>
      {{ __('Google Maps API キー') }}
      <input type="password" name="api_key" value="{{ $gm['api_key_masked'] ?? '' }}" placeholder="AIza..." autocomplete="off" />
    </label>
    <label class="storage-enable">
      <input type="checkbox" name="referrer_restriction_confirmed" value="1" @checked(!empty($gm['referrer_restriction_confirmed'])) />
      {{ __('HTTP リファラ制限を Cloud コンソールで設定済み') }}
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="google-maps-api-test-btn">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" id="google-maps-api-test-live"></span>
    </div>
  </form>
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const testBtn = document.getElementById('google-maps-api-test-btn');
    const testLive = document.getElementById('google-maps-api-test-live');
    const strings = {
      testing: @json(__('テスト中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };
    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      if (testLive) testLive.textContent = strings.testing;
      try {
        const res = await fetch('/settings/api/google-maps/test', {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': csrf,
            Accept: 'application/json',
          },
        });
        const data = await res.json().catch(() => ({}));
        if (testLive) {
          testLive.textContent = data.message || '';
          testLive.classList.toggle('is-ok', Boolean(data.ok));
          testLive.classList.toggle('is-fail', !data.ok);
        }
      } catch (_) {
        if (testLive) {
          testLive.textContent = strings.networkError;
          testLive.classList.add('is-fail');
        }
      } finally {
        testBtn.disabled = false;
      }
    });
  })();
</script>
