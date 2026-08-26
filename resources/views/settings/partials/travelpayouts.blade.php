@php
  $tp = $travelpayoutsSettings ?? [];
@endphp

<div class="panel storage-settings" id="travelpayouts-api-settings">
  <h2>{{ __('Travelpayouts（航空運賃）') }}</h2>
  <p class="hint">{{ __('航空機能の運賃目安取得に使います。Travelpayouts（Aviasales）の API トークンを登録してください。キャッシュ価格のため公式サイトと差が出ることがあります。') }}</p>
  @if(!empty($tp['last_test_message']))
    <p class="hint storage-test-result {{ ($tp['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $tp['last_test_message'] }}
      @if(!empty($tp['last_tested_at']))
        <span class="inline-hint">({{ $tp['last_tested_at'] }})</span>
      @endif
    </p>
  @endif
  <form method="post" action="/settings/api/travelpayouts" class="storage-provider-form" id="travelpayouts-api-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($tp['enabled'])) />
      {{ __('有効にする') }}
    </label>
    <label>
      {{ __('API トークン') }}
      <input type="password" name="token" value="{{ $tp['token_masked'] ?? '' }}" placeholder="xxxxxxxxxxxxxxxx" autocomplete="off" />
    </label>
    <label>
      {{ __('Project ID（任意）') }}
      <input type="password" name="project_id" value="{{ $tp['project_id_masked'] ?? '' }}" placeholder="TRAVELPAYOUTS_PROJECT_ID" autocomplete="off" />
    </label>
    <label>
      {{ __('優先航空会社コード') }}
      <input type="text" name="prefer_airline" value="{{ $tp['prefer_airline'] ?? '' }}" placeholder="{{ __('検索の絞り込みには使いません') }}" maxlength="8" />
      <span class="hint">{{ __('フライト検索は空欄で全社の目安を出します。ここは接続テスト用の任意項目です。') }}</span>
    </label>
    <label class="storage-enable">
      <input type="checkbox" name="direct_only" value="1" @checked(!empty($tp['direct_only'])) />
      {{ __('直行便のみ検索する') }}
    </label>
    <label>
      {{ __('PHP 市場コード') }}
      <input type="text" name="market_php" value="{{ $tp['market_php'] ?? 'ph' }}" placeholder="ph" maxlength="8" />
    </label>
    <label>
      {{ __('JPY 市場コード') }}
      <input type="text" name="market_jpy" value="{{ $tp['market_jpy'] ?? 'jp' }}" placeholder="jp" maxlength="8" />
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="travelpayouts-api-test-btn">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" id="travelpayouts-api-test-live"></span>
    </div>
  </form>
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const testBtn = document.getElementById('travelpayouts-api-test-btn');
    const testLive = document.getElementById('travelpayouts-api-test-live');
    const strings = {
      testing: @json(__('テスト中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };
    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      if (testLive) testLive.textContent = strings.testing;
      try {
        const res = await fetch('/settings/api/travelpayouts/test', {
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
