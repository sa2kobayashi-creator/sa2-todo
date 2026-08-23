@php
  $gco = $googleCalendarOauthSettings ?? [];
@endphp

<div class="panel storage-settings" id="google-calendar-oauth-settings">
  <h2>{{ __('Google カレンダー（OAuth アプリ）') }}</h2>
  <p class="hint">{{ __('マイページのカレンダー連携で使う、Google Cloud の OAuth クライアントです。カレンダー API を有効化し、承認済みリダイレクト URI に下の URI を登録してください。キーはアプリ全体で1セットです。') }}</p>
  @if(!empty($gco['uses_env_fallback']))
    <p class="hint">{{ __('DB に未保存のため、いまは .env の GOOGLE_CLIENT_ID を使用しています。ここに保存すると管理画面の設定が優先されます。') }}</p>
  @endif
  @if(!empty($gco['last_test_message']))
    <p class="hint storage-test-result {{ ($gco['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $gco['last_test_message'] }}
      @if(!empty($gco['last_tested_at']))
        <span class="inline-hint">({{ $gco['last_tested_at'] }})</span>
      @endif
    </p>
  @endif
  <form method="post" action="/settings/api/google-calendar" class="storage-provider-form" id="google-calendar-oauth-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($gco['enabled'])) />
      {{ __('有効にする') }}
    </label>
    <label>
      {{ __('Client ID') }}
      <input type="text" name="client_id" value="{{ $gco['client_id'] ?? '' }}" placeholder="xxxxx.apps.googleusercontent.com" autocomplete="off" />
    </label>
    <label>
      {{ __('Client Secret') }}
      <input type="password" name="client_secret" value="{{ $gco['client_secret_masked'] ?? '' }}" placeholder="GOCSPX-..." autocomplete="off" />
    </label>
    <label>
      {{ __('リダイレクト URI') }}
      <input type="url" name="redirect_uri" value="{{ $gco['redirect_uri'] ?? '' }}" autocomplete="off" />
    </label>
    <p class="hint">{{ __('Google Cloud の「承認済みのリダイレクト URI」に、この URI をそのまま登録します。空欄なら APP_URL から自動生成します。') }}</p>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="google-calendar-oauth-test-btn">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" id="google-calendar-oauth-test-live"></span>
    </div>
  </form>
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const testBtn = document.getElementById('google-calendar-oauth-test-btn');
    const testLive = document.getElementById('google-calendar-oauth-test-live');
    const strings = {
      testing: @json(__('テスト中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };
    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      if (testLive) testLive.textContent = strings.testing;
      try {
        const res = await fetch('/settings/api/google-calendar/test', {
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
