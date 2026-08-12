@php
  $mm = $messengerMessaging ?? [
    'enabled' => false,
    'ready' => false,
    'configured' => false,
    'connected' => false,
    'page_access_token_masked' => '',
    'app_secret_masked' => '',
    'verify_token_masked' => '',
    'app_id' => '',
    'page_name' => '',
    'webhook_url' => url('/webhooks/messenger'),
    'displayName' => null,
    'linkedAt' => null,
  ];
@endphp
<div class="panel storage-settings" id="facebook-messenger">
  <h2>{{ __('Facebook Messenger 通知連携') }}</h2>
  <p class="hint">{{ __('Meta の Facebook アプリ／ページ情報を登録します。保存後、ページの Messenger に連携コードを送ると個人アカウントとつながります。') }}</p>

  @if(!empty($mm['last_test_message']))
    <p class="hint storage-test-result {{ ($mm['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $mm['last_test_message'] }}
      @if(!empty($mm['last_tested_at']))
        <span class="inline-hint">({{ $mm['last_tested_at'] }})</span>
      @endif
    </p>
  @endif

  <form method="post" action="/settings/messaging/messenger/channel" class="storage-provider-form" id="messenger-channel-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($mm['enabled']) || empty($mm['configured'])) />
      {{ __('有効にする') }}
    </label>
    <p class="hint">{{ __('保存するときは必ず「有効にする」にチェックを入れてください。Page Access Token は Messenger → トークンを生成 で出る長い文字列（通常 EAA で始まる）です。App Secret や Advanced の Client Token ではありません。') }}</p>
    <label>
      {{ __('Page Access Token') }}
      <input type="password" name="page_access_token" value="" autocomplete="new-password" placeholder="{{ !empty($mm['page_access_token_masked']) ? __('••••••••（変更するときだけ入力）') : 'EAA...' }}" />
    </label>
    <label>
      {{ __('App Secret') }}
      <input type="password" name="app_secret" value="" autocomplete="new-password" placeholder="{{ !empty($mm['app_secret_masked']) ? __('••••••••（変更するときだけ入力）') : '' }}" />
    </label>
    <label>
      {{ __('Webhook Verify Token') }}
      <input type="password" name="verify_token" value="" autocomplete="new-password" placeholder="{{ !empty($mm['verify_token_masked']) ? __('••••••••（変更するときだけ入力）') : __('任意の文字列') }}" />
    </label>
    <label>
      {{ __('App ID（任意）') }}
      <input type="text" name="app_id" value="{{ $mm['app_id'] ?? '' }}" autocomplete="off" />
    </label>
    <label>
      {{ __('ページ名（任意）') }}
      <input type="text" name="page_name" value="{{ $mm['page_name'] ?? '' }}" autocomplete="off" />
    </label>
    <p class="hint">{{ __('Webhook URL') }}: <code>{{ $mm['webhook_url'] ?? url('/webhooks/messenger') }}</code></p>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="messenger-channel-test-btn">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" id="messenger-channel-test-live"></span>
    </div>
  </form>

  <hr style="margin: 18px 0; border: 0; border-top: 1px solid #e5e7eb;" />
  <h3>{{ __('個人アカウント連携') }}</h3>
  <p class="hint">{{ __('ページの Messenger に、下で発行した6桁コードを送ってください。ToDo の通知方法で「Messenger」を選ぶとリマインダが届きます。') }}</p>

  @if(empty($mm['configured']) && empty($mm['ready']))
    <p class="hint storage-test-result is-fail">{{ __('先に上のチャネル設定を保存して有効にしてください。') }}</p>
  @elseif(empty($mm['connected']))
    <div class="storage-form-actions">
      <form method="post" action="/settings/messaging/messenger/code">
        @csrf
        <button type="submit" class="button-link">{{ __('連携コードを発行') }}</button>
      </form>
    </div>
  @else
    <dl class="photos-usage-result-dl" style="margin: 0 0 12px;">
      <div>
        <dt>{{ __('状態') }}</dt>
        <dd>{{ __('連携済み') }}</dd>
      </div>
      @if(!empty($mm['linkedAt']))
        <div>
          <dt>{{ __('連携日時') }}</dt>
          <dd>{{ $mm['linkedAt'] }}</dd>
        </div>
      @endif
    </dl>
    <div class="storage-form-actions" style="display:flex;flex-wrap:wrap;gap:8px;">
      <form method="post" action="/settings/messaging/messenger/test">
        @csrf
        <button type="submit" class="button-link">{{ __('テスト通知を送る') }}</button>
      </form>
      <form method="post" action="/settings/messaging/messenger/code">
        @csrf
        <button type="submit" class="button-link secondary">{{ __('コードを再発行') }}</button>
      </form>
      <form method="post" action="/settings/messaging/messenger/disconnect" onsubmit="return confirm(@json(__('Messenger連携を解除しますか？')))">
        @csrf
        <button type="submit" class="button-link secondary">{{ __('連携解除') }}</button>
      </form>
    </div>
  @endif
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const testBtn = document.getElementById('messenger-channel-test-btn');
    const testLive = document.getElementById('messenger-channel-test-live');
    const strings = {
      testing: @json(__('テスト中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };
    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      if (testLive) testLive.textContent = strings.testing;
      try {
        const res = await fetch('/settings/messaging/messenger/channel/test', {
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
