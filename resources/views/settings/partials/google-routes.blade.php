@php
  $gr = $googleRoutesSettings ?? [];
@endphp

<div class="panel storage-settings" id="google-routes-api-settings">
  <h2>{{ __('Google Maps Routes API（路線検索の経路探索）') }}</h2>
  <p class="hint">{{ __('車・徒歩の地図ルートに使えます。Google は日本の交通機関ルートが API の提供対象外です。電車・バスの検索には NAVITIME または駅すぱあと を設定してください。') }}
    <a href="https://developers.google.com/maps/documentation/routes" target="_blank" rel="noopener">{{ __('Routes API の案内') }}</a>
  </p>
  <p class="hint">{{ __('出発地・到着地の地名を座標にするのは Geocoding API です。経路そのもの（Routes API）とは別で、上の Google マップのキーを使います。') }}
    <a href="https://console.cloud.google.com/apis/library/geocoding-backend.googleapis.com" target="_blank" rel="noopener">{{ __('Geocoding API を有効化') }}</a>
  </p>
  <p class="hint">{{ __('専用キーを空のまま有効にすると、上の Google マップのキーを流用します。') }}</p>
  @if(!empty($gr['uses_maps_key_fallback']))
    <p class="hint">{{ __('いまは Google マップの API キーを流用しています。専用キーを保存するとそちらが優先されます。') }}</p>
  @endif
  @if(!empty($gr['last_test_message']))
    <p class="hint storage-test-result {{ ($gr['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $gr['last_test_message'] }}
      @if(!empty($gr['last_tested_at']))
        <span class="inline-hint">({{ $gr['last_tested_at'] }})</span>
      @endif
    </p>
  @endif
  <form method="post" action="/settings/api/google-routes" class="storage-provider-form" id="google-routes-api-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($gr['enabled'])) />
      {{ __('有効にする') }}
    </label>
    <label>
      {{ __('API キー') }}
      <input type="password" name="api_key" value="{{ $gr['api_key_masked'] ?? '' }}" placeholder="AIza..." autocomplete="off" />
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="google-routes-api-test-btn">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" id="google-routes-api-test-live"></span>
    </div>
  </form>
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const testBtn = document.getElementById('google-routes-api-test-btn');
    const testLive = document.getElementById('google-routes-api-test-live');
    const strings = {
      testing: @json(__('テスト中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };
    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      if (testLive) testLive.textContent = strings.testing;
      try {
        const res = await fetch('/settings/api/google-routes/test', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
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
