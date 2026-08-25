@php
  $ek = $ekispertSettings ?? [];
  $ekSettings = $ek['settings'] ?? [];
@endphp

<div class="panel storage-settings" id="ekispert-api-settings">
  <h2>{{ __('駅すぱあと（路線検索の経路探索）') }}</h2>
  <p class="hint">{{ __('路線検索の「検索」で、駅すぱあとの時刻表に基づく経路・運賃・乗換を取得します。') }}
    <a href="https://docs.ekispert.com/v1/" target="_blank" rel="noopener">{{ __('駅すぱあと Webサービスの案内') }}</a>
  </p>
  @if(!empty($ek['last_test_message']))
    <p class="hint storage-test-result {{ ($ek['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $ek['last_test_message'] }}
      @if(!empty($ek['last_tested_at']))
        <span class="inline-hint">({{ $ek['last_tested_at'] }})</span>
      @endif
    </p>
  @endif
  <form method="post" action="/settings/api/ekispert" class="storage-provider-form" id="ekispert-api-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($ek['enabled'])) />
      {{ __('有効にする') }}
    </label>
    <label>
      {{ __('アクセスキー') }}
      <input type="password" name="api_key" value="{{ $ek['api_key_masked'] ?? '' }}" placeholder="{{ __('駅すぱあと Webサービスのアクセスキー') }}" autocomplete="off" />
    </label>
    <label>
      {{ __('ベース URL') }}
      <input type="text" name="base_url" value="{{ $ekSettings['base_url'] ?? '' }}" placeholder="https://api.ekispert.jp/v1/json" autocomplete="off" />
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="ekispert-api-test-btn">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" id="ekispert-api-test-live"></span>
    </div>
  </form>
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const testBtn = document.getElementById('ekispert-api-test-btn');
    const testLive = document.getElementById('ekispert-api-test-live');
    const strings = {
      testing: @json(__('テスト中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };
    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      if (testLive) testLive.textContent = strings.testing;
      try {
        const res = await fetch('/settings/api/ekispert/test', {
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
