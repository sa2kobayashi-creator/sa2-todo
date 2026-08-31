{{-- マイページ「現在の利用状況」 --}}
@php
  $plan = $planSummary ?? [];
  $remaining = $usageRemaining ?? null;
  $aiUsage = $aiUsage ?? null;
  $showOpsAi = ! empty($aiUsage) && auth()->user()?->isAdmin();
@endphp

<details class="app-accordion mypage-accordion" id="usage-status" data-accordion-key="mypage-usage-status" open>
  <summary class="app-accordion-summary">
    <span>{{ __('現在の利用状況') }}</span>
    <span class="app-accordion-caret" aria-hidden="true">▾</span>
  </summary>
  <div class="app-accordion-body">
    <p class="hint">
      {{ __('契約状態と、このアカウントの使用量の目安です。') }}
      <a href="#billing-plan">{{ __('プラン・お支払い') }}</a>
    </p>

    @if(empty($plan['subscriptionActive']) && empty($plan['isStaff']))
      <div class="banner notice" style="margin-bottom:1rem;">
        <p style="margin:0 0 0.5rem;">{{ __('いまはライト（お試し・約:gbGB）です。容量とメニューを広げるにはスタンダードへお申し込みください。', ['gb' => max(1, (int) round(((int) config('photos.user_free_quota_bytes', 20 * 1024 * 1024 * 1024)) / (1024 * 1024 * 1024)))]) }}</p>
        <p class="hint" style="margin:0 0 0.5rem;">{{ __('約:warn日ログインがない場合は警告メールが届きます。警告後:grace日ログインがなければアカウントは削除されます。', ['warn' => (int) config('registration.light_inactive_warn_days', 90), 'grace' => (int) config('registration.light_inactive_delete_grace_days', 14)]) }}</p>
        <p class="hint" style="margin:0 0 0.75rem;">{{ __('最初の:days日間は無料。その後 ¥:yen／月（税込）。', ['days' => (int) ($plan['standardTrialDays'] ?? 14), 'yen' => number_format((int) ($plan['standardMonthlyYen'] ?? 980))]) }}</p>
        <a href="#billing-plan" class="button-link">{{ __('スタンダードに申し込む（¥:yen〜）', ['yen' => number_format((int) ($plan['standardMonthlyYen'] ?? 980))]) }}</a>
      </div>
    @endif

    <dl class="plan-summary-grid">
      <div class="plan-summary-card">
        <dt>{{ __('契約状態') }}</dt>
        <dd>
          {{ $plan['subscriptionStatusLabel'] ?? __('未契約') }}
          @if(!empty($plan['trialEndsAt']))
            <span class="hint" style="display:block;margin-top:6px;">{{ __('お試し期限') }}: {{ $plan['trialEndsAt'] }}</span>
          @endif
          @if(!empty($remaining['plan']))
            <span class="hint" style="display:block;margin-top:6px;">
              {{ __('利用枠プラン') }}: {{ __(\App\Models\UsageLimitPolicy::accountPlanLabel((string) $remaining['plan'])) }}
              @if(!empty($remaining['pool']))
                · {{ __('回数・文字数は契約メンバー合算です') }}
              @endif
            </span>
          @endif
        </dd>
      </div>
      @if(!empty($plan['canPhotos']))
        <div class="plan-summary-card">
          <dt>{{ __('写真の容量') }}</dt>
          <dd>
            {{ $plan['storageUsedLabel'] ?? '—' }} / {{ $plan['storageQuotaLabel'] ?? '—' }}
            @if(!empty($plan['storageUploadsBlocked']))
              <span class="hint" style="display:block;margin-top:6px;">{{ __('無料枠を超えているため、追加アップロードは停止中です。') }}</span>
            @elseif(!empty($plan['storageOverageActive']))
              <span class="hint" style="display:block;margin-top:6px;">{{ __('有料超過が許可されています。') }}</span>
            @endif
            <a href="/photos" style="display:inline-block;margin-top:8px;">{{ __('Photos を開く') }}</a>
            <a href="/archives" style="display:inline-block;margin-top:8px;margin-left:12px;">{{ __('過去データの長期保存') }}</a>
          </dd>
        </div>
      @endif
      @if(!empty($plan['canMail']))
        <div class="plan-summary-card">
          <dt>{{ __('メールボックス') }}（&#64;{{ $plan['mailboxDomain'] ?? 'sa2-plus.com' }}）</dt>
          <dd>
            @if(!empty($plan['mailboxIncludedInPlan']))
              {{ __('スタンダード契約に含まれています') }}
              <span class="hint" style="display:block;margin-top:6px;">{{ __('アドレス作成とパスワード設定は運営者が手動です。パスワードは別途お知らせします。') }}</span>
            @elseif(!empty($plan['mailboxAddonActive']))
              {{ __('有料オプション: 有効') }}
            @else
              {{ __('ライトプランでは個別契約です') }}
              <span class="hint" style="display:block;margin-top:6px;">
                {{ __('月額 :price 円（目安）。申請はメール画面から行えます。', ['price' => number_format((int) ($plan['mailboxAddonPriceMonthly'] ?? 300))]) }}
              </span>
            @endif
            <a href="/mail?tab=domain" style="display:inline-block;margin-top:8px;">{{ __('メール申請を開く') }}</a>
          </dd>
        </div>
      @endif
    </dl>

    @if(!empty($remaining['meters']))
      <h3 class="mypage-usage-subtitle">{{ __('このアカウントの枠') }}</h3>
      @include('partials.usage-limit-warnings', ['warnings' => $remaining['warnings'] ?? []])
      <div class="usage-overview-grid mypage-usage-meters">
        @foreach(\App\Services\UserUsageLimitService::featureShortLabels() as $meter => $label)
          @php $m = $remaining['meters'][$meter] ?? null; @endphp
          @if(!$m)
            @continue
          @endif
          <article class="usage-overview-card{{ !empty($m['warn_level']) ? ' is-'.$m['warn_level'] : '' }}">
            <strong>{{ $label }}</strong>
            <span>{{ __('本日') }} {{ number_format((int) $m['used_today']) }}@if((int) $m['daily_limit'] > 0) / {{ number_format((int) $m['daily_limit']) }}@else / {{ __('上限なし') }}@endif</span>
            <span>{{ __('今月') }} {{ number_format((int) $m['used_month']) }}@if((int) $m['monthly_limit'] > 0) / {{ number_format((int) $m['monthly_limit']) }}@else / {{ __('上限なし') }}@endif</span>
            @if(!empty($m['warn_message']))
              <p class="usage-overview-warn is-{{ $m['warn_level'] }}">{{ $m['warn_message'] }}</p>
            @endif
          </article>
        @endforeach
      </div>
    @endif

    @if($showOpsAi)
      <details class="app-accordion mypage-accordion mypage-ai-usage-accordion" data-accordion-key="mypage-ai-usage">
        <summary class="app-accordion-summary">
          <span>{{ __('AI使用料・翻訳使用料') }}</span>
          <span class="app-accordion-caret" aria-hidden="true">▾</span>
        </summary>
        <div class="app-accordion-body">
          <p class="hint">{{ __('無料枠の残りと、見込まれる使用料の目安です。詳細は設定画面で確認・更新できます。') }}</p>
          <div class="dash-ai-usage-grid">
            @foreach(['ai' => $aiUsage['ai'] ?? [], 'translation' => $aiUsage['translation'] ?? []] as $kind => $card)
              @if($kind === 'ai' && ! auth()->user()?->isSuperAdmin())
                @continue
              @endif
              @if(($card['title'] ?? '') === '')
                @continue
              @endif
              @php
                $enabled = ! empty($card['enabled']);
                $warn = ! empty($card['warn']);
                $percent = $card['percent'] ?? null;
              @endphp
              <article class="dash-ai-usage-card{{ $enabled ? '' : ' is-off' }}{{ $warn ? ' is-warn' : '' }}">
                <header class="dash-ai-usage-card-head">
                  <div>
                    <h3>{{ $card['title'] ?? '—' }}</h3>
                    <p class="dash-ai-usage-provider">{{ $card['provider_label'] ?? '' }} · {{ $card['status_label'] ?? '' }}</p>
                  </div>
                  <a class="button-link secondary dash-ai-usage-link" href="{{ $card['settings_url'] ?? '/settings' }}">{{ __('設定') }}</a>
                </header>
                <p class="dash-ai-usage-remaining">{{ $card['remaining_label'] ?? '—' }}</p>
                <dl class="dash-ai-usage-dl">
                  <div>
                    <dt>{{ __('使用量') }}</dt>
                    <dd>{{ $card['usage_label'] ?? '—' }}</dd>
                  </div>
                  <div>
                    <dt>{{ __('料金目安') }}</dt>
                    <dd>{{ $card['cost_label'] ?? '—' }}</dd>
                  </div>
                  @if(! empty($card['fetched_at']))
                    <div>
                      <dt>{{ __('最終更新') }}</dt>
                      <dd>{{ $card['fetched_at'] }}</dd>
                    </div>
                  @endif
                </dl>
                @if($percent !== null)
                  <div class="dash-ai-usage-bar" role="progressbar" aria-valuenow="{{ min(100, (float) $percent) }}" aria-valuemin="0" aria-valuemax="100">
                    <span class="dash-ai-usage-bar-fill{{ $percent >= 80 ? ' is-danger' : ($percent >= 50 ? ' is-warn' : '') }}" style="width: {{ min(100, (float) $percent) }}%"></span>
                  </div>
                  <p class="dash-ai-usage-rate">{{ __('使用率') }} {{ number_format((float) $percent, 1) }}%</p>
                @endif
                @if($kind === 'translation' && ! empty($card['keys']) && count($card['keys']) > 1)
                  <ul class="dash-ai-usage-keys">
                    @foreach($card['keys'] as $keyCard)
                      <li>
                        <strong>{{ $keyCard['name'] }}</strong>
                        <span class="dash-ai-usage-badge{{ ! empty($keyCard['is_paid']) ? ' is-paid' : '' }}">
                          {{ ! empty($keyCard['is_paid']) ? __('有料') : __('無料') }}
                        </span>
                        @if(($keyCard['remaining'] ?? null) !== null)
                          · {{ __('あと :n 文字', ['n' => number_format((int) $keyCard['remaining'])]) }}
                        @elseif(! empty($keyCard['is_paid']) && ($keyCard['estimated_cost'] ?? null) !== null)
                          · €{{ rtrim(rtrim(number_format((float) $keyCard['estimated_cost'], 4, '.', ''), '0'), '.') }}
                        @endif
                      </li>
                    @endforeach
                  </ul>
                @endif
              </article>
            @endforeach
          </div>
        </div>
      </details>
    @endif
  </div>
</details>
