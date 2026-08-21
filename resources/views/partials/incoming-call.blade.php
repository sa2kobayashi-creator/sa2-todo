{{-- ログイン中かつメッセージ機能ありなら、どの画面でも着信を検知する --}}
<dialog class="msg-dialog msg-call-incoming-dialog" id="message-call-incoming-dialog">
  <section class="msg-dialog-card msg-call-incoming-card" aria-labelledby="message-call-incoming-title">
    <p class="msg-kicker">{{ __('着信') }}</p>
    <h3 id="message-call-incoming-title">{{ __('通話の着信') }}</h3>
    <p class="msg-call-incoming-from" id="message-call-incoming-from"></p>
    <p class="msg-call-hint">{{ __('応答するとカメラとマイクが使われます。') }}</p>
    <div class="msg-call-incoming-actions">
      <button type="button" class="msg-call-decline" id="message-call-decline">{{ __('拒否') }}</button>
      <button type="button" class="msg-call-accept" id="message-call-accept">{{ __('応答') }}</button>
    </div>
  </section>
</dialog>
<audio id="message-call-ring" preload="auto" playsinline></audio>
<script src="{{ asset('messages-call.js') }}?v={{ @filemtime(public_path('messages-call.js')) ?: time() }}" defer></script>
