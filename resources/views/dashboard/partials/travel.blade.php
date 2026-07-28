@if(!empty($travelSummary) && !empty($canTravel))
  @php
    $ar = $travelSummary['annualReport'] ?? [];
    $rp = $travelSummary['rp'] ?? [];
    $next = $travelSummary['nextDeadline'] ?? null;
    $unread = (int) ($travelSummary['unreadAlerts'] ?? 0);
    $unreadPromo = (int) ($travelSummary['unreadPromoAlerts'] ?? 0);
    $promoAlerts = $travelSummary['promoAlerts'] ?? [];
    $dashReturn = $returnTo ?? '/dashboard';
  @endphp
  <section class="dash-travel" aria-label="{{ __('Travel期限') }}">
    <div class="dash-travel-head">
      <div>
        <h2 class="dash-travel-title">{{ __('Travel') }}</h2>
        <p class="dash-travel-lead">{{ __('RP・Annual Report の期限と、セール／プロモ監視アラートです。') }}</p>
      </div>
      <a class="button-link secondary" href="/travel">{{ __('Travelへ') }}</a>
    </div>

    <div class="dash-travel-grid">
      <article class="dash-travel-card{{ !empty($ar['danger']) ? ' is-danger' : (!empty($ar['warn']) ? ' is-warn' : '') }}">
        <h3>{{ $ar['label'] ?? 'Annual Report' }}</h3>
        <p class="dash-travel-date">{{ $ar['deadline'] ?? '—' }}</p>
        <p class="dash-travel-days">
          @if(($ar['daysLeft'] ?? null) === null)
            —
          @elseif(($ar['daysLeft'] ?? 0) < 0)
            {{ __('期限超過 :n 日', ['n' => abs((int) $ar['daysLeft'])]) }}
          @else
            {{ __('あと :n 日', ['n' => (int) $ar['daysLeft']]) }}
          @endif
        </p>
      </article>

      <article class="dash-travel-card{{ !empty($rp['danger']) ? ' is-danger' : (!empty($rp['warn']) ? ' is-warn' : '') }}">
        <h3>{{ __('RP') }}</h3>
        <p class="dash-travel-date">{{ $rp['deadline'] ?? __('未設定') }}</p>
        <p class="dash-travel-days">
          @if(empty($rp['deadline']))
            {{ __('未設定') }}
          @elseif(($rp['daysLeft'] ?? 0) < 0)
            {{ __('期限超過 :n 日', ['n' => abs((int) $rp['daysLeft'])]) }}
          @else
            {{ __('あと :n 日', ['n' => (int) $rp['daysLeft']]) }}
          @endif
        </p>
      </article>
    </div>

    <div class="dash-travel-meta">
      @if($next)
        <span>
          {{ __('次') }}:
          {{ $next['label'] ?? '' }}
          （{{ $next['deadline'] ?? '' }}）
        </span>
      @endif
      @if($unread > 0)
        <span class="dash-travel-alert-count">{{ __('未読アラート :n', ['n' => $unread]) }}</span>
      @endif
      @if($unreadPromo > 0)
        <span class="dash-travel-alert-count">{{ __('未読プロモ :n', ['n' => $unreadPromo]) }}</span>
      @endif
      @if(($travelSummary['activePromos'] ?? 0) > 0)
        <span>{{ __('監視中プロモ :n', ['n' => (int) $travelSummary['activePromos']]) }}</span>
      @endif
    </div>

    <div class="dash-travel-promo">
      <div class="dash-travel-promo-head">
        <h3 class="dash-travel-promo-title">{{ __('セール／プロモ監視') }}</h3>
        <a class="button-link secondary" href="/travel">{{ __('詳細') }}</a>
      </div>

      @if(count($promoAlerts) === 0)
        <p class="hint">{{ __('プロモアラートはまだありません。Travel で「今すぐ取得」するか、定期取得を待ってください。') }}</p>
      @else
        <ul class="dash-travel-promo-list">
          @foreach($promoAlerts as $alert)
            <li class="dash-travel-promo-item is-{{ $alert['severity'] ?? 'info' }}{{ !empty($alert['isRead']) ? ' is-read' : '' }}">
              <div class="dash-travel-promo-body">
                <strong>{{ $alert['title'] }}</strong>
                @if(($alert['body'] ?? '') !== '')
                  <p>{{ $alert['body'] }}</p>
                @endif
                <span class="dash-travel-promo-time">{{ $alert['createdAt'] ?? '' }}</span>
              </div>
              @if(empty($alert['isRead']))
                <form method="post" action="/travel/alerts/{{ $alert['id'] }}/read">
                  @csrf
                  <input type="hidden" name="returnTo" value="{{ $dashReturn }}" />
                  <button type="submit" class="secondary">{{ __('既読') }}</button>
                </form>
              @else
                <span class="dash-travel-promo-read">{{ __('既読済') }}</span>
              @endif
            </li>
          @endforeach
        </ul>
      @endif
    </div>
  </section>
@endif
