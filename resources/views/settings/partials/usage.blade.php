@php
  $usage = $integrationUsage ?? [];
  $storage = $storageStats ?? null;
@endphp
<div class="panel" id="settings-usage">
  <h2>{{ __('使用量') }}</h2>
  <p class="hint">{{ __('外部連携のアプリ内利用回数と、ストレージの使用状況をまとめて確認できます。請求額・残高の詳細は各公式コンソールも参照してください。') }}</p>
</div>

@if(!empty($usage))
  <section class="panel" id="integration-usage">
    <h2>{{ __('外部連携の使用量') }}</h2>
    <p class="hint">{{ __('本日・今月はアプリ側で成功を記録できた回数です。残高・請求額は各サービスの公式コンソールで確認してください。') }}</p>
    <div class="usage-overview-grid">
      @foreach($usage as $provider => $row)
        <article class="usage-overview-card">
          <strong>{{ match($provider) {
            'llm' => __('LLM（入出金音声入力）'),
            'youtube' => __('YouTube検索（Data API）'),
            'travelpayouts' => __('Travelpayouts（航空運賃）'),
            'stability' => __('Stability AI'),
            'realesrgan' => __('Real-ESRGAN（ローカル GPU）'),
            'swinir' => __('SwinIR（GPU VPS）'),
            'line' => __('LINE連携設定'),
            'facebook' => __('Facebook Messenger 通知連携'),
            'livekit' => __('LiveKit 通話（試作）'),
            'web_push' => __('Web Push 着信通知'),
            'web_push_subscriptions' => __('Web Push 登録端末'),
            'google_calendar' => __('Googleカレンダー'),
            default => $provider,
          } }}</strong>
          <span>{{ $row['metric'] }}</span>
          @if($provider === 'web_push_subscriptions')
            <b>{{ __('登録 :count 台', ['count' => number_format($row['today'])]) }}</b>
          @else
            <b>{{ __('本日 :count / 今月 :month', ['count' => number_format($row['today']), 'month' => number_format($row['month'])]) }}</b>
          @endif
          @if(!empty($row['external']))
            <a href="{{ $row['external'] }}" target="_blank" rel="noopener noreferrer">{{ __('公式コンソールを開く') }}</a>
          @endif
        </article>
      @endforeach
    </div>
  </section>
@endif

@if(!empty($storage))
  <section class="panel" id="storage-usage">
    <h2>{{ __('ストレージ使用状況') }}</h2>
    <p class="hint">
      {{ __('無料枠') }}: {{ $storage['formattedCombinedQuota'] ?? '' }}
      @if(!empty($storage['archiveEnabled']))
        （{{ __('内訳目安') }} R2 {{ $storage['formattedQuota'] ?? '' }} + B2 {{ $storage['formattedB2Quota'] ?? '' }}）
      @endif
      · {{ __('現在の使用') }} {{ $storage['formattedTotalUsed'] ?? ($storage['formattedUsed'] ?? '') }}
      · {{ __('超過課金見込') }} {{ $storage['estimatedTotalBillLabel'] ?? '$0/月' }}
      @if(($storage['billingMode'] ?? '') === 'estimate')
        · {{ __('現在は見込表示のみ（実課金は今後）') }}
      @endif
    </p>
    <div class="usage-overview-grid">
      @foreach(($storage['providers'] ?? []) as $provider)
        <article class="usage-overview-card{{ empty($provider['enabled']) ? ' is-off' : '' }}{{ !empty($provider['overFreeTier']) ? ' is-over' : '' }}">
          <strong>{{ $provider['name'] }}</strong>
          <span>{{ $provider['role'] }}</span>
          @if(empty($provider['enabled']))
            <b>{{ __('現在このパイプラインでは未使用') }}</b>
          @else
            <b>
              {{ ($provider['meter'] ?? '') === 'credits' ? __('クレジット残高') : __('使用量') }}:
              {{ $provider['usedLabel'] }}
              / {{ $provider['quotaLabel'] }}
            </b>
            <span>{{ __('見込') }}: {{ $provider['estimatedBillLabel'] ?? '$0/月' }}</span>
            @if(!empty($provider['billingNote']))
              <span>{{ $provider['billingNote'] }}</span>
            @endif
          @endif
        </article>
      @endforeach
    </div>
    <div class="storage-form-actions" style="margin-top:12px;">
      <a class="button-link secondary" href="/settings?section=storage">{{ __('ストレージ設定を開く') }}</a>
      <a class="button-link secondary" href="/photos">{{ __('Photos を開く') }}</a>
    </div>
  </section>
@endif
