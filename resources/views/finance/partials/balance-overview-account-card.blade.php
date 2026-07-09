<article
  class="finance-balance-overview-item finance-balance-overview-account"
  data-account='@json($account)'
  tabindex="0"
  role="button"
  aria-label="{{ $account['name'] }} の残高 {{ $formatMoney($account['balance'], $account['currency']) }}"
>
  <form method="post" action="/finance/accounts/{{ $account['id'] }}/overview" class="finance-balance-overview-remove-form" onclick="event.stopPropagation()">
    @csrf
    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
    <input type="hidden" name="show" value="0" />
    <button type="submit" class="finance-balance-overview-remove" title="カードから外す" aria-label="{{ $account['name'] }} をカードから外す">×</button>
  </form>
  <span class="finance-kind-badge">{{ $account['kindLabel'] }}</span>
  <span class="finance-balance-overview-account-name">{{ $account['name'] }}</span>
  <strong class="finance-balance-overview-account-balance">{{ $formatMoney($account['balance'], $account['currency']) }}</strong>
  <span class="finance-balance-overview-account-label">{{ $account['balanceLabel'] ?? '残高' }}</span>
</article>
