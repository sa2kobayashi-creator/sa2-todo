@php
  $pushReady = !empty($pushConfigured);
@endphp
<div class="panel" id="web-push-subscribe">
  <h2>{{ __('メッセージと通話の通知') }}</h2>
  <p class="hint">{{ __('アプリを閉じているときでも、新着メッセージと通話の着信をこの端末の通知で受け取れます。使うブラウザ・端末ごとに登録してください。') }}</p>
  <div class="storage-form-actions line-code-issue-row">
    <button type="button" class="button-link secondary" id="push-subscribe-btn" @disabled(! $pushReady)>{{ __('この端末の通知を登録') }}</button>
    <span class="hint" id="push-status">
      {{ $pushReady ? __('この端末で通知を許可してください。') : __('通知サーバーが未設定です。管理者に Web Push の設定を依頼してください。') }}
    </span>
  </div>
  <p class="hint">{{ __('HTTPS の Chrome などで利用できます。iPhone はホーム画面に追加したあとでないと使えないことがあります。') }}</p>
</div>
@if($pushReady)
  <script src="{{ asset('push-client.js') }}?v={{ @filemtime(public_path('push-client.js')) ?: time() }}" defer></script>
@endif
