<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <title>入出金経費レポート - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="finance-page finance-report-page">
    @include('partials.header', ['active' => 'finance'])
    <main class="page-main finance-main finance-report-main">
      <div class="finance-report-toolbar no-print">
        <div class="finance-top-bar finance-report-top-bar">
          <div class="finance-tabs" role="tablist" aria-label="レポート表示切替">
            @foreach(\App\Services\FinanceService::TAB_LABELS as $tabKey => $tabLabel)
              <a
                href="{{ $buildFinanceReportQuery(array_merge($filters, ['tab' => $tabKey])) }}"
                class="finance-tab @if($filters['tab'] === $tabKey) is-active @endif"
                role="tab"
                aria-selected="{{ $filters['tab'] === $tabKey ? 'true' : 'false' }}"
              >{{ $tabLabel }}</a>
            @endforeach
          </div>

          <form class="finance-period-form" method="get" action="/finance/report" id="finance-report-period-form">
            <input type="hidden" name="tab" value="{{ $filters['tab'] }}" />
            <label class="finance-period-label">
              表示月
              <input type="month" name="period" value="{{ $periodValue }}" />
            </label>
          </form>

          <div class="finance-report-actions">
            <a href="{{ $buildFinanceQuery($filters) }}" class="button-link secondary">管理画面へ</a>
            <button type="button" class="button-link" onclick="window.print()">印刷</button>
          </div>
        </div>
      </div>

      <article class="finance-report panel">
        <header class="finance-report-header">
          <h1 class="finance-report-title">入出金経費レポート</h1>
          <p class="finance-report-meta">
            <span>{{ $monthLabel }}</span>
            <span class="finance-report-meta-sep">·</span>
            <span>{{ \App\Services\FinanceService::TAB_LABELS[$filters['tab']] ?? $filters['tab'] }}</span>
          </p>
        </header>

        <section class="finance-report-summary">
          <h2 class="finance-section-title">月次サマリー</h2>
          <div class="finance-report-summary-grid">
            <div class="finance-report-stat">
              <span class="finance-report-stat-label">収入</span>
              <span class="finance-report-stat-value is-income">{{ $formatMoney($summary['income'], $summary['currency']) }}</span>
            </div>
            <div class="finance-report-stat">
              <span class="finance-report-stat-label">支出</span>
              <span class="finance-report-stat-value is-expense">{{ $formatMoney($summary['expense'], $summary['currency']) }}</span>
            </div>
            <div class="finance-report-stat">
              <span class="finance-report-stat-label">収支</span>
              <span class="finance-report-stat-value @if($summary['net'] >= 0) is-income @else is-expense @endif">{{ $formatMoney($summary['net'], $summary['currency']) }}</span>
            </div>
            @if($filters['tab'] !== 'transfer')
              <div class="finance-report-stat">
                <span class="finance-report-stat-label">振替出</span>
                <span class="finance-report-stat-value">{{ $formatMoney($summary['transferOut'], $summary['currency']) }}</span>
              </div>
              <div class="finance-report-stat">
                <span class="finance-report-stat-label">振替入</span>
                <span class="finance-report-stat-value">{{ $formatMoney($summary['transferIn'], $summary['currency']) }}</span>
              </div>
            @endif
          </div>
        </section>

        @if($accountBreakdown !== [])
          <section class="finance-report-section">
            <h2 class="finance-section-title">口座別集計</h2>
            <div class="finance-report-table-wrap">
              <table class="finance-report-table">
                <thead>
                  <tr>
                    <th>口座</th>
                    <th class="is-num">収入</th>
                    <th class="is-num">支出</th>
                    <th class="is-num">収支</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($accountBreakdown as $row)
                    <tr>
                      <td>{{ $row['accountName'] }}</td>
                      <td class="is-num is-income">{{ $formatMoney($row['income'], $row['currency']) }}</td>
                      <td class="is-num is-expense">{{ $formatMoney($row['expense'], $row['currency']) }}</td>
                      <td class="is-num @if($row['net'] >= 0) is-income @else is-expense @endif">{{ $formatMoney($row['net'], $row['currency']) }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </section>
        @endif

        @foreach([
          'income' => '収入',
          'expense' => '支出',
          'transfer' => '振替',
        ] as $typeKey => $typeLabel)
          @if(!empty($groupedTransactions[$typeKey]))
            <section class="finance-report-section">
              <h2 class="finance-section-title">{{ $typeLabel }}一覧（{{ count($groupedTransactions[$typeKey]) }}件）</h2>
              <div class="finance-report-table-wrap">
                <table class="finance-report-table">
                  <thead>
                    <tr>
                      <th>日付</th>
                      <th>口座</th>
                      @if($typeKey === 'transfer')
                        <th>振替先</th>
                      @endif
                      <th class="is-num">金額</th>
                      <th>メモ</th>
                    </tr>
                  </thead>
                  <tbody>
                    @foreach($groupedTransactions[$typeKey] as $transaction)
                      <tr>
                        <td>{{ $transaction['transactionDate'] }}</td>
                        <td>{{ $transaction['accountName'] }}</td>
                        @if($typeKey === 'transfer')
                          <td>{{ $transaction['toAccountName'] }}</td>
                        @endif
                        <td class="is-num @if($typeKey === 'income') is-income @elseif($typeKey === 'expense') is-expense @endif">
                          @if($typeKey === 'transfer' && $transaction['toAmount'] !== null && $transaction['toCurrency'] !== $transaction['currency'])
                            {{ $formatMoney($transaction['amount'], $transaction['currency']) }}
                            →
                            {{ $formatMoney($transaction['toAmount'], $transaction['toCurrency'] ?? $transaction['currency']) }}
                          @else
                            {{ $formatMoney($transaction['amount'], $transaction['currency']) }}
                          @endif
                        </td>
                        <td>{{ $transaction['displayMemo'] ?? $transaction['memo'] }}</td>
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
            </section>
          @endif
        @endforeach

        @if($schedules !== [])
          <section class="finance-report-section">
            <h2 class="finance-section-title">予定（{{ count($schedules) }}件）</h2>
            <div class="finance-report-table-wrap">
              <table class="finance-report-table">
                <thead>
                  <tr>
                    <th>予定日</th>
                    <th>種別</th>
                    <th>口座</th>
                    <th class="is-num">金額</th>
                    <th>メモ</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($schedules as $schedule)
                    <tr>
                      <td>{{ $schedule['scheduledDate'] }}</td>
                      <td>{{ $schedule['typeLabel'] }}</td>
                      <td>{{ $schedule['accountName'] }}</td>
                      <td class="is-num">{{ $formatMoney($schedule['amount'], $schedule['currency']) }}</td>
                      <td>{{ $schedule['memo'] }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </section>
        @endif

        <section class="finance-report-section finance-report-balances">
          <h2 class="finance-section-title">現在の口座残高（参考）</h2>
          <div class="finance-report-table-wrap">
            <table class="finance-report-table">
              <thead>
                <tr>
                  <th>口座</th>
                  <th>種別</th>
                  <th class="is-num">残高</th>
                </tr>
              </thead>
              <tbody>
                @foreach($accounts as $account)
                  <tr>
                    <td>{{ $account['name'] }}</td>
                    <td>{{ $account['kindLabel'] }}</td>
                    <td class="is-num">
                      {{ $formatMoney($account['balance'], $account['currency']) }}
                      @if(($account['balanceLabel'] ?? '残高') !== '残高')
                        <span class="finance-report-balance-kind">（{{ $account['balanceLabel'] }}）</span>
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div class="finance-report-balance-totals">
            @foreach($balanceTotals['totals'] as $currency => $total)
              <span>{{ $currency }} 総残高: {{ $formatMoney($total, $currency) }}</span>
            @endforeach
            @foreach($balanceTotals['creditCards'] ?? [] as $currency => $amount)
              <span>{{ $currency }} クレカ利用額: {{ $formatMoney($amount, $currency) }}</span>
            @endforeach
          </div>
        </section>
      </article>
    </main>

    <script>
      document.getElementById('finance-report-period-form')?.querySelector('input[name="period"]')?.addEventListener('change', function () {
        this.form.submit();
      });
    </script>
  </body>
</html>
