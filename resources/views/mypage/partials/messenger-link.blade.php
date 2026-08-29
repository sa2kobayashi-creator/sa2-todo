@php
  $mm = $messengerMessaging ?? [
    'enabled' => false,
    'ready' => false,
    'configured' => false,
    'connected' => false,
    'displayName' => null,
    'linkedAt' => null,
  ];
  $messengerReady = !empty($mm['ready']) || !empty($mm['configured']);
  $messengerBase = $messengerMessagingActionBase ?? '/mypage/messaging/messenger';
  $messengerEmbed = ! empty($mypageEmbed);
@endphp
@if($messengerEmbed)
<div class="mypage-embed-body">
@else
<div class="panel storage-settings" id="facebook-messenger">
  <h2>{{ __('Messenger連携') }}</h2>
@endif
  <p class="hint">{{ __('ページの Messenger に、下で発行した6桁コードを送ってください。ToDo の通知方法で「Messenger」を選ぶとリマインダが届きます。') }}</p>

  @if(! $messengerReady)
    <p class="hint storage-test-result is-fail">{{ __('まだチャネルが有効ではありません。管理者に Messenger チャネル設定を依頼してください。') }}</p>
  @elseif(empty($mm['connected']))
    <div class="storage-form-actions line-code-issue-row">
      <form method="post" action="{{ $messengerBase }}/code">
        @csrf
        <button type="submit" class="button-link">{{ __('連携コードを発行') }}</button>
      </form>
      @include('mypage.partials.link-code-issued', ['expectedProvider' => 'messenger'])
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
    <div class="storage-form-actions line-code-issue-row" style="display:flex;flex-wrap:wrap;gap:8px;">
      <form method="post" action="{{ $messengerBase }}/test">
        @csrf
        <button type="submit" class="button-link">{{ __('テスト通知を送る') }}</button>
      </form>
      <form method="post" action="{{ $messengerBase }}/code">
        @csrf
        <button type="submit" class="button-link secondary">{{ __('コードを再発行') }}</button>
      </form>
      @include('mypage.partials.link-code-issued', ['expectedProvider' => 'messenger'])
      <form method="post" action="{{ $messengerBase }}/disconnect" onsubmit="return confirm(@json(__('Messenger連携を解除しますか？')))">
        @csrf
        <button type="submit" class="button-link secondary">{{ __('連携解除') }}</button>
      </form>
    </div>
  @endif
</div>
