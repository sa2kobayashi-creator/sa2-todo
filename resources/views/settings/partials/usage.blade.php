@php
  $usage = $integrationUsage ?? [];
  $usageGroups = $usage['groups'] ?? [];
  $storage = $storageStats ?? null;
@endphp
<div class="panel" id="settings-usage">
  <h2>{{ __('使用量') }}</h2>
  <p class="hint">{{ __('外部連携のアプリ内利用回数と、ストレージの使用状況をまとめて確認できます。請求額・残高の詳細は各公式コンソールも参照してください。') }}</p>
</div>

@if(!empty($usageGroups))
  <section class="panel" id="integration-usage">
    <h2>{{ __('外部連携の使用量') }}</h2>
    <p class="hint">{{ __('本日・今月はアプリ側で成功を記録できた回数です。残高・請求額は各サービスの公式コンソールで確認してください。') }}</p>
    @foreach($usageGroups as $group)
      @php
        $usageItems = $group['items'] ?? [];
        if (empty($canSuperAdmin)) {
          unset($usageItems['enhance'], $usageItems['facebook']);
        }
      @endphp
      @if($usageItems === [])
        @continue
      @endif
      <div class="usage-purpose-group">
        <h3>{{ $group['label'] }}</h3>
        <div class="usage-overview-grid">
          @foreach($usageItems as $provider => $row)
            <article class="usage-overview-card">
              <strong>{{ $row['label'] ?? $provider }}</strong>
              @if(!empty($row['description']))
                <p class="usage-overview-desc">{{ $row['description'] }}</p>
              @endif
              @if(!empty($row['freeTier']))
                <span class="usage-overview-free">{{ __('無料枠') }}: {{ $row['freeTier'] }}</span>
              @endif
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
      </div>
    @endforeach
  </section>
@endif

@if(!empty($storage))
  <section class="panel" id="storage-usage">
    <h2>{{ __('ストレージ使用状況') }}</h2>
    <p class="hint">
      {{ __('無料枠') }}:
      {{ __('Light :light / Standard :standard', [
        'light' => $storage['formattedLightQuota'] ?? '',
        'standard' => $storage['formattedStandardQuota'] ?? '',
      ]) }}
      （{{ __('このアカウント') }} {{ $storage['formattedCombinedQuota'] ?? '' }}）
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
        @if(empty($canSuperAdmin) && in_array(($provider['id'] ?? ''), ['stability', 'realesrgan', 'swinir'], true))
          @continue
        @endif
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
