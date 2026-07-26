<section class="dash-ai-usage" aria-label="{{ __('AI使用状況') }}">
  <div class="dash-ai-usage-head">
    <h2 class="dash-ai-usage-title">{{ __('AI使用料・翻訳使用料') }}</h2>
    <p class="dash-ai-usage-lead">{{ __('無料枠の残りと、見込まれる使用料の目安です。詳細は設定画面で確認・更新できます。') }}</p>
  </div>

  <div class="dash-ai-usage-grid">
    @foreach(['ai' => $aiUsage['ai'] ?? [], 'translation' => $aiUsage['translation'] ?? []] as $kind => $card)
      @php
        $enabled = !empty($card['enabled']);
        $warn = !empty($card['warn']);
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
          @if(!empty($card['fetched_at']))
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

        @if(!empty($card['llm_note']))
          <p class="dash-ai-usage-note">{{ $card['llm_note'] }}</p>
        @endif

        @if($kind === 'translation' && !empty($card['keys']) && count($card['keys']) > 1)
          <ul class="dash-ai-usage-keys">
            @foreach($card['keys'] as $keyCard)
              <li>
                <strong>{{ $keyCard['name'] }}</strong>
                <span class="dash-ai-usage-badge{{ !empty($keyCard['is_paid']) ? ' is-paid' : '' }}">
                  {{ !empty($keyCard['is_paid']) ? __('有料') : __('無料') }}
                </span>
                @if(($keyCard['remaining'] ?? null) !== null)
                  · {{ __('あと :n 文字', ['n' => number_format((int) $keyCard['remaining'])]) }}
                @elseif(!empty($keyCard['is_paid']) && ($keyCard['estimated_cost'] ?? null) !== null)
                  · €{{ rtrim(rtrim(number_format((float) $keyCard['estimated_cost'], 4, '.', ''), '0'), '.') }}
                @endif
              </li>
            @endforeach
          </ul>
        @endif
      </article>
    @endforeach
  </div>
</section>
