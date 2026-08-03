@php
  $lm = $lineMessaging ?? [
    'enabled' => false,
    'ready' => false,
    'configured' => false,
    'connected' => false,
    'has_secrets' => false,
    'channel_access_token_masked' => '',
    'channel_secret_masked' => '',
    'bot_basic_id' => '',
    'webhook_url' => url('/webhooks/line'),
    'qr_code_url' => null,
    'qr_code_filename' => '',
    'displayName' => null,
    'linkedAt' => null,
  ];
  $lineActive = !empty($lm['ready']);
@endphp
<div class="panel storage-settings line-settings-panel" id="line-messaging">
  <h2>{{ __('LINE連携設定') }}</h2>

  <div class="line-settings-layout">
    <div class="line-settings-main">
      @if($lineActive)
        <div class="line-status-banner is-on" role="status">
          <span class="line-status-icon" aria-hidden="true">✓</span>
          <span>{{ __('有効: LINE連携が有効になっています') }}</span>
        </div>
      @else
        <div class="line-status-banner is-off" role="status">
          <span>{{ __('無効: チャネル情報を保存して有効にしてください') }}</span>
        </div>
      @endif

      @if(!empty($lm['last_test_message']))
        <p class="hint storage-test-result {{ ($lm['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
          {{ $lm['last_test_message'] }}
          @if(!empty($lm['last_tested_at']))
            <span class="inline-hint">({{ $lm['last_tested_at'] }})</span>
          @endif
        </p>
      @endif

      <form method="post" action="/settings/messaging/line/channel" class="storage-provider-form line-channel-form" id="line-channel-form" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="enabled" value="1" />

        <label>
          {{ __('Channel Secret') }} <span class="req">*</span>
          <input type="password" name="channel_secret" value="{{ $lm['channel_secret_masked'] ?? '' }}" autocomplete="off" @if(empty($lm['has_secrets'])) required @endif />
        </label>
        <label>
          {{ __('Channel Access Token') }} <span class="req">*</span>
          <input type="password" name="channel_access_token" value="{{ $lm['channel_access_token_masked'] ?? '' }}" autocomplete="off" @if(empty($lm['has_secrets'])) required @endif />
        </label>
        <label>
          {{ __('Bot Basic ID（任意）') }}
          <input type="text" name="bot_basic_id" value="{{ $lm['bot_basic_id'] ?? '' }}" placeholder="@xxxx" autocomplete="off" />
        </label>
        <label>
          {{ __('Webhook URL') }}
          <input type="text" readonly value="{{ $lm['webhook_url'] ?? url('/webhooks/line') }}" onclick="this.select()" />
        </label>
        <p class="hint line-webhook-note">
          {{ __('実際に LINE から呼ばれる URL は APP_URL に基づく :url です（受信処理はこちらを使用）。LINE Developers の Webhook に同じ URL を設定してください。', ['url' => $lm['webhook_url'] ?? url('/webhooks/line')]) }}
        </p>

        <div class="line-qr-field">
          <div class="line-qr-label">{{ __('QRコード画像') }}</div>
          @if(!empty($lm['qr_code_url']))
            <div class="line-qr-preview">
              <img src="{{ $lm['qr_code_url'] }}" alt="{{ __('LINE QRコード') }}" />
              <div class="line-qr-meta">
                <span>{{ $lm['qr_code_filename'] }}</span>
                <button type="submit" form="line-qr-delete-form" class="line-qr-delete" onclick="return confirm(@json(__('QRコード画像を削除しますか？')))">{{ __('削除') }}</button>
              </div>
            </div>
          @endif
          <input type="file" name="qr_code" accept="image/jpeg,image/png,image/gif,.jpg,.jpeg,.png,.gif" />
          <p class="hint">{{ __('LINE公式アカウントの友だち追加用 QR をアップロード（JPEG / PNG / GIF、最大2MB）') }}</p>
        </div>

        <div class="storage-form-actions line-settings-actions">
          <button type="submit" class="button-link">{{ __('設定を保存') }}</button>
          @if(!empty($lm['enabled']))
            <button type="submit" form="line-disable-form" class="button-link secondary">{{ __('無効化') }}</button>
          @endif
          <button type="button" class="secondary" id="line-channel-test-btn">{{ __('接続テスト') }}</button>
          <span class="storage-test-live hint" id="line-channel-test-live"></span>
        </div>
      </form>

      <form id="line-disable-form" method="post" action="/settings/messaging/line/disable" class="is-hidden-form">@csrf</form>
      <form id="line-qr-delete-form" method="post" action="/settings/messaging/line/qr/delete" class="is-hidden-form">@csrf</form>

      <div class="line-personal-link">
        <h3>{{ __('個人アカウント連携') }}</h3>
        <p class="hint">{{ __('公式アカウントを友だち追加し、下で発行した6桁コードをトークに送ってください。ToDo の通知方法で「LINE」を選ぶとリマインダが届きます。') }}</p>

        @if(! $lineActive)
          <p class="hint storage-test-result is-fail">{{ __('先に上のチャネル設定を保存して有効にしてください。') }}</p>
        @elseif(empty($lm['connected']))
          <div class="storage-form-actions">
            <form method="post" action="/settings/messaging/line/code">
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
            @if(!empty($lm['linkedAt']))
              <div>
                <dt>{{ __('連携日時') }}</dt>
                <dd>{{ $lm['linkedAt'] }}</dd>
              </div>
            @endif
          </dl>
          <div class="storage-form-actions" style="display:flex;flex-wrap:wrap;gap:8px;">
            <form method="post" action="/settings/messaging/line/test">
              @csrf
              <button type="submit" class="button-link">{{ __('テスト通知を送る') }}</button>
            </form>
            <form method="post" action="/settings/messaging/line/code">
              @csrf
              <button type="submit" class="button-link secondary">{{ __('コードを再発行') }}</button>
            </form>
            <form method="post" action="/settings/messaging/line/disconnect" onsubmit="return confirm(@json(__('LINE連携を解除しますか？')))">
              @csrf
              <button type="submit" class="button-link secondary">{{ __('連携解除') }}</button>
            </form>
          </div>
        @endif
      </div>
    </div>

    <aside class="line-settings-side">
      <h3>{{ __('設定手順') }}</h3>
      <ol class="line-setup-steps">
        <li>{{ __('LINE Developers Console で Messaging API チャネルを作成します。') }}</li>
        <li>{{ __('Channel Secret と Channel Access Token を取得し、左のフォームに入力します。') }}</li>
        <li>{{ __('表示されている Webhook URL を LINE Developers の Webhook に設定し、Webhook をオンにします。') }}</li>
        <li>{{ __('友だち追加用 QR をアップロードし、ユーザーに公式アカウントを追加してもらいます。連携コードで個人アカウントを接続します。') }}</li>
      </ol>

      <div class="line-notify-examples">
        <h4>{{ __('通知例') }}</h4>
        <ul>
          <li>{{ __('ToDo リマインダ（当日9時 / 30分前 など）') }}</li>
          <li>{{ __('設定画面からのテスト通知') }}</li>
          <li>{{ __('連携完了・コード再発行時の案内') }}</li>
        </ul>
      </div>

      @if(!empty($lm['qr_code_url']))
        <div class="line-side-qr">
          <h4>{{ __('友だち追加 QR') }}</h4>
          <img src="{{ $lm['qr_code_url'] }}" alt="{{ __('LINE QRコード') }}" />
        </div>
      @endif
    </aside>
  </div>
</div>

<script>
  (() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const testBtn = document.getElementById('line-channel-test-btn');
    const testLive = document.getElementById('line-channel-test-live');
    const strings = {
      testing: @json(__('テスト中...')),
      networkError: @json(__('通信エラーが発生しました')),
    };
    testBtn?.addEventListener('click', async () => {
      testBtn.disabled = true;
      if (testLive) testLive.textContent = strings.testing;
      try {
        const res = await fetch('/settings/messaging/line/channel/test', {
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
