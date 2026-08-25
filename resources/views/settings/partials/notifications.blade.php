@php
  $lm = $lineMessaging ?? ['configured' => false, 'connected' => false];
  $mm = $messengerMessaging ?? ['configured' => false, 'connected' => false];
@endphp
<div class="panel" id="notification-channels">
  <h2>{{ __('通知設定') }}</h2>
  <p class="hint">{{ __('通話の着信を、アプリを閉じているときにも端末通知で受け取れます。VAPID サーバー設定は外部連携から行います。スタンダード／ライトは設定メニューがないため、マイページから端末を登録します。') }}</p>

  <dl class="photos-usage-result-dl" style="margin: 0 0 12px;">
    <div>
      <dt>{{ __('LINE') }}</dt>
      <dd>
        @if(empty($lm['configured']))
          {{ __('チャネル未設定') }}
        @elseif(!empty($lm['connected']))
          {{ __('連携済み') }}
        @else
          {{ __('未連携') }}
        @endif
      </dd>
    </div>
    <div>
      <dt>{{ __('Messenger') }}</dt>
      <dd>
        @if(empty($mm['configured']))
          {{ __('チャネル未設定') }}
        @elseif(!empty($mm['connected']))
          {{ __('連携済み') }}
        @else
          {{ __('未連携') }}
        @endif
      </dd>
    </div>
    <div>
      <dt>{{ __('Web Push') }}</dt>
      <dd>{{ !empty($pushConfigured) ? __('利用可能') : __('管理者のVAPID設定待ち') }}</dd>
    </div>
  </dl>

  <div class="storage-form-actions">
    <button type="button" class="button-link secondary" id="push-subscribe-btn" @disabled(empty($pushConfigured))>{{ __('通話の着信通知を登録') }}</button>
    <span class="hint" id="push-status">
      {{ !empty($pushConfigured) ? __('この端末で通知を許可してください。') : __('通知サーバーが未設定です。') }}
    </span>
  </div>
  <p class="hint">{{ __('Android TWA とWeb版の両方で利用できます。端末の通知許可をオンにしてください。') }}</p>

  <p class="hint">{{ __('配信は cron で php artisan todos:send-reminders を1分ごと（または5分ごと）に実行してください。') }}</p>
  <div class="storage-form-actions">
    <a class="button-link secondary" href="/settings?section=integration#web-push">{{ __('外部連携の Web Push 設定') }}</a>
  </div>
</div>
@if(!empty($pushConfigured))
  <script src="{{ asset('push-client.js') }}?v={{ @filemtime(public_path('push-client.js')) ?: time() }}" defer></script>
@endif
