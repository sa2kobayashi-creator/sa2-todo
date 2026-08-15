@php
  $lk = $livekitSettings ?? [
    'enabled' => false,
    'ready' => false,
    'url' => '',
    'api_key_masked' => '',
    'api_secret_masked' => '',
  ];
@endphp
<div class="panel storage-settings" id="livekit-call">
  <h2>{{ __('LiveKit 通話（試作）') }}</h2>
  <p class="hint">{{ __('メッセージの個別チャットで音声・ビデオ通話を使うための LiveKit Cloud（または自前 LiveKit）設定です。Project URL と API Key / Secret を登録してください。') }}</p>

  @if(!empty($lk['last_test_message']))
    <p class="hint storage-test-result {{ ($lk['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $lk['last_test_message'] }}
      @if(!empty($lk['last_tested_at']))
        <span class="inline-hint">({{ $lk['last_tested_at'] }})</span>
      @endif
    </p>
  @endif

  <form method="post" action="/settings/api/livekit" class="storage-provider-form" id="livekit-api-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($lk['enabled'])) />
      {{ __('有効にする') }}
    </label>
    <p class="hint">{{ __('LiveKit Cloud の Project Settings から URL（wss://…）と API Key / Secret をコピーします。保存するときは「有効にする」にチェックを入れてください。') }}</p>
    <label>
      {{ __('LiveKit URL') }}
      <input type="text" name="url" value="{{ $lk['url'] ?? '' }}" autocomplete="off" placeholder="wss://xxxxx.livekit.cloud" />
    </label>
    <label>
      {{ __('API Key') }}
      <input type="password" name="api_key" value="" autocomplete="new-password" placeholder="{{ !empty($lk['api_key_masked']) ? __('••••••••（変更するときだけ入力）') : 'APIxxxxxxxx' }}" />
    </label>
    <label>
      {{ __('API Secret') }}
      <input type="password" name="api_secret" value="" autocomplete="new-password" placeholder="{{ !empty($lk['api_secret_masked']) ? __('••••••••（変更するときだけ入力）') : '' }}" />
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="livekit-api-test-btn">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" id="livekit-api-test-live"></span>
    </div>
  </form>
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const testBtn = document.getElementById('livekit-api-test-btn');
    const testLive = document.getElementById('livekit-api-test-live');
    const strings = {
      testing: @json(__('テスト中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };
    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      if (testLive) testLive.textContent = strings.testing;
      try {
        const res = await fetch('/settings/api/livekit/test', {
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
