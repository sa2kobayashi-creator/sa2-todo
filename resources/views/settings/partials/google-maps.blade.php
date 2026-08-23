@php
  $gm = $googleMapsSettings ?? [];
@endphp

<div class="panel storage-settings" id="google-maps-api-settings">
  <h2>{{ __('Google マップ（Map / Transit）') }}</h2>
  <p class="hint">{{ __('Map と乗換案内の地図・場所検索に使います。Google Cloud で Maps JavaScript API と Places API を有効化し、HTTP リファラ制限と予算アラートを設定してください。キーはアプリ全体で1セットです。') }}</p>
  @if(!empty($gm['uses_env_fallback']))
    <p class="hint">{{ __('DB に未保存のため、いまは .env の GOOGLE_MAPS_API_KEY を使用しています。ここに保存すると管理画面の設定が優先されます。') }}</p>
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
