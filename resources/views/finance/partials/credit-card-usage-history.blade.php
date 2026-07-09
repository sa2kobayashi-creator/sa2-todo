@if(($account['kind'] ?? null) === 'credit_card' && (!empty($account['usageConfigured']) || !empty($account['usageHistory'])))
  <div class="finance-card-usage-history @if(!empty($listMode)) is-list @endif" onclick="event.stopPropagation()">
    @if(!empty($account['usageConfigured']))
      <p class="finance-card-usage-configured">
        設定残高
        <strong>{{ $formatMoney($account['usageConfigured']['total'], $account['usageConfigured']['currency']) }}</strong>
        <span class="finance-card-usage-configured-detail">
          （開始残高 {{ $formatMoney($account['usageConfigured']['initialBalance'], $account['currency']) }}
          / 調整 {{ $formatMoney($account['usageConfigured']['adjustmentAmount'], $account['currency']) }}）
        </span>
      </p>
      <p class="hint finance-card-usage-configured-hint">取引ではなく口座設定の値です。不要なら「編集」または「口座設定」で 0 にしてください。</p>
    @endif
    @if(!empty($account['usageHistory']))
      <p class="finance-card-usage-title">請求内訳</p>
      <ul class="finance-card-usage-list">
        @foreach($account['usageHistory'] as $item)
          <li class="finance-card-usage-item">
            <span class="finance-card-usage-label">{{ $item['label'] }}</span>
            <span class="finance-card-usage-meta">
              <span class="finance-card-usage-date">{{ $item['displayDate'] }}</span>
              <span class="finance-card-usage-amount">{{ $formatMoney($item['amount'], $item['currency']) }}</span>
            </span>
          </li>
        @endforeach
      </ul>
    @elseif(empty($account['usageConfigured']))
      <p class="hint finance-card-usage-empty">請求はまだありません。支出取引を追加するとここに表示されます。</p>
    @endif
  </div>
@endif
