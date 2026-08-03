@php
  $lm = $lineMessaging ?? ['configured' => false, 'connected' => false];
  $mm = $messengerMessaging ?? ['configured' => false, 'connected' => false];
@endphp
<div class="panel" id="notification-channels">
  <h2>{{ __('通知設定') }}</h2>
  <p class="hint">{{ __('ToDo リマインダの配信先です。Web Push は次フェーズです。チャネル設定と個人連携は外部連携から行います。') }}</p>

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
      <dd>{{ __('次フェーズ') }}</dd>
    </div>
  </dl>

  <p class="hint">{{ __('配信は cron で php artisan todos:send-reminders を1分ごと（または5分ごと）に実行してください。') }}</p>
  <div class="storage-form-actions">
    <a class="button-link secondary" href="/settings?section=integration#line-messaging">{{ __('外部連携を開く') }}</a>
  </div>
</div>
