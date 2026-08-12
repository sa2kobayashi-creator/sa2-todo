@php
  $lm = $lineMessaging ?? [
    'enabled' => false,
    'ready' => false,
    'configured' => false,
    'connected' => false,
    'displayName' => null,
    'linkedAt' => null,
    'qr_code_url' => null,
  ];
  $lineActive = !empty($lm['ready']);
  $lineBase = $lineMessagingActionBase ?? '/mypage/messaging/line';
@endphp
<div class="panel storage-settings" id="line-messaging">
  <h2>{{ __('LINE連携') }}</h2>
  <p class="hint">{{ __('公式アカウントを友だち追加し、下で発行した6桁コードをトークに送ってください。ToDo の通知方法で「LINE」を選ぶとリマインダが届きます。') }}</p>

  @if(!empty($lm['qr_code_url']))
    <div class="line-side-qr" style="margin-bottom:12px;">
      <img src="{{ $lm['qr_code_url'] }}" alt="{{ __('LINE QRコード') }}" style="max-width:160px;height:auto;" />
    </div>
  @endif

  @if(! $lineActive)
    <p class="hint storage-test-result is-fail">{{ __('まだチャネルが有効ではありません。管理者に LINE チャネル設定を依頼してください。') }}</p>
  @elseif(empty($lm['connected']))
    <div class="storage-form-actions">
      <form method="post" action="{{ $lineBase }}/code">
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
      <form method="post" action="{{ $lineBase }}/test">
        @csrf
        <button type="submit" class="button-link">{{ __('テスト通知を送る') }}</button>
      </form>
      <form method="post" action="{{ $lineBase }}/code">
        @csrf
        <button type="submit" class="button-link secondary">{{ __('コードを再発行') }}</button>
      </form>
      <form method="post" action="{{ $lineBase }}/disconnect" onsubmit="return confirm(@json(__('LINE連携を解除しますか？')))">
        @csrf
        <button type="submit" class="button-link secondary">{{ __('連携解除') }}</button>
      </form>
    </div>
  @endif
</div>
