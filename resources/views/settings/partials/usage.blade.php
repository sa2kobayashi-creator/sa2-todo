@php
  $usage = $integrationUsage ?? [];
  $usageGroups = $usage['groups'] ?? [];
  $storage = $storageStats ?? null;
@endphp
<div class="panel" id="settings-usage">
  <h2>{{ __('使用量') }}</h2>
  <p class="hint">{{ __('外部連携のアプリ内利用回数と、ストレージの使用状況をまとめて確認できます。請求額・残高の詳細は各公式コンソールも参照してください。') }}</p>
  @if(!empty($isSuperAdmin))
    <p><a class="button-link secondary" href="/settings?section=limits">{{ __('制限を編集') }}</a></p>
  @endif
</div>

@php $remaining = $usageRemaining ?? null; @endphp
@if(!empty($remaining['meters']))
  <section class="panel" id="plan-usage-remaining">
    <h2>{{ __('このアカウントの枠') }}</h2>
    <p class="hint">
      {{ __('プラン') }}: {{ __(\App\Models\UsageLimitPolicy::accountPlanLabel((string) ($remaining['plan'] ?? ''))) }}
      @if(!empty($remaining['pool']))
        · {{ __('回数・文字数は契約メンバー合算です') }}
      @endif
    </p>
    <div class="usage-overview-grid">
      @foreach(['translate' => __('翻訳'), 'llm_voice' => __('音声AI'), 'workers_ai' => __('生活ガイド')] as $meter => $label)
        @php $m = $remaining['meters'][$meter] ?? null; @endphp
        @if(!$m)
          @continue
        @endif
        <article class="usage-overview-card">
          <strong>{{ $label }}</strong>
          <span>{{ __('本日') }} {{ number_format((int) $m['used_today']) }}@if((int) $m['daily_limit'] > 0) / {{ number_format((int) $m['daily_limit']) }}@else / {{ __('上限なし') }}@endif</span>
          <span>{{ __('今月') }} {{ number_format((int) $m['used_month']) }}@if((int) $m['monthly_limit'] > 0) / {{ number_format((int) $m['monthly_limit']) }}@else / {{ __('上限なし') }}@endif</span>
        </article>
      @endforeach
    </div>
  </section>
@endif

@php $official = $officialAiUsage ?? []; @endphp
<section class="panel" id="official-ai-usage">
  <div class="deepl-usage-panel-head">
    <div>
      <h2>{{ __('公式APIの使用量') }}</h2>
      <p class="hint">{{ __('DeepL は文字数 API、ChatGPT は管理キーがあればトークン、Workers AI は Analytics 権限があればトークン／Neurons を取得します。Gemini の API キーでは使用量を取れません。') }}</p>
    </div>
    @if(!empty($isAdmin))
      <form method="post" action="/settings/ai/usage/refresh" class="inline-form">
        @csrf
        <button type="submit" class="secondary">{{ __('公式使用量を更新') }}</button>
      </form>
    @endif
  </div>
  <div class="usage-overview-grid">
    @foreach(['deepl', 'openai', 'gemini', 'workers_ai'] as $usageKey)
      @php $card = $official[$usageKey] ?? null; @endphp
      @if(!$card)
        @continue
      @endif
      <article class="usage-overview-card{{ empty($card['ok']) ? ' is-off' : '' }}">
        <strong>{{ $card['label'] ?? $usageKey }}</strong>
        @if(!empty($card['message']))
          <p class="usage-overview-desc">{{ $card['message'] }}</p>
        @endif
        @if(($card['id'] ?? '') === 'deepl')
          @forelse(($card['keys'] ?? []) as $deeplKey)
            <span>{{ $deeplKey['name'] }} · {{ !empty($deeplKey['is_paid']) ? __('有料') : __('無料') }}</span>
            <b>
              {{ number_format((int) ($deeplKey['character_count'] ?? 0)) }}
              @if(($deeplKey['character_limit'] ?? null) !== null)
                / {{ number_format((int) $deeplKey['character_limit']) }}
              @endif
              {{ __('文字') }}
            </b>
            @if(($deeplKey['usage_rate'] ?? null) !== null)
              <span>{{ __('使用率') }} {{ number_format((float) $deeplKey['usage_rate'], 1) }}%</span>
            @endif
            @if(!empty($deeplKey['is_paid']) && ($deeplKey['estimated_cost'] ?? null) !== null)
              <span>{{ __('推定合計') }}: €{{ number_format((float) $deeplKey['estimated_cost'], 2) }}</span>
            @endif
            <span>{{ __('取得:') }} {{ $deeplKey['fetched_at'] ?? __('未取得') }}</span>
          @empty
            <b>{{ __('未設定') }}</b>
          @endforelse
        @else
          @if(!empty($card['ok']) && ($card['total_tokens'] ?? null) !== null)
            <b>{{ __('公式（今月）') }}: {{ number_format((int) $card['total_tokens']) }} {{ __('トークン') }}</b>
            <span>
              {{ __('入力') }} {{ number_format((int) ($card['input_tokens'] ?? 0)) }}
              / {{ __('出力') }} {{ number_format((int) ($card['output_tokens'] ?? 0)) }}
            </span>
            @if(($card['requests'] ?? null) !== null)
              <span>{{ __('リクエスト') }}: {{ number_format((int) $card['requests']) }}</span>
            @endif
            @if(($card['neurons'] ?? null) !== null)
              <span>Neurons: {{ number_format((int) $card['neurons']) }}</span>
            @endif
          @endif
          @if(!empty($card['observed']) && (((int) ($card['observed']['today'] ?? 0)) > 0 || ((int) ($card['observed']['month'] ?? 0)) > 0))
            <span>{{ __('アプリが観測したトークン') }}: {{ __('本日 :count / 今月 :month', ['count' => number_format((int) $card['observed']['today']), 'month' => number_format((int) $card['observed']['month'])]) }}</span>
          @endif
          @if(!empty($card['fetched_at']))
            <span>{{ __('取得:') }} {{ $card['fetched_at'] }}</span>
          @endif
        @endif
        @if(!empty($card['external']))
          <a href="{{ $card['external'] }}" target="_blank" rel="noopener noreferrer">{{ __('公式コンソールを開く') }}</a>
        @endif
      </article>
    @endforeach
  </div>
</section>

@if(!empty($usageGroups))
  <section class="panel" id="integration-usage">
    <h2>{{ __('外部連携の使用量') }}</h2>
    <p class="hint">{{ __('本日・今月はアプリ側で成功を記録できた回数です。残高・請求額は各サービスの公式コンソールで確認してください。') }}</p>
    @foreach($usageGroups as $group)
      @php
        $usageItems = $group['items'] ?? [];
        if (empty($canSuperAdmin)) {
          unset($usageItems['enhance'], $usageItems['facebook'], $usageItems['travelpayouts']);
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
