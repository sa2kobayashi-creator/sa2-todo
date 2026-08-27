@php
  $yt = $youtubeSettings ?? [];
@endphp

<div class="panel storage-settings" id="youtube-api-settings">
  <h2>{{ __('YouTube検索（Data API）') }}</h2>
  <p class="hint">{{ __('Google Cloud で YouTube Data API v3 を有効化し、動画検索専用の API キーを登録してください。Maps 用キーは流用しないでください。キーの制限は「アプリケーションの制限: なし（またはサーバーIP）」「API の制限: YouTube Data API v3（search.list をブロックしない）」です。URL 貼り付け再生にはキーは不要です。') }}</p>
  @if(!empty($yt['last_test_message']))
    <p class="hint storage-test-result {{ ($yt['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $yt['last_test_message'] }}
      @if(!empty($yt['last_tested_at']))
        <span class="inline-hint">({{ $yt['last_tested_at'] }})</span>
      @endif
    </p>
  @endif
  <form method="post" action="/settings/api/youtube" class="storage-provider-form" id="youtube-api-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($yt['enabled'])) />
      {{ __('有効にする') }}
    </label>
    <label>
      {{ __('YouTube Data API キー') }}
      <input type="password" name="api_key" value="{{ $yt['api_key_masked'] ?? '' }}" placeholder="AIza..." autocomplete="off" />
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="youtube-api-test-btn">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" id="youtube-api-test-live"></span>
    </div>
  </form>
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const testBtn = document.getElementById('youtube-api-test-btn');
    const testLive = document.getElementById('youtube-api-test-live');
    const strings = {
      testing: @json(__('テスト中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };
    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      if (testLive) testLive.textContent = strings.testing;
      try {
        const res = await fetch('/settings/api/youtube/test', {
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
