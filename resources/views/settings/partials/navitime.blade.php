@php
  $nv = $navitimeSettings ?? [];
  $nvSettings = $nv['settings'] ?? [];
  $nvMode = $nvSettings['mode'] ?? 'rapidapi';
@endphp

<div class="panel storage-settings" id="navitime-api-settings">
  <h2>{{ __('NAVITIME（路線検索の経路探索）') }}</h2>
  <p class="hint">{{ __('路線検索の「検索」で、時刻表に基づく経路・運賃・乗換を取得します。未設定のときはアプリ内蔵の RAPTOR（福岡エリア）で検索します。') }}</p>
  <p class="hint">{{ __('契約は RapidAPI などの API マーケット（NAVITIME Route(totalnavi)）か、ナビタイムジャパンとの直接契約のどちらかです。') }}
    <a href="https://api-sdk.navitime.co.jp/api/" target="_blank" rel="noopener">{{ __('NAVITIME API の案内') }}</a>
  </p>
  @if(!empty($nv['last_test_message']))
    <p class="hint storage-test-result {{ ($nv['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $nv['last_test_message'] }}
      @if(!empty($nv['last_tested_at']))
        <span class="inline-hint">({{ $nv['last_tested_at'] }})</span>
      @endif
    </p>
  @endif
  <form method="post" action="/settings/api/navitime" class="storage-provider-form" id="navitime-api-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($nv['enabled'])) />
      {{ __('有効にする') }}
    </label>
    <div class="settings-mode-switch" role="radiogroup" aria-label="{{ __('契約方法') }}">
      <label class="settings-mode-option">
        <input type="radio" name="mode" value="rapidapi" @checked($nvMode !== 'direct') />
        <span>{{ __('API マーケット（RapidAPI）') }}</span>
      </label>
      <label class="settings-mode-option">
        <input type="radio" name="mode" value="direct" @checked($nvMode === 'direct') />
        <span>{{ __('ナビタイムと直接契約') }}</span>
      </label>
    </div>
    <label>
      {{ __('API キー') }}
      <input type="password" name="api_key" value="{{ $nv['api_key_masked'] ?? '' }}" placeholder="{{ __('RapidAPI の X-RapidAPI-Key など') }}" autocomplete="off" />
    </label>
    <div class="settings-mode-fields" data-mode="rapidapi">
      <label>
        {{ __('ルート検索のホスト') }}
        <input type="text" name="route_host" value="{{ $nvSettings['route_host'] ?? '' }}" placeholder="navitime-route-totalnavi.p.rapidapi.com" autocomplete="off" />
      </label>
      <label>
        {{ __('地点検索のホスト（任意）') }}
        <input type="text" name="node_host" value="{{ $nvSettings['node_host'] ?? '' }}" placeholder="navitime-transport.p.rapidapi.com" autocomplete="off" />
        <span class="hint">{{ __('NAVITIME Transport も契約している場合だけ入力します。空のときは Google マップの API キーで地名を緯度経度に変換します。') }}</span>
      </label>
    </div>
    <div class="settings-mode-fields" data-mode="direct">
      <label>
        {{ __('ベース URL') }}
        <input type="text" name="base_url" value="{{ $nvSettings['base_url'] ?? '' }}" placeholder="https://example.navitime.biz/xxxxxxxx/v1" autocomplete="off" />
      </label>
      <label>
        {{ __('認証ヘッダ名') }}
        <input type="text" name="auth_header" value="{{ $nvSettings['auth_header'] ?? 'x-api-key' }}" placeholder="x-api-key" autocomplete="off" />
        <span class="hint">{{ __('IP 制限だけで認証する契約のときは、API キーを空のままにしてください。') }}</span>
      </label>
    </div>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="navitime-api-test-btn">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" id="navitime-api-test-live"></span>
    </div>
  </form>
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const form = document.getElementById('navitime-api-form');
    const testBtn = document.getElementById('navitime-api-test-btn');
    const testLive = document.getElementById('navitime-api-test-live');
    const strings = {
      testing: @json(__('テスト中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };

    const syncMode = () => {
      const mode = form?.querySelector('input[name="mode"]:checked')?.value || 'rapidapi';
      form?.querySelectorAll('.settings-mode-fields').forEach((box) => {
        box.hidden = box.dataset.mode !== mode;
      });
    };
    form?.querySelectorAll('input[name="mode"]').forEach((radio) => {
      radio.addEventListener('change', syncMode);
    });
    syncMode();

    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      if (testLive) testLive.textContent = strings.testing;
      try {
        const res = await fetch('/settings/api/navitime/test', {
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
