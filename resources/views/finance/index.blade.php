<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>入出金経費 - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="finance-page">
    @include('partials.header', ['active' => 'finance'])
    <main class="page-main finance-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="finance-top-bar">
        <div class="finance-tabs" role="tablist" aria-label="表示切替">
          @foreach(\App\Services\FinanceService::TAB_LABELS as $tabKey => $tabLabel)
            <a
              href="{{ $buildFinanceQuery(array_merge($filters, ['tab' => $tabKey]), ['account' => null]) }}"
              class="finance-tab @if($filters['tab'] === $tabKey) is-active @endif"
              role="tab"
              aria-selected="{{ $filters['tab'] === $tabKey ? 'true' : 'false' }}"
            >{{ $tabLabel }}</a>
          @endforeach
        </div>

        <form class="finance-period-form" method="get" action="/finance" id="finance-period-form">
          <input type="hidden" name="tab" value="{{ $filters['tab'] }}" />
          @if($filters['accountId'])
            <input type="hidden" name="account" value="{{ $filters['accountId'] }}" />
          @endif
          <label class="finance-period-label">
            表示月
            <input type="month" name="period" value="{{ $periodValue }}" />
          </label>
        </form>

        <button type="button" class="button-link" id="finance-open-add">＋ 取引を追加</button>
        <a href="{{ $buildFinanceReportQuery($filters) }}" class="button-link secondary finance-report-link">レポート</a>
      </div>

      <section class="finance-balance-overview panel">
        <h2 class="finance-section-title">現在の総残高</h2>
        <div class="finance-balance-overview-grid">
          @forelse($balanceTotals['totals'] as $currency => $total)
            <div class="finance-balance-overview-item finance-balance-overview-main">
              <span class="finance-summary-label">総残高</span>
              <strong class="finance-balance-overview-total">{{ $formatMoney($total, $currency) }}</strong>
            </div>
          @empty
            <p class="hint">表示対象の口座がありません。</p>
          @endforelse
        </div>
        @if(!empty($balanceTotals['assets']) || !empty($balanceTotals['creditCards']))
          <div class="finance-balance-overview-breakdown">
            @foreach($balanceTotals['assets'] as $currency => $amount)
              <span>口座・現金 {{ $formatMoney($amount, $currency) }}</span>
            @endforeach
            @foreach($balanceTotals['creditCards'] as $currency => $amount)
              <span>クレカ {{ $formatMoney($amount, $currency) }}</span>
            @endforeach
          </div>
        @endif
        @if(!empty($balanceTotals['upcomingPayments']) || !empty($balanceTotals['upcomingDeposits']))
          <div class="finance-balance-overview-schedules">
            @foreach($balanceTotals['upcomingPayments'] as $currency => $amount)
              <span>次回支払予定合計 {{ $formatMoney($amount, $currency) }}</span>
            @endforeach
            @foreach($balanceTotals['upcomingDeposits'] as $currency => $amount)
              <span>次回入金予定合計 {{ $formatMoney($amount, $currency) }}</span>
            @endforeach
          </div>
        @endif
      </section>

      <section class="finance-summary panel">
        <h2 class="finance-section-title">{{ $monthLabel }} サマリー</h2>
        <p class="hint finance-summary-hint">各項目をクリックすると取引を追加できます。</p>
        <div class="finance-summary-grid">
          <button type="button" class="finance-summary-item finance-summary-clickable" data-summary-type="income">
            <span class="finance-summary-label">収入</span>
            <strong class="finance-summary-value income">{{ $formatMoney($summary['income'], $summary['currency']) }}</strong>
            <span class="finance-summary-action">＋ 追加</span>
          </button>
          <button type="button" class="finance-summary-item finance-summary-clickable" data-summary-type="expense">
            <span class="finance-summary-label">支出</span>
            <strong class="finance-summary-value expense">{{ $formatMoney($summary['expense'], $summary['currency']) }}</strong>
            <span class="finance-summary-action">＋ 追加</span>
          </button>
          <button type="button" class="finance-summary-item finance-summary-clickable" data-summary-type="expense">
            <span class="finance-summary-label">収支</span>
            <strong class="finance-summary-value">{{ $formatMoney($summary['net'], $summary['currency']) }}</strong>
            <span class="finance-summary-action">＋ 追加</span>
          </button>
          @if($filters['tab'] === 'transfer' || $filters['tab'] === 'all' || $filters['tab'] === 'jp' || $filters['tab'] === 'ph')
            <button type="button" class="finance-summary-item finance-summary-clickable" data-summary-type="transfer">
              <span class="finance-summary-label">振替出</span>
              <strong class="finance-summary-value">{{ $formatMoney($summary['transferOut'], $summary['currency']) }}</strong>
              <span class="finance-summary-action">＋ 追加</span>
            </button>
            <button type="button" class="finance-summary-item finance-summary-clickable" data-summary-type="transfer">
              <span class="finance-summary-label">振替入</span>
              <strong class="finance-summary-value">{{ $formatMoney($summary['transferIn'], $summary['currency']) }}</strong>
              <span class="finance-summary-action">＋ 追加</span>
            </button>
          @endif
        </div>
      </section>

      @if($filters['tab'] !== 'transfer')
        <section class="finance-accounts panel">
          <div class="finance-section-head">
            <h2 class="finance-section-title">口座残高</h2>
            <div class="finance-section-actions">
              <div class="finance-view-toggle" role="group" aria-label="口座表示切替">
                <button type="button" class="finance-view-toggle-btn is-active" data-accounts-view="cards" aria-pressed="true">カード</button>
                <button type="button" class="finance-view-toggle-btn" data-accounts-view="list" aria-pressed="false">リスト</button>
              </div>
              <button type="button" class="text-btn" id="finance-open-add-account">＋ 口座を追加</button>
              <button type="button" class="text-btn" id="finance-toggle-settings">口座設定</button>
            </div>
          </div>

          <p class="hint finance-drag-hint">カード表示では <span class="finance-drag-hint-icon" aria-hidden="true">⠿</span> をドラッグして並び替えできます。金額は <code>1000+340</code> のような式でも入力できます。</p>

          <div class="finance-accounts-view" id="finance-accounts-view" data-view="cards">
          @forelse($groupedAccounts as $kind => $kindAccounts)
            <div class="finance-account-group" data-kind="{{ $kind }}">
              <h3 class="finance-account-group-title">{{ \App\Services\FinanceService::KIND_LABELS[$kind] ?? $kind }}</h3>
              <div class="finance-account-cards" data-kind="{{ $kind }}">
                @foreach($kindAccounts as $account)
                  <div
                    class="finance-account-card @if($filters['accountId'] === $account['id']) is-selected @endif"
                    data-account-id="{{ $account['id'] }}"
                    data-account='@json($account)'
                  >
                    <button
                      type="button"
                      class="finance-account-drag-handle"
                      draggable="true"
                      aria-label="{{ $account['name'] }} の表示順を変更"
                      title="ドラッグして並び替え"
                    >⠿</button>
                    <div class="finance-account-card-body" tabindex="0" role="button" aria-label="{{ $account['name'] }} を編集">
                      <span class="finance-account-name">{{ $account['name'] }}</span>
                      <strong class="finance-account-balance">{{ $formatMoney($account['balance'], $account['currency']) }}</strong>
                      @if(($account['adjustmentAmount'] ?? 0) != 0)
                        <span class="finance-account-adjustment">調整 {{ $formatMoney($account['adjustmentAmount'], $account['currency']) }}</span>
                      @endif
                      @if(($account['scheduleType'] ?? null) === 'payment')
                        <form method="post" action="/finance/accounts/{{ $account['id'] }}/schedules/upsert" class="finance-card-schedule-form" onclick="event.stopPropagation()">
                          @csrf
                          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                          <label class="finance-card-schedule-field">
                            <span>支払予定日</span>
                            <input type="date" name="scheduledDate" value="{{ $account['nextSchedule']['scheduledDate'] ?? '' }}" required />
                          </label>
                          <label class="finance-card-schedule-field">
                            <span>支払額</span>
                            <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" value="{{ $account['nextSchedule']['amount'] ?? '' }}" required placeholder="1000+340" autocomplete="off" />
                          </label>
                          <div class="finance-card-schedule-actions">
                            <button type="submit" class="text-btn finance-card-schedule-save">保存</button>
                            @if(count($account['schedules'] ?? []) > 0)
                              <button type="button" class="text-btn finance-account-schedule-btn" data-schedule-account='@json($account)'>一覧</button>
                            @endif
                          </div>
                        </form>
                      @elseif(($account['scheduleType'] ?? null) === 'deposit')
                        <form method="post" action="/finance/accounts/{{ $account['id'] }}/schedules/upsert" class="finance-card-schedule-form" onclick="event.stopPropagation()">
                          @csrf
                          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                          <label class="finance-card-schedule-field">
                            <span>入金予定日</span>
                            <input type="date" name="scheduledDate" value="{{ $account['nextSchedule']['scheduledDate'] ?? '' }}" required />
                          </label>
                          <label class="finance-card-schedule-field">
                            <span>入金額</span>
                            <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" value="{{ $account['nextSchedule']['amount'] ?? '' }}" required placeholder="1000+340" autocomplete="off" />
                          </label>
                          <div class="finance-card-schedule-actions">
                            <button type="submit" class="text-btn finance-card-schedule-save">保存</button>
                            @if(count($account['schedules'] ?? []) > 0)
                              <button type="button" class="text-btn finance-account-schedule-btn" data-schedule-account='@json($account)'>一覧</button>
                            @endif
                          </div>
                        </form>
                      @endif
                      <a
                        href="{{ $buildFinanceQuery(array_merge($filters, ['accountId' => $account['id']])) }}"
                        class="finance-account-filter-link"
                        title="この口座の取引を表示"
                      >取引</a>
                    </div>
                  </div>
                @endforeach
              </div>
              <div class="finance-account-list" data-kind="{{ $kind }}">
                @foreach($kindAccounts as $account)
                  <div
                    class="finance-account-list-row @if($filters['accountId'] === $account['id']) is-selected @endif"
                    data-account-id="{{ $account['id'] }}"
                    data-account='@json($account)'
                    role="button"
                    tabindex="0"
                    aria-label="{{ $account['name'] }} を編集"
                  >
                    <span class="finance-account-list-name">{{ $account['name'] }}</span>
                    <span class="finance-kind-badge">{{ $account['kindLabel'] }}</span>
                    <strong class="finance-account-list-balance">{{ $formatMoney($account['balance'], $account['currency']) }}</strong>
                    @if(($account['adjustmentAmount'] ?? 0) != 0)
                      <span class="finance-adjustment-badge">調整 {{ $formatMoney($account['adjustmentAmount'], $account['currency']) }}</span>
                    @else
                      <span class="finance-adjustment-badge is-empty" aria-hidden="true"></span>
                    @endif
                    @if(($account['scheduleType'] ?? null) === 'payment')
                      <form method="post" action="/finance/accounts/{{ $account['id'] }}/schedules/upsert" class="finance-list-schedule-form" onclick="event.stopPropagation()">
                        @csrf
                        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                        <input type="date" name="scheduledDate" value="{{ $account['nextSchedule']['scheduledDate'] ?? '' }}" required aria-label="支払予定日" />
                        <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" value="{{ $account['nextSchedule']['amount'] ?? '' }}" required placeholder="支払額" aria-label="支払額" autocomplete="off" />
                        <button type="submit" class="text-btn">保存</button>
                      </form>
                    @elseif(($account['scheduleType'] ?? null) === 'deposit')
                      <form method="post" action="/finance/accounts/{{ $account['id'] }}/schedules/upsert" class="finance-list-schedule-form" onclick="event.stopPropagation()">
                        @csrf
                        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                        <input type="date" name="scheduledDate" value="{{ $account['nextSchedule']['scheduledDate'] ?? '' }}" required aria-label="入金予定日" />
                        <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" value="{{ $account['nextSchedule']['amount'] ?? '' }}" required placeholder="入金額" aria-label="入金額" autocomplete="off" />
                        <button type="submit" class="text-btn">保存</button>
                      </form>
                    @else
                      <span class="finance-list-schedule-empty" aria-hidden="true"></span>
                    @endif
                    <div class="finance-account-list-actions">
                      <a
                        href="{{ $buildFinanceQuery(array_merge($filters, ['accountId' => $account['id']])) }}"
                        class="text-btn finance-account-filter-link"
                        title="この口座の取引を表示"
                      >取引</a>
                      <button type="button" class="text-btn finance-edit-account-card-btn">編集</button>
                    </div>
                  </div>
                @endforeach
              </div>
            </div>
          @empty
            <p class="hint">表示する口座がありません。</p>
          @endforelse
          </div>

          @if($filters['accountId'])
            <p class="hint inline-hint">
              口座フィルタ中
              <a href="{{ $buildFinanceQuery(array_merge($filters, ['accountId' => null])) }}">解除</a>
            </p>
          @endif

          <div class="finance-account-settings" id="finance-account-settings" hidden>
            <h3 class="finance-account-group-title">口座・クレカの管理</h3>
            <p class="hint finance-settings-hint">口座名・種別の変更、残高調整、削除ができます。金額は <code>1000+340</code> のような式でも入力できます。</p>
            @foreach($accounts as $account)
              <details class="finance-account-setting-row" data-account='@json($account)'>
                <summary>
                  <span class="finance-account-setting-name">
                    [{{ $account['regionLabel'] }}] {{ $account['name'] }}
                    <span class="finance-kind-badge">{{ $account['kindLabel'] }}</span>
                  </span>
                  <span class="finance-account-setting-balance">{{ $formatMoney($account['balance'], $account['currency']) }}</span>
                  @if(($account['adjustmentAmount'] ?? 0) != 0)
                    <span class="finance-adjustment-badge">調整 {{ $formatMoney($account['adjustmentAmount'], $account['currency']) }}</span>
                  @endif
                </summary>
                <div class="finance-account-setting-actions">
                  <button type="button" class="text-btn finance-edit-account-btn">編集</button>
                  <form method="post" action="/finance/accounts/{{ $account['id'] }}/delete" class="finance-inline-form" onsubmit="return confirm('この口座を削除しますか？\n過去の取引は残りますが、一覧からは非表示になります。')">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <button type="submit" class="text-btn danger">削除</button>
                  </form>
                </div>
                <form method="post" action="/finance/accounts/{{ $account['id'] }}/balance" class="finance-inline-form">
                  @csrf
                  <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                  <label>
                    開始残高
                    <input type="text" inputmode="decimal" class="finance-amount-calc" name="initialBalance" value="{{ $account['initialBalance'] }}" autocomplete="off" />
                  </label>
                  <label>
                    調整金額
                    <input type="text" inputmode="decimal" class="finance-amount-calc" name="adjustmentAmount" value="{{ $account['adjustmentAmount'] ?? 0 }}" autocomplete="off" />
                  </label>
                  <button type="submit" class="button-link secondary">残高を保存</button>
                </form>
                @if($account['kind'] === 'credit_card')
                  <form method="post" action="/finance/accounts/{{ $account['id'] }}/linked-bank" class="finance-inline-form">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <label>
                      引落口座
                      <select name="linkedBankId">
                        <option value="">未設定</option>
                        @foreach($bankAccounts as $bank)
                          @if($bank['region'] === $account['region'])
                            <option value="{{ $bank['id'] }}" @selected($account['linkedBankId'] === $bank['id'])>{{ $bank['name'] }}</option>
                          @endif
                        @endforeach
                      </select>
                    </label>
                    <button type="submit" class="button-link secondary">引落口座を保存</button>
                  </form>
                @endif
                @if($account['scheduleType'] ?? null)
                  <div class="finance-account-schedule-panel">
                    <h4 class="finance-account-schedule-title">{{ $account['scheduleTypeLabel'] }}</h4>
                    @forelse($account['schedules'] as $schedule)
                      <div class="finance-account-schedule-item">
                        <span>{{ $schedule['scheduledDate'] }} {{ $formatMoney($schedule['amount'], $account['currency']) }}</span>
                        @if($schedule['memo'])
                          <span class="finance-account-schedule-memo">{{ $schedule['memo'] }}</span>
                        @endif
                        <form method="post" action="/finance/schedules/{{ $schedule['id'] }}/delete" class="finance-inline-form" onsubmit="return confirm('この予定を削除しますか？')">
                          @csrf
                          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                          <button type="submit" class="text-btn danger">削除</button>
                        </form>
                      </div>
                    @empty
                      <p class="hint">登録された予定はありません。</p>
                    @endforelse
                    <form method="post" action="/finance/accounts/{{ $account['id'] }}/schedules" class="finance-inline-form">
                      @csrf
                      <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                      <label>
                        予定日
                        <input type="date" name="scheduledDate" value="{{ $defaultDate }}" required />
                      </label>
                      <label>
                        金額
                        <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" required placeholder="1000+340" autocomplete="off" />
                      </label>
                      <label>
                        メモ
                        <input type="text" name="memo" placeholder="給与、カード引落 など" />
                      </label>
                      <button type="submit" class="button-link secondary">予定を追加</button>
                    </form>
                  </div>
                @endif
              </details>
            @endforeach
          </div>
        </section>
      @endif

      <section class="finance-transactions panel">
        <h2 class="finance-section-title">取引一覧</h2>
        @if(count($transactions) === 0)
          <p class="hint">この条件の取引はありません。「＋ 取引を追加」から登録できます。</p>
        @else
          <div class="finance-transaction-list">
            @foreach($transactions as $transaction)
              <article class="finance-transaction-row type-{{ $transaction['type'] }}" data-transaction='@json($transaction)'>
                <div class="finance-transaction-main">
                  <div class="finance-transaction-date">{{ $transaction['transactionDate'] }}</div>
                  <div class="finance-transaction-body">
                    <span class="finance-type-badge">{{ $transaction['typeLabel'] }}</span>
                    @if($transaction['type'] === 'transfer')
                      <span class="finance-transaction-desc">
                        {{ $transaction['accountName'] }}
                        →
                        {{ $transaction['toAccountName'] }}
                      </span>
                      <span class="finance-transaction-amount">
                        {{ $formatMoney($transaction['amount'], $transaction['currency']) }}
                        @if($transaction['toAmount'] !== null && ($transaction['toCurrency'] ?? '') !== $transaction['currency'])
                          / {{ $formatMoney($transaction['toAmount'], $transaction['toCurrency'] ?? $transaction['currency']) }}
                        @endif
                      </span>
                    @else
                      <span class="finance-transaction-desc">{{ $transaction['accountName'] }}</span>
                      <span class="finance-transaction-amount {{ $transaction['type'] }}">
                        {{ $transaction['type'] === 'expense' ? '−' : '+' }}{{ $formatMoney($transaction['amount'], $transaction['currency']) }}
                      </span>
                    @endif
                    @if($transaction['memo'])
                      <span class="finance-transaction-memo">{{ $transaction['memo'] }}</span>
                    @endif
                  </div>
                </div>
                <div class="finance-transaction-actions">
                  <button type="button" class="text-btn finance-edit-btn">編集</button>
                  <form method="post" action="/finance/{{ $transaction['id'] }}/delete" class="finance-inline-form" onsubmit="return confirm('この取引を削除しますか？')">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <button type="submit" class="text-btn danger">削除</button>
                  </form>
                </div>
              </article>
            @endforeach
          </div>
        @endif
      </section>
    </main>

    <div class="modal modal-centered" id="finance-transaction-modal" hidden>
      <div class="modal-backdrop" data-close-finance-modal></div>
      <div class="modal-dialog finance-modal-dialog" role="dialog" aria-labelledby="finance-modal-title">
        <div class="modal-header">
          <h2 id="finance-modal-title">取引を追加</h2>
          <button type="button" class="modal-close" data-close-finance-modal aria-label="閉じる">×</button>
        </div>
        <form method="post" action="/finance" id="finance-transaction-form" class="modal-form finance-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <input type="hidden" name="transaction_id" id="finance-transaction-id" value="" />

          <fieldset class="finance-type-fieldset">
            <legend>種別</legend>
            <label class="inline-check"><input type="radio" name="type" value="expense" checked /> 支出</label>
            <label class="inline-check"><input type="radio" name="type" value="income" /> 収入</label>
            <label class="inline-check"><input type="radio" name="type" value="transfer" /> 振替・送金</label>
          </fieldset>

          <label>
            日付
            <input type="date" name="transactionDate" id="finance-date" value="{{ $defaultDate }}" required />
          </label>

          <label>
            口座
            <select name="accountId" id="finance-account-id" required>
              @foreach($accounts as $account)
                <option value="{{ $account['id'] }}" data-region="{{ $account['region'] }}" data-currency="{{ $account['currency'] }}">
                  [{{ $account['regionLabel'] }}] {{ $account['name'] }}
                </option>
              @endforeach
            </select>
          </label>

          <div id="finance-transfer-fields" hidden>
            <label>
              入金口座
              <select name="toAccountId" id="finance-to-account-id">
                @foreach($accounts as $account)
                  <option value="{{ $account['id'] }}" data-region="{{ $account['region'] }}" data-currency="{{ $account['currency'] }}">
                    [{{ $account['regionLabel'] }}] {{ $account['name'] }}
                  </option>
                @endforeach
              </select>
            </label>
            <label id="finance-to-amount-wrap" hidden>
              入金側金額（異なる通貨の送金）
              <input type="text" inputmode="decimal" class="finance-amount-calc" name="toAmount" id="finance-to-amount" placeholder="1000+340" autocomplete="off" />
            </label>
          </div>

          <label>
            金額
            <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" id="finance-amount" required placeholder="1000+340" autocomplete="off" />
            <span class="hint finance-calc-hint">例: 1000+340 / 5000-200 / 100*1.1</span>
          </label>

          <label>
            メモ
            <input type="text" name="memo" id="finance-memo" placeholder="国保、PH送金 など" />
          </label>

          <div class="finance-form-actions">
            <button type="button" class="secondary" data-close-finance-modal>キャンセル</button>
            <button type="submit" class="button-link" id="finance-submit-btn">保存</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal modal-centered" id="finance-account-modal" hidden>
      <div class="modal-backdrop" data-close-finance-account-modal></div>
      <div class="modal-dialog finance-modal-dialog" role="dialog" aria-labelledby="finance-account-modal-title">
        <div class="modal-header">
          <h2 id="finance-account-modal-title">口座を追加</h2>
          <button type="button" class="modal-close" data-close-finance-account-modal aria-label="閉じる">×</button>
        </div>
        <form method="post" action="/finance/accounts" id="finance-account-form" class="modal-form finance-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <input type="hidden" name="account_id" id="finance-account-id-input" value="" />

          <label>
            口座名
            <input type="text" name="name" id="finance-account-name" required maxlength="100" placeholder="楽天銀行、Rakuten など" />
          </label>

          <label id="finance-account-region-wrap">
            地域
            <select name="region" id="finance-account-region" required>
              @foreach(\App\Services\FinanceService::REGION_LABELS as $regionKey => $regionLabel)
                <option value="{{ $regionKey }}" @selected($filters['tab'] === $regionKey)>{{ $regionLabel }}</option>
              @endforeach
            </select>
          </label>

          <label>
            種別
            <select name="kind" id="finance-account-kind" required>
              @foreach(\App\Services\FinanceService::KIND_LABELS as $kindKey => $kindLabel)
                <option value="{{ $kindKey }}">{{ $kindLabel }}</option>
              @endforeach
            </select>
          </label>

          <label>
            開始残高
            <input type="text" inputmode="decimal" class="finance-amount-calc" name="initialBalance" id="finance-account-initial-balance" value="0" autocomplete="off" />
          </label>

          <label>
            調整金額
            <input type="text" inputmode="decimal" class="finance-amount-calc" name="adjustmentAmount" id="finance-account-adjustment" value="0" autocomplete="off" />
          </label>

          <label id="finance-account-linked-bank-wrap" hidden>
            引落口座（クレカのみ）
            <select name="linkedBankId" id="finance-account-linked-bank">
              <option value="">未設定</option>
              @foreach($bankAccounts as $bank)
                <option value="{{ $bank['id'] }}" data-region="{{ $bank['region'] }}">{{ $bank['name'] }}（{{ $bank['regionLabel'] }}）</option>
              @endforeach
            </select>
          </label>

          <div class="finance-form-actions">
            <button type="button" class="secondary" data-close-finance-account-modal>キャンセル</button>
            <button type="submit" class="button-link" id="finance-account-submit-btn">保存</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal modal-centered" id="finance-schedule-modal" hidden>
      <div class="modal-backdrop" data-close-finance-schedule-modal></div>
      <div class="modal-dialog finance-modal-dialog" role="dialog" aria-labelledby="finance-schedule-modal-title">
        <div class="modal-header">
          <h2 id="finance-schedule-modal-title">予定</h2>
          <button type="button" class="modal-close" data-close-finance-schedule-modal aria-label="閉じる">×</button>
        </div>
        <div class="modal-form finance-form">
          <p class="finance-schedule-account-name" id="finance-schedule-account-name"></p>
          <div class="finance-account-schedule-panel">
            <h3 class="finance-account-schedule-title" id="finance-schedule-list-title">予定一覧</h3>
            <div id="finance-schedule-list" class="finance-schedule-list"></div>
          </div>
          <form method="post" action="/finance/accounts/0/schedules" id="finance-schedule-form" class="finance-inline-form finance-schedule-form">
            @csrf
            <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
            <label>
              予定日
              <input type="date" name="scheduledDate" id="finance-schedule-date" value="{{ $defaultDate }}" required />
            </label>
            <label>
              金額
              <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" id="finance-schedule-amount" required placeholder="1000+340" autocomplete="off" />
            </label>
            <label>
              メモ
              <input type="text" name="memo" id="finance-schedule-memo" placeholder="給与、カード引落 など" />
            </label>
            <div class="finance-form-actions">
              <button type="button" class="secondary" data-close-finance-schedule-modal>閉じる</button>
              <button type="submit" class="button-link">予定を追加</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
      (function () {
        function normalizeAmountExpression(raw) {
          return String(raw || '')
            .replace(/＝/g, '=')
            .replace(/×/g, '*')
            .replace(/÷/g, '/')
            .replace(/＋/g, '+')
            .replace(/－/g, '-')
            .replace(/,/g, '')
            .replace(/\s+/g, '')
            .trim()
        }

        function evaluateAmountExpression(raw) {
          let expr = normalizeAmountExpression(raw)
          if (!expr) return null
          const eqIndex = expr.indexOf('=')
          if (eqIndex >= 0) expr = expr.slice(0, eqIndex)
          if (!expr) return null
          if (!/^[-+*/().\d]+$/.test(expr)) return null
          if (/[*/]{2,}|\(\)|^[*/]|[*/]$/.test(expr)) return null
          try {
            const value = Function('"use strict"; return (' + expr + ')')()
            if (typeof value !== 'number' || !Number.isFinite(value)) return null
            return Math.round(value * 100) / 100
          } catch (_) {
            return null
          }
        }

        function formatAmountValue(value) {
          return Number.isInteger(value) ? String(value) : String(value)
        }

        function applyAmountCalc(input) {
          if (!input || input.disabled) return true
          const raw = String(input.value || '').trim()
          if (!raw) return true
          const result = evaluateAmountExpression(raw)
          if (result === null) {
            input.classList.add('is-invalid-calc')
            input.setCustomValidity('計算式が正しくありません（例: 1000+340）')
            return false
          }
          input.classList.remove('is-invalid-calc')
          input.setCustomValidity('')
          input.value = formatAmountValue(result)
          return true
        }

        function bindAmountCalcInputs(root = document) {
          root.querySelectorAll('.finance-amount-calc').forEach((input) => {
            if (input.dataset.calcBound === '1') return
            input.dataset.calcBound = '1'
            input.addEventListener('blur', () => applyAmountCalc(input))
            input.addEventListener('keydown', (event) => {
              if (event.key === 'Enter') applyAmountCalc(input)
            })
            const form = input.closest('form')
            if (form && form.dataset.calcSubmitBound !== '1') {
              form.dataset.calcSubmitBound = '1'
              form.addEventListener('submit', (event) => {
                let ok = true
                form.querySelectorAll('.finance-amount-calc').forEach((field) => {
                  if (!applyAmountCalc(field)) ok = false
                })
                if (!ok) {
                  event.preventDefault()
                  form.querySelector('.finance-amount-calc.is-invalid-calc')?.reportValidity()
                }
              })
            }
          })
        }

        bindAmountCalcInputs()

        const modal = document.getElementById('finance-transaction-modal')
        const form = document.getElementById('finance-transaction-form')
        const openBtn = document.getElementById('finance-open-add')
        const modalTitle = document.getElementById('finance-modal-title')
        const submitBtn = document.getElementById('finance-submit-btn')
        const transactionIdInput = document.getElementById('finance-transaction-id')
        const transferFields = document.getElementById('finance-transfer-fields')
        const toAmountWrap = document.getElementById('finance-to-amount-wrap')
        const accountSelect = document.getElementById('finance-account-id')
        const toAccountSelect = document.getElementById('finance-to-account-id')
        const periodForm = document.getElementById('finance-period-form')
        const settingsToggle = document.getElementById('finance-toggle-settings')
        const settingsPanel = document.getElementById('finance-account-settings')
        const typeRadios = form.querySelectorAll('input[name="type"]')
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content

        periodForm?.querySelector('input[type="month"]')?.addEventListener('change', () => periodForm.submit())

        settingsToggle?.addEventListener('click', () => {
          if (!settingsPanel) return
          const hidden = settingsPanel.hasAttribute('hidden')
          if (hidden) settingsPanel.removeAttribute('hidden')
          else settingsPanel.setAttribute('hidden', '')
        })

        function closeModal() {
          modal?.setAttribute('hidden', '')
        }

        function syncTransferVisibility() {
          const type = form.querySelector('input[name="type"]:checked')?.value || 'expense'
          const isTransfer = type === 'transfer'
          transferFields.hidden = !isTransfer
          toAccountSelect.required = isTransfer
          syncCrossCurrency()
        }

        function syncCrossCurrency() {
          const fromOption = accountSelect.selectedOptions[0]
          const toOption = toAccountSelect.selectedOptions[0]
          if (!fromOption || !toOption) return
          const cross = fromOption.dataset.currency !== toOption.dataset.currency
          toAmountWrap.hidden = !cross
        }

        typeRadios.forEach((radio) => radio.addEventListener('change', syncTransferVisibility))
        accountSelect?.addEventListener('change', syncCrossCurrency)
        toAccountSelect?.addEventListener('change', syncCrossCurrency)

        const defaultTransactionAccountId = @json(
          $filters['accountId']
            ?? ($filters['tab'] === 'jp' ? (collect($jpAccounts)->first()['id'] ?? null) : null)
            ?? ($filters['tab'] === 'ph' ? (collect($phAccounts)->first()['id'] ?? null) : null)
        )

        function applyDefaultTransactionAccount() {
          if (defaultTransactionAccountId) {
            accountSelect.value = String(defaultTransactionAccountId)
          }
        }

        function openAddModal(presetType = 'expense') {
          const allowedTypes = ['income', 'expense', 'transfer']
          const type = allowedTypes.includes(presetType) ? presetType : 'expense'
          modalTitle.textContent = '取引を追加'
          submitBtn.textContent = '保存'
          transactionIdInput.value = ''
          form.action = '/finance'
          form.method = 'post'
          form.querySelector('#finance-date').value = @json($defaultDate);
          form.querySelector('#finance-amount').value = ''
          form.querySelector('#finance-memo').value = ''
          form.querySelector('#finance-to-amount').value = ''
          const typeRadio = form.querySelector(`input[name="type"][value="${type}"]`)
          if (typeRadio) typeRadio.checked = true
          applyDefaultTransactionAccount()
          syncTransferVisibility()
          modal?.removeAttribute('hidden')
          window.setTimeout(() => form.querySelector('#finance-amount')?.focus(), 0)
        }

        function openEditModal(data) {
          modalTitle.textContent = '取引を編集'
          submitBtn.textContent = '更新'
          transactionIdInput.value = String(data.id)
          form.action = `/finance/${data.id}/update`
          form.querySelector('#finance-date').value = data.transactionDate
          form.querySelector('#finance-amount').value = data.amount
          form.querySelector('#finance-memo').value = data.memo || ''
          form.querySelector(`input[name="type"][value="${data.type}"]`).checked = true
          accountSelect.value = String(data.accountId)
          if (data.toAccountId) toAccountSelect.value = String(data.toAccountId)
          if (data.toAmount != null) form.querySelector('#finance-to-amount').value = data.toAmount
          syncTransferVisibility()
          modal?.removeAttribute('hidden')
        }

        openBtn?.addEventListener('click', () => openAddModal('expense'))
        document.querySelectorAll('[data-summary-type]').forEach((item) => {
          item.addEventListener('click', () => openAddModal(item.dataset.summaryType))
        })
        document.querySelectorAll('[data-close-finance-modal]').forEach((el) => {
          el.addEventListener('click', closeModal)
        })
        document.querySelectorAll('.finance-edit-btn').forEach((btn) => {
          btn.addEventListener('click', () => {
            const row = btn.closest('.finance-transaction-row')
            const data = JSON.parse(row.dataset.transaction)
            openEditModal(data)
          })
        })

        applyDefaultTransactionAccount()

        const accountModal = document.getElementById('finance-account-modal')
        const accountForm = document.getElementById('finance-account-form')
        const accountModalTitle = document.getElementById('finance-account-modal-title')
        const accountSubmitBtn = document.getElementById('finance-account-submit-btn')
        const accountIdInput = document.getElementById('finance-account-id-input')
        const accountRegionWrap = document.getElementById('finance-account-region-wrap')
        const accountRegionSelect = document.getElementById('finance-account-region')
        const accountKindSelect = document.getElementById('finance-account-kind')
        const accountLinkedBankWrap = document.getElementById('finance-account-linked-bank-wrap')
        const accountLinkedBankSelect = document.getElementById('finance-account-linked-bank')
        const openAccountBtn = document.getElementById('finance-open-add-account')
        const bankOptions = accountLinkedBankSelect ? Array.from(accountLinkedBankSelect.options) : []

        function closeAccountModal() {
          accountModal?.setAttribute('hidden', '')
        }

        function syncAccountLinkedBankVisibility() {
          const isCreditCard = accountKindSelect?.value === 'credit_card'
          if (accountLinkedBankWrap) accountLinkedBankWrap.hidden = !isCreditCard
          if (!isCreditCard && accountLinkedBankSelect) accountLinkedBankSelect.value = ''
        }

        function filterLinkedBankOptions(region) {
          if (!accountLinkedBankSelect) return
          bankOptions.forEach((option) => {
            if (!option.value) return
            option.hidden = option.dataset.region !== region
          })
        }

        function openAddAccountModal() {
          accountModalTitle.textContent = '口座を追加'
          accountSubmitBtn.textContent = '登録'
          accountIdInput.value = ''
          accountForm.action = '/finance/accounts'
          accountForm.querySelector('#finance-account-name').value = ''
          accountForm.querySelector('#finance-account-initial-balance').value = '0'
          accountForm.querySelector('#finance-account-adjustment').value = '0'
          if (accountRegionWrap) accountRegionWrap.hidden = false
          if (accountRegionSelect) {
            accountRegionSelect.disabled = false
            @if($filters['tab'] === 'ph')
              accountRegionSelect.value = 'ph'
            @else
              accountRegionSelect.value = 'jp'
            @endif
          }
          accountKindSelect.value = 'bank'
          if (accountLinkedBankSelect) accountLinkedBankSelect.value = ''
          filterLinkedBankOptions(accountRegionSelect?.value || 'jp')
          syncAccountLinkedBankVisibility()
          accountModal?.removeAttribute('hidden')
        }

        function openEditAccountModal(data) {
          accountModalTitle.textContent = '口座を編集'
          accountSubmitBtn.textContent = '更新'
          accountIdInput.value = String(data.id)
          accountForm.action = `/finance/accounts/${data.id}/update`
          accountForm.querySelector('#finance-account-name').value = data.name || ''
          accountForm.querySelector('#finance-account-initial-balance').value = data.initialBalance ?? 0
          accountForm.querySelector('#finance-account-adjustment').value = data.adjustmentAmount ?? 0
          if (accountRegionSelect) {
            accountRegionSelect.value = data.region || 'jp'
            accountRegionSelect.disabled = true
          }
          if (accountRegionWrap) accountRegionWrap.hidden = false
          accountKindSelect.value = data.kind || 'bank'
          filterLinkedBankOptions(data.region || 'jp')
          if (accountLinkedBankSelect) {
            accountLinkedBankSelect.value = data.linkedBankId ? String(data.linkedBankId) : ''
          }
          syncAccountLinkedBankVisibility()
          accountModal?.removeAttribute('hidden')
        }

        openAccountBtn?.addEventListener('click', openAddAccountModal)
        accountKindSelect?.addEventListener('change', syncAccountLinkedBankVisibility)
        accountRegionSelect?.addEventListener('change', () => {
          filterLinkedBankOptions(accountRegionSelect.value)
          if (accountLinkedBankSelect) accountLinkedBankSelect.value = ''
        })
        document.querySelectorAll('[data-close-finance-account-modal]').forEach((el) => {
          el.addEventListener('click', closeAccountModal)
        })
        document.querySelectorAll('.finance-edit-account-btn').forEach((btn) => {
          btn.addEventListener('click', () => {
            const row = btn.closest('.finance-account-setting-row')
            const data = JSON.parse(row.dataset.account)
            openEditAccountModal(data)
          })
        })

        const accountsView = document.getElementById('finance-accounts-view')
        const viewToggleButtons = document.querySelectorAll('[data-accounts-view]')
        const ACCOUNTS_VIEW_KEY = 'finance-accounts-view'

        function setAccountsView(view) {
          if (!accountsView) return
          const mode = view === 'list' ? 'list' : 'cards'
          accountsView.dataset.view = mode
          viewToggleButtons.forEach((btn) => {
            const active = btn.dataset.accountsView === mode
            btn.classList.toggle('is-active', active)
            btn.setAttribute('aria-pressed', active ? 'true' : 'false')
          })
          try {
            localStorage.setItem(ACCOUNTS_VIEW_KEY, mode)
          } catch (_) {}
        }

        viewToggleButtons.forEach((btn) => {
          btn.addEventListener('click', () => setAccountsView(btn.dataset.accountsView))
        })

        try {
          const savedView = localStorage.getItem(ACCOUNTS_VIEW_KEY)
          if (savedView === 'list' || savedView === 'cards') setAccountsView(savedView)
        } catch (_) {}

        function openAccountEditorFromElement(el) {
          if (!el?.dataset?.account) return
          openEditAccountModal(JSON.parse(el.dataset.account))
        }

        accountsView?.addEventListener('click', (event) => {
          if (event.target.closest('.finance-account-filter-link')) return
          if (event.target.closest('.finance-account-drag-handle')) return
          if (event.target.closest('.finance-account-schedule-btn')) return
          if (event.target.closest('.finance-card-schedule-form')) return
          if (event.target.closest('.finance-list-schedule-form')) return
          const editBtn = event.target.closest('.finance-edit-account-card-btn')
          if (editBtn) {
            openAccountEditorFromElement(editBtn.closest('[data-account]'))
            return
          }
          const item = event.target.closest('.finance-account-card-body, .finance-account-list-row')
          if (item) {
            openAccountEditorFromElement(item.closest('[data-account]') || item)
          }
        })

        accountsView?.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' && event.key !== ' ') return
          const item = event.target.closest('.finance-account-card-body, .finance-account-list-row')
          if (!item) return
          event.preventDefault()
          openAccountEditorFromElement(item.closest('[data-account]') || item)
        })

        let draggedCard = null

        function getDragAfterCard(container, x) {
          const cards = [...container.querySelectorAll('.finance-account-card:not(.is-dragging)')]
          return cards.reduce((closest, child) => {
            const box = child.getBoundingClientRect()
            const offset = x - box.left - box.width / 2
            if (offset < 0 && offset > closest.offset) {
              return { offset, element: child }
            }
            return closest
          }, { offset: Number.NEGATIVE_INFINITY }).element
        }

        function syncListOrder(cardsContainer) {
          const group = cardsContainer.closest('.finance-account-group')
          const list = group?.querySelector('.finance-account-list')
          if (!list) return
          const order = new Map()
          cardsContainer.querySelectorAll('.finance-account-card').forEach((card, index) => {
            order.set(card.dataset.accountId, index)
          })
          const rows = Array.from(list.querySelectorAll('.finance-account-list-row'))
          rows.sort((a, b) => (order.get(a.dataset.accountId) ?? 0) - (order.get(b.dataset.accountId) ?? 0))
          rows.forEach((row) => list.appendChild(row))
        }

        async function saveAccountOrder(container) {
          const accountIds = Array.from(container.querySelectorAll('.finance-account-card'))
            .map((card) => parseInt(card.dataset.accountId, 10))
            .filter((id) => id > 0)
          if (accountIds.length === 0) return

          try {
            const response = await fetch('/finance/accounts/reorder', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
              },
              body: JSON.stringify({ accountIds }),
            })
            if (!response.ok) throw new Error('reorder failed')
          } catch (_) {
            window.location.reload()
          }
        }

        document.querySelectorAll('.finance-account-drag-handle').forEach((handle) => {
          handle.addEventListener('dragstart', (event) => {
            draggedCard = handle.closest('.finance-account-card')
            if (!draggedCard) return
            draggedCard.classList.add('is-dragging')
            event.dataTransfer.effectAllowed = 'move'
            event.dataTransfer.setData('text/plain', draggedCard.dataset.accountId || '')
          })
          handle.addEventListener('dragend', () => {
            draggedCard?.classList.remove('is-dragging')
            draggedCard = null
          })
        })

        document.querySelectorAll('.finance-account-cards').forEach((container) => {
          container.addEventListener('dragover', (event) => {
            event.preventDefault()
            if (!draggedCard || draggedCard.closest('.finance-account-cards') !== container) return
            const after = getDragAfterCard(container, event.clientX)
            if (after == null) container.appendChild(draggedCard)
            else container.insertBefore(draggedCard, after)
          })
          container.addEventListener('drop', (event) => {
            event.preventDefault()
            if (!draggedCard) return
            syncListOrder(container)
            saveAccountOrder(container)
          })
        })

        const scheduleModal = document.getElementById('finance-schedule-modal')
        const scheduleForm = document.getElementById('finance-schedule-form')
        const scheduleList = document.getElementById('finance-schedule-list')
        const scheduleModalTitle = document.getElementById('finance-schedule-modal-title')
        const scheduleAccountName = document.getElementById('finance-schedule-account-name')
        const scheduleReturnTo = @json($returnTo);

        function formatScheduleMoney(amount, currency) {
          const prefix = currency === 'PHP' ? '₱' : '¥'
          const decimals = currency === 'PHP' ? 2 : 0
          return prefix + Number(amount).toLocaleString('ja-JP', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
          })
        }

        function openScheduleModal(account) {
          if (!account?.scheduleType) return
          scheduleModalTitle.textContent = account.scheduleTypeLabel || '予定'
          scheduleAccountName.textContent = account.name || ''
          scheduleForm.action = `/finance/accounts/${account.id}/schedules`
          scheduleForm.querySelector('#finance-schedule-date').value = @json($defaultDate);
          scheduleForm.querySelector('#finance-schedule-amount').value = ''
          scheduleForm.querySelector('#finance-schedule-memo').value = ''
          scheduleList.innerHTML = ''
          const schedules = account.schedules || []
          if (schedules.length === 0) {
            scheduleList.innerHTML = '<p class="hint">登録された予定はありません。</p>'
          } else {
            schedules.forEach((schedule) => {
              const row = document.createElement('div')
              row.className = 'finance-account-schedule-item'
              const memo = schedule.memo
                ? `<span class="finance-account-schedule-memo">${schedule.memo}</span>`
                : ''
              row.innerHTML = `
                <span>${schedule.scheduledDate} ${formatScheduleMoney(schedule.amount, account.currency)}</span>
                ${memo}
                <form method="post" action="/finance/schedules/${schedule.id}/delete" class="finance-inline-form">
                  <input type="hidden" name="_token" value="${csrfToken || ''}" />
                  <input type="hidden" name="returnTo" value="${scheduleReturnTo}" />
                  <button type="submit" class="text-btn danger">削除</button>
                </form>
              `
              scheduleList.appendChild(row)
            })
          }
          bindAmountCalcInputs(scheduleModal)
          scheduleModal?.removeAttribute('hidden')
        }

        function closeScheduleModal() {
          scheduleModal?.setAttribute('hidden', '')
        }

        document.querySelectorAll('.finance-account-schedule-btn').forEach((btn) => {
          btn.addEventListener('click', (event) => {
            event.stopPropagation()
            event.preventDefault()
            openScheduleModal(JSON.parse(btn.dataset.scheduleAccount))
          })
        })

        document.querySelectorAll('[data-close-finance-schedule-modal]').forEach((el) => {
          el.addEventListener('click', closeScheduleModal)
        })
      })()
    </script>
  </body>
</html>
