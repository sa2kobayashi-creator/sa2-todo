<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('入出金経費') }} - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="finance-page">
    @include('partials.header', ['active' => 'finance'])
    <main class="page-main finance-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="finance-top-bar">
        <div class="finance-tabs" role="tablist" aria-label="{{ __('表示切替') }}">
          @foreach(\App\Services\FinanceService::TAB_LABELS as $tabKey => $tabLabel)
            <a
              href="{{ $buildFinanceQuery(array_merge($filters, ['tab' => $tabKey]), ['account' => null]) }}"
              class="finance-tab @if($filters['tab'] === $tabKey) is-active @endif"
              role="tab"
              aria-selected="{{ $filters['tab'] === $tabKey ? 'true' : 'false' }}"
            >{{ __($tabLabel) }}</a>
          @endforeach
        </div>

        <form class="finance-period-form" method="get" action="/finance" id="finance-period-form">
          <input type="hidden" name="tab" value="{{ $filters['tab'] }}" />
          @if($filters['accountId'])
            <input type="hidden" name="account" value="{{ $filters['accountId'] }}" />
          @endif
          <label class="finance-period-label">
              {{ __('表示月') }}
            <input type="month" name="period" value="{{ $periodValue }}" />
          </label>
        </form>

        <a href="{{ $buildFinanceReportQuery($filters) }}" class="button-link secondary finance-report-link">{{ __('レポート') }}</a>
        <details class="finance-csv-panel">
          <summary>CSV</summary>
          <div class="finance-csv-panel-body">
            <div class="finance-csv-actions">
              <a
                href="{{ $buildFinanceExportQuery($filters, 'transactions') }}"
                class="button-link secondary"
              >{{ __('取引をエクスポート') }}</a>
              <a
                href="{{ $buildFinanceExportQuery($filters, 'budget_monitor') }}"
                class="button-link secondary"
              >{{ __('予算監視形式でエクスポート') }}</a>
              <a
                href="{{ $buildFinanceExportQuery($filters, 'accounts') }}"
                class="button-link secondary"
              >{{ __('口座マスターをエクスポート') }}</a>
            </div>
            <form method="post" action="/finance/import" enctype="multipart/form-data" class="finance-csv-import-form">
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <p class="finance-csv-form-title">{{ __('取引インポート') }}</p>
              <label class="finance-csv-file-label">
                {{ __('CSVファイル') }}
                <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required />
              </label>
              <label class="inline-check">
                <input type="checkbox" name="replace" value="1" />
                {{ __('以前の予算CSVインポート分を置き換え') }}
              </label>
              <label class="inline-check">
                <input type="checkbox" name="include_card_deltas" value="1" checked />
                {{ __('クレカ欄の金額も支出として取り込む') }}
              </label>
              <button type="submit" class="button-link">{{ __('インポート') }}</button>
            </form>
            <form method="post" action="/finance/import" enctype="multipart/form-data" class="finance-csv-import-form finance-csv-import-form-accounts">
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <input type="hidden" name="import_type" value="accounts" />
              <p class="finance-csv-form-title">{{ __('口座マスターインポート') }}</p>
              <label class="finance-csv-file-label">
                {{ __('CSVファイル') }}
                <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required />
              </label>
              <label class="inline-check">
                <input type="checkbox" name="update_existing" value="1" checked />
                {{ __('同名・同識別子の口座を更新') }}
              </label>
              <button type="submit" class="button-link">{{ __('口座マスターをインポート') }}</button>
            </form>
            <p class="hint finance-csv-hint">{{ __('予算監視CSVは見出し名と口座名を照合して取り込みます（IN/OUT・PH Bank In/Out・クレカ金額・口座残高）。取り込み前に「以前の予算CSVを置き換え」を推奨。エクスポートも同じ列順（UTF-8 BOM）。Shift-JIS のインポートにも対応。') }}</p>
          </div>
        </details>
      </div>

      <section class="finance-quick-entry panel" id="finance-quick-entry">
        <form method="post" action="/finance" id="finance-quick-entry-form" class="finance-quick-entry-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />

          <div class="finance-quick-type-tabs" role="tablist" aria-label="{{ __('取引種別') }}">
            <label class="finance-quick-type-tab is-income">
              <input type="radio" name="type" value="income" />
              <span>{{ __('入金') }}</span>
            </label>
            <label class="finance-quick-type-tab is-expense is-active">
              <input type="radio" name="type" value="expense" checked />
              <span>{{ __('支出') }}</span>
            </label>
            <label class="finance-quick-type-tab is-transfer">
              <input type="radio" name="type" value="transfer" />
              <span>{{ __('振替') }}</span>
            </label>

            <div class="finance-expense-categories" id="finance-expense-categories" role="group" aria-label="{{ __('支出カテゴリー') }}">
              <input type="hidden" name="category" id="finance-quick-category" value="" />
              @foreach($expenseCategoryPrimary as $slug => $label)
                <button
                  type="button"
                  class="finance-expense-category-btn"
                  data-category="{{ $slug }}"
                >{{ __($label) }}</button>
              @endforeach
              <button
                type="button"
                class="finance-expense-category-btn is-other"
                id="finance-expense-category-other"
                data-category="__other__"
              >{{ __('その他') }}</button>
            </div>
          </div>

          <div class="finance-quick-entry-fields">
            <div class="finance-quick-entry-row finance-quick-entry-row-main" id="finance-quick-entry-row-main">
              <label class="finance-quick-field finance-quick-field-date">
                <span class="finance-quick-field-label">{{ __('日付') }}</span>
                <input type="date" name="transactionDate" id="finance-quick-date" value="{{ $defaultDate }}" required />
              </label>

              <label class="finance-quick-field finance-quick-field-account">
                <span class="finance-quick-field-label" id="finance-quick-account-label">{{ __('口座') }}</span>
                <select name="accountId" id="finance-quick-account" required>
                  @foreach($accounts as $account)
                    <option value="{{ $account['id'] }}" data-region="{{ $account['region'] }}" data-currency="{{ $account['currency'] }}" data-kind="{{ $account['kind'] }}">
                      {{ $account['kindLabel'] }}: {{ $account['name'] }}
                    </option>
                  @endforeach
                </select>
              </label>

              <label class="finance-quick-field finance-quick-field-to-account" id="finance-quick-to-account-field" hidden>
                <span class="finance-quick-field-label">{{ __('振替先') }}</span>
                <select name="toAccountId" id="finance-quick-to-account">
                  @foreach($accounts as $account)
                    <option value="{{ $account['id'] }}" data-region="{{ $account['region'] }}" data-currency="{{ $account['currency'] }}" data-kind="{{ $account['kind'] }}">
                      {{ $account['kindLabel'] }}: {{ $account['name'] }}
                    </option>
                  @endforeach
                </select>
              </label>

              <label class="finance-quick-field finance-quick-field-amount">
                <span class="finance-quick-field-label" id="finance-quick-amount-label">{{ __('金額') }}</span>
                <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" id="finance-quick-amount" required placeholder="1000" autocomplete="off" />
              </label>

              <label class="finance-quick-field finance-quick-field-to-amount" id="finance-quick-to-amount-wrap" hidden>
                <span class="finance-quick-field-label">{{ __('振替先金額') }}</span>
                <input type="text" inputmode="decimal" class="finance-amount-calc" name="toAmount" id="finance-quick-to-amount" placeholder="1000" autocomplete="off" />
              </label>
            </div>

            <div class="finance-quick-entry-row finance-quick-entry-row-memo">
              <label class="finance-quick-field finance-quick-field-memo">
                <span class="finance-quick-field-label">{{ __('メモ') }}</span>
                <input type="text" name="memo" id="finance-quick-memo" placeholder="{{ __('例: 給与、スーパー、Amazon（内容を書くと後から探しやすい）') }}" autocomplete="off" />
              </label>

              <button type="submit" class="finance-quick-submit" id="finance-quick-submit">{{ __('登録') }}</button>
            </div>
          </div>
        </form>
        <p class="hint finance-quick-entry-hint">{{ __('金額は入力欄をクリックして直接入力するか、横の') }} <span class="finance-easy-amount-inline-hint" aria-hidden="true"><svg viewBox="0 0 24 24" width="14" height="14"><rect x="3" y="3" width="7" height="6" rx="1.5" fill="currentColor"></rect><rect x="14" y="3" width="7" height="6" rx="1.5" fill="currentColor"></rect><rect x="3" y="11" width="7" height="6" rx="1.5" fill="currentColor"></rect><rect x="14" y="11" width="7" height="6" rx="1.5" fill="currentColor"></rect></svg></span> {{ __('ボタン（簡単入力）から入れられます。今日以前の日付は') }}<strong>{{ __('すぐ残高に反映') }}</strong>{{ __('されます。') }}</p>
      </section>

      <p class="finance-tab-context" aria-live="polite">{{ $tabContextLabel }}</p>

      <section class="finance-balance-overview panel">
        <h2 class="finance-section-title">{{ __('現在の総残高') }}</h2>
        <div class="finance-balance-overview-grid">
          @forelse($balanceTotals['totals'] as $currency => $total)
            <div class="finance-balance-overview-item finance-balance-overview-main">
              <span class="finance-summary-label">{{ __('総残高') }}</span>
              <strong class="finance-balance-overview-total">{{ $formatMoney($total, $currency) }}</strong>
            </div>
          @empty
            @if($filters['tab'] === 'transfer')
              <p class="hint">{{ __('表示対象の口座がありません。') }}</p>
            @endif
          @endforelse
          @if($filters['tab'] !== 'transfer')
            @if($filters['tab'] === 'all')
              @foreach($overviewAccountsByRegion as $region => $regionAccounts)
                <div class="finance-balance-overview-region" data-region="{{ $region }}">
                  <span class="finance-balance-overview-region-label">{{ __(\App\Services\FinanceService::REGION_LABELS[$region] ?? $region) }}</span>
                  <div class="finance-balance-overview-region-cards">
                    @foreach($regionAccounts as $account)
                      @include('finance.partials.balance-overview-account-card', ['account' => $account, 'returnTo' => $returnTo, 'formatMoney' => $formatMoney])
                    @endforeach
                  </div>
                </div>
              @endforeach
            @else
              @foreach($overviewAccounts as $account)
                @include('finance.partials.balance-overview-account-card', ['account' => $account, 'returnTo' => $returnTo, 'formatMoney' => $formatMoney])
              @endforeach
            @endif
            <button type="button" class="finance-balance-overview-item finance-balance-overview-add" id="finance-open-overview-add" aria-label="{{ __('残高カードを追加') }}">
              <span class="finance-balance-overview-add-icon" aria-hidden="true">＋</span>
              <span class="finance-balance-overview-add-label">{{ __('カードを追加') }}</span>
            </button>
          @endif
        </div>
        @if(!empty($balanceTotals['assets']) || !empty($balanceTotals['creditCards']))
          <div class="finance-balance-overview-breakdown">
            @foreach($balanceTotals['assets'] as $currency => $amount)
              <span>{{ __('口座・現金') }} {{ $formatMoney($amount, $currency) }}</span>
            @endforeach
            @foreach($balanceTotals['creditCards'] as $currency => $amount)
              <span>{{ __('クレカ利用額') }} {{ $formatMoney($amount, $currency) }}</span>
            @endforeach
          </div>
        @endif
        @if(!empty($balanceTotals['upcomingPayments']) || !empty($balanceTotals['upcomingDeposits']))
          <div class="finance-balance-overview-schedules">
            @foreach($balanceTotals['upcomingPayments'] as $currency => $amount)
              <span>{{ __('次回支払予定合計') }} {{ $formatMoney($amount, $currency) }}</span>
            @endforeach
            @foreach($balanceTotals['upcomingDeposits'] as $currency => $amount)
              <span>{{ __('次回入金予定合計') }} {{ $formatMoney($amount, $currency) }}</span>
            @endforeach
          </div>
        @endif
      </section>

      <section class="finance-summary panel">
        <h2 class="finance-section-title">{{ $monthLabel }} {{ __('サマリー') }}</h2>
        <p class="hint finance-summary-hint">{{ __('カードを押すと内訳を表示します。支出はカテゴリーで絞り込めます。') }}</p>
        <div class="finance-summary-grid">
          <button type="button" class="finance-summary-item finance-summary-clickable" data-summary-detail="income">
            <span class="finance-summary-label">{{ __('収入（入金）') }}</span>
            <strong class="finance-summary-value income">{{ $formatMoney($summary['income'], $summary['currency']) }}</strong>
            <span class="finance-summary-action">{{ __('詳細を見る') }}</span>
          </button>
          <button type="button" class="finance-summary-item finance-summary-clickable" data-summary-detail="expense">
            <span class="finance-summary-label">{{ __('支出') }}</span>
            <strong class="finance-summary-value expense">{{ $formatMoney($summary['expense'], $summary['currency']) }}</strong>
            <span class="finance-summary-action">{{ __('カテゴリー別で見る') }}</span>
          </button>
          <button type="button" class="finance-summary-item finance-summary-clickable" data-summary-detail="net">
            <span class="finance-summary-label">{{ __('収支') }}</span>
            <strong class="finance-summary-value @if($summary['net'] >= 0) income @else expense @endif">{{ $formatMoney($summary['net'], $summary['currency']) }}</strong>
            <span class="finance-summary-action">{{ __('詳細を見る') }}</span>
          </button>
          @if($filters['tab'] === 'transfer' || $filters['tab'] === 'all' || $filters['tab'] === 'jp' || $filters['tab'] === 'ph')
            <button type="button" class="finance-summary-item finance-summary-clickable" data-summary-detail="transferOut">
              <span class="finance-summary-label">{{ __('振替出') }}</span>
              <strong class="finance-summary-value">{{ $formatMoney($summary['transferOut'], $summary['currency']) }}</strong>
              <span class="finance-summary-action">{{ __('詳細を見る') }}</span>
            </button>
            <button type="button" class="finance-summary-item finance-summary-clickable" data-summary-detail="transferIn">
              <span class="finance-summary-label">{{ __('振替入') }}</span>
              <strong class="finance-summary-value">{{ $formatMoney($summary['transferIn'], $summary['currency']) }}</strong>
              <span class="finance-summary-action">{{ __('詳細を見る') }}</span>
            </button>
          @endif
        </div>
      </section>

      @if($filters['tab'] !== 'transfer')
        <section class="finance-accounts panel">
          <div class="finance-section-head">
            <h2 class="finance-section-title">{{ __('口座残高') }}</h2>
            <div class="finance-section-actions">
              <div class="finance-view-toggle" role="group" aria-label="{{ __('口座表示切替') }}">
                <button type="button" class="finance-view-toggle-btn" data-accounts-view="cards" aria-pressed="false">{{ __('カード') }}</button>
                <button type="button" class="finance-view-toggle-btn is-active" data-accounts-view="list" aria-pressed="true">{{ __('リスト') }}</button>
              </div>
              <button type="button" class="text-btn" id="finance-open-add-account">{{ __('＋ 口座を追加') }}</button>
              <button type="button" class="text-btn" id="finance-toggle-settings" aria-expanded="false" aria-controls="finance-account-settings">{{ __('口座設定') }}</button>
            </div>
          </div>

          <p class="hint finance-drag-hint">{{ __('カード表示では') }} <span class="finance-drag-hint-icon" aria-hidden="true">⠿</span> {{ __('をドラッグして並び替えできます。入金・支出は上部フォームか、各口座の') }} <strong>{{ __('入金 / 支出') }}</strong> {{ __('ボタンから。日付欄は将来の') }}<strong>{{ __('予定') }}</strong>{{ __('用です。') }}</p>

          <div class="finance-accounts-view" id="finance-accounts-view" data-view="list">
          @forelse($accountDisplayGroups as $displayGroup)
            <div class="finance-account-region-block" @if($displayGroup['region']) data-region="{{ $displayGroup['region'] }}" @endif>
              @if($displayGroup['regionLabel'])
                <h3 class="finance-account-region-title">{{ $displayGroup['regionLabel'] }}</h3>
              @endif
              @foreach($displayGroup['kinds'] as $kind => $kindAccounts)
            <div class="finance-account-group" data-kind="{{ $kind }}" @if($displayGroup['region']) data-region="{{ $displayGroup['region'] }}" @endif>
              <h3 class="finance-account-group-title">{{ __(\App\Services\FinanceService::KIND_LABELS[$kind] ?? $kind) }}</h3>
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
                      aria-label="{{ $account['name'] }} {{ __('の表示順を変更') }}"
                      title="{{ __('ドラッグして並び替え') }}"
                    >⠿</button>
                    <div class="finance-account-card-body" tabindex="0" role="button" aria-label="{{ $account['name'] }} {{ __('を編集') }}">
                      <span class="finance-account-name">{{ $account['name'] }}</span>
                      <strong class="finance-account-balance">{{ $formatMoney($account['balance'], $account['currency']) }}</strong>
                      <span class="finance-account-balance-label">{{ $account['balanceLabel'] ?? __('残高') }}</span>
                      @if(($account['adjustmentAmount'] ?? 0) != 0)
                        <span class="finance-account-adjustment">{{ __('調整') }} {{ $formatMoney($account['adjustmentAmount'], $account['currency']) }}</span>
                      @endif
                      @if(($account['scheduleType'] ?? null) === 'payment')
                        <form method="post" action="/finance/accounts/{{ $account['id'] }}/schedules/upsert" class="finance-card-schedule-form" onclick="event.stopPropagation()">
                          @csrf
                          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                          <label class="finance-card-schedule-field">
                            <span>{{ __('支払予定日') }}</span>
                            <input type="date" name="scheduledDate" value="{{ $account['nextSchedule']['scheduledDate'] ?? '' }}" required />
                          </label>
                          <label class="finance-card-schedule-field">
                            <span>{{ __('支払額') }}</span>
                            <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" value="{{ $account['nextSchedule']['amount'] ?? '' }}" required placeholder="1000+340" autocomplete="off" />
                          </label>
                          <label class="finance-card-schedule-field">
                            <span>{{ __('メモ') }}</span>
                            <input type="text" name="memo" value="{{ $account['nextSchedule']['memo'] ?? '' }}" placeholder="{{ __('給与、カード引落 など') }}" autocomplete="off" />
                          </label>
                          <div class="finance-card-schedule-actions">
                            <button type="submit" class="text-btn finance-card-schedule-save">{{ __('保存') }}</button>
                            @if(!empty($account['nextSchedule']['id']))
                              <button
                                type="submit"
                                class="text-btn danger"
                                formaction="/finance/schedules/{{ $account['nextSchedule']['id'] }}/delete"
                                formmethod="post"
                                onclick='return confirm(@json(__('予定を削除しますか？') . "\n" . __('既に反映済みの取引がある場合はそれも削除されます。')))'
                              >{{ __('クリア') }}</button>
                            @endif
                            @if(count($account['schedules'] ?? []) > 0)
                              <button type="button" class="text-btn finance-account-schedule-btn" data-schedule-account='@json($account)'>{{ __('一覧') }}</button>
                            @endif
                          </div>
                        </form>
                      @elseif(($account['scheduleType'] ?? null) === 'deposit')
                        <form method="post" action="/finance/accounts/{{ $account['id'] }}/schedules/upsert" class="finance-card-schedule-form" onclick="event.stopPropagation()">
                          @csrf
                          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                          <label class="finance-card-schedule-field">
                            <span>{{ __('入金予定日') }}</span>
                            <input type="date" name="scheduledDate" value="{{ $account['nextSchedule']['scheduledDate'] ?? '' }}" required />
                          </label>
                          <label class="finance-card-schedule-field">
                            <span>{{ __('入金額') }}</span>
                            <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" value="{{ $account['nextSchedule']['amount'] ?? '' }}" required placeholder="1000+340" autocomplete="off" />
                          </label>
                          <label class="finance-card-schedule-field">
                            <span>{{ __('メモ') }}</span>
                            <input type="text" name="memo" value="{{ $account['nextSchedule']['memo'] ?? '' }}" placeholder="{{ __('給与、振込元 など') }}" autocomplete="off" />
                          </label>
                          <div class="finance-card-schedule-actions">
                            <button type="submit" class="text-btn finance-card-schedule-save">{{ __('保存') }}</button>
                            @if(!empty($account['nextSchedule']['id']))
                              <button
                                type="submit"
                                class="text-btn danger"
                                formaction="/finance/schedules/{{ $account['nextSchedule']['id'] }}/delete"
                                formmethod="post"
                                onclick='return confirm(@json(__('予定を削除しますか？') . "\n" . __('既に反映済みの取引がある場合はそれも削除されます。')))'
                              >{{ __('クリア') }}</button>
                            @endif
                            @if(count($account['schedules'] ?? []) > 0)
                              <button type="button" class="text-btn finance-account-schedule-btn" data-schedule-account='@json($account)'>{{ __('一覧') }}</button>
                            @endif
                          </div>
                        </form>
                      @endif
                      <div class="finance-account-quick-btns" onclick="event.stopPropagation()">
                        <button type="button" class="finance-account-quick-btn income" data-quick-type="income" data-account-id="{{ $account['id'] }}">{{ __('入金') }}</button>
                        <button type="button" class="finance-account-quick-btn expense" data-quick-type="expense" data-account-id="{{ $account['id'] }}">{{ __('支出') }}</button>
                      </div>
                      <a
                        href="{{ $buildFinanceQuery(array_merge($filters, ['accountId' => $account['id']]), ['account' => $account['id']]) }}#finance-transactions"
                        class="finance-account-filter-link"
                        title="{{ __('この口座の取引一覧を表示') }}"
                        data-finance-account-filter="{{ $account['id'] }}"
                        >{{ __('取引') }}</a>
                      <form
                        method="post"
                        action="/finance/accounts/{{ $account['id'] }}/delete"
                        class="finance-inline-form finance-account-delete-form"
                        onclick="event.stopPropagation()"
                        onsubmit='return confirm(@json('「'.$account['name'].'」'.__('を削除しますか？') . "\n" . __('一覧から非表示になります（過去の取引は残ります）。')))'
                      >
                        @csrf
                        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                        <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
                      </form>
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
                    aria-label="{{ $account['name'] }} {{ __('を編集') }}"
                  >
                    <div class="finance-account-list-identity">
                      <span class="finance-account-list-name">{{ $account['name'] }}</span>
                      <strong class="finance-account-list-balance">{{ $formatMoney($account['balance'], $account['currency']) }}</strong>
                      @if(($account['adjustmentAmount'] ?? 0) != 0)
                        <span class="finance-adjustment-badge">{{ __('調整') }} {{ $formatMoney($account['adjustmentAmount'], $account['currency']) }}</span>
                      @endif
                    </div>
                    @if(($account['scheduleType'] ?? null) === 'payment')
                      <form method="post" action="/finance/accounts/{{ $account['id'] }}/schedules/upsert" class="finance-list-schedule-form" id="finance-list-schedule-form-{{ $account['id'] }}">
                        @csrf
                        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                        <span class="finance-list-schedule-label">{{ __('支払予定') }}</span>
                        <input type="date" name="scheduledDate" value="{{ $account['nextSchedule']['scheduledDate'] ?? '' }}" required aria-label="{{ __('支払予定日') }}" />
                        <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" value="{{ $account['nextSchedule']['amount'] ?? '' }}" required placeholder="{{ __('支払額') }}" aria-label="{{ __('支払額') }}" autocomplete="off" />
                        <input type="text" name="memo" value="{{ $account['nextSchedule']['memo'] ?? '' }}" placeholder="{{ __('メモ') }}" aria-label="{{ __('メモ') }}" autocomplete="off" />
                      </form>
                    @elseif(($account['scheduleType'] ?? null) === 'deposit')
                      <form method="post" action="/finance/accounts/{{ $account['id'] }}/schedules/upsert" class="finance-list-schedule-form" id="finance-list-schedule-form-{{ $account['id'] }}">
                        @csrf
                        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                        <span class="finance-list-schedule-label">{{ __('入金予定') }}</span>
                        <input type="date" name="scheduledDate" value="{{ $account['nextSchedule']['scheduledDate'] ?? '' }}" required aria-label="{{ __('入金予定日') }}" />
                        <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" value="{{ $account['nextSchedule']['amount'] ?? '' }}" required placeholder="{{ __('入金額') }}" aria-label="{{ __('入金額') }}" autocomplete="off" />
                        <input type="text" name="memo" value="{{ $account['nextSchedule']['memo'] ?? '' }}" placeholder="{{ __('メモ') }}" aria-label="{{ __('メモ') }}" autocomplete="off" />
                      </form>
                    @else
                      <span class="finance-list-schedule-empty" aria-hidden="true"></span>
                    @endif
                    <div class="finance-account-list-actions" onclick="event.stopPropagation()">
                      <button type="button" class="finance-account-quick-btn income" data-quick-type="income" data-account-id="{{ $account['id'] }}">{{ __('入金') }}</button>
                      <button type="button" class="finance-account-quick-btn expense" data-quick-type="expense" data-account-id="{{ $account['id'] }}">{{ __('支出') }}</button>
                      <div class="finance-account-list-manage">
                        @if(($account['scheduleType'] ?? null) === 'payment' || ($account['scheduleType'] ?? null) === 'deposit')
                          <button type="submit" form="finance-list-schedule-form-{{ $account['id'] }}" class="text-btn finance-list-schedule-save">{{ __('保存') }}</button>
                          @if(!empty($account['nextSchedule']['id']))
                            <button
                              type="submit"
                              form="finance-list-schedule-form-{{ $account['id'] }}"
                              class="text-btn danger finance-list-schedule-clear"
                              formaction="/finance/schedules/{{ $account['nextSchedule']['id'] }}/delete"
                              formmethod="post"
                              onclick='return confirm(@json(__('予定を削除しますか？') . "\n" . __('既に反映済みの取引がある場合はそれも削除されます。')))'
                            >{{ __('クリア') }}</button>
                          @endif
                        @endif
                        <a
                          href="{{ $buildFinanceQuery(array_merge($filters, ['accountId' => $account['id']]), ['account' => $account['id']]) }}#finance-transactions"
                          class="text-btn finance-account-filter-link"
                          title="{{ __('この口座の取引一覧を表示') }}"
                          data-finance-account-filter="{{ $account['id'] }}"
                      >{{ __('取引') }}</a>
                        <button type="button" class="text-btn finance-edit-account-card-btn">{{ __('編集') }}</button>
                        <form
                          method="post"
                          action="/finance/accounts/{{ $account['id'] }}/delete"
                          class="finance-inline-form finance-account-delete-form"
                          onsubmit='return confirm(@json('「'.$account['name'].'」'.__('を削除しますか？') . "\n" . __('一覧から非表示になります（過去の取引は残ります）。')))'
                        >
                          @csrf
                          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                          <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
                        </form>
                      </div>
                    </div>
                  </div>
                @endforeach
              </div>
            </div>
              @endforeach
            </div>
          @empty
            <p class="hint">{{ __('表示する口座がありません。') }}</p>
          @endforelse
          </div>

          @if($filters['accountId'])
            <p class="hint inline-hint">
              {{ __('口座フィルタ中') }}
              <a href="{{ $buildFinanceQuery(array_merge($filters, ['accountId' => null])) }}">{{ __('解除') }}</a>
            </p>
          @endif

          <div class="finance-account-settings" id="finance-account-settings" hidden>
            <h3 class="finance-account-group-title">{{ __('口座・クレカの管理') }}</h3>
            <p class="hint finance-settings-hint">{{ __('各口座の名前・種別・開始残高・調整金額・引落口座・予定の管理、削除ができます。カードをクリックしても同様の編集ができます。') }}</p>
            @foreach($accounts as $account)
              <details class="finance-account-setting-row" data-account='@json($account)'>
                <summary>
                  <span class="finance-account-setting-name">
                    [{{ $account['regionLabel'] }}] {{ $account['name'] }}
                    <span class="finance-kind-badge">{{ $account['kindLabel'] }}</span>
                  </span>
                  <span class="finance-account-setting-balance">{{ $formatMoney($account['balance'], $account['currency']) }}</span>
                  @if(($account['adjustmentAmount'] ?? 0) != 0)
                    <span class="finance-adjustment-badge">{{ __('調整') }} {{ $formatMoney($account['adjustmentAmount'], $account['currency']) }}</span>
                  @endif
                </summary>
                <div class="finance-account-setting-actions">
                  <button type="button" class="text-btn finance-edit-account-btn">{{ __('編集') }}</button>
                  <form method="post" action="/finance/accounts/{{ $account['id'] }}/delete" class="finance-inline-form" onsubmit='return confirm(@json(__('この口座を削除しますか？') . "\n" . __('過去の取引は残りますが、一覧からは非表示になります。')))'>
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
                  </form>
                </div>
                <form method="post" action="/finance/accounts/{{ $account['id'] }}/balance" class="finance-inline-form">
                  @csrf
                  <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                  <label>
                    {{ __('開始残高') }}
                    <input type="text" inputmode="decimal" class="finance-amount-calc" name="initialBalance" value="{{ $account['initialBalance'] }}" autocomplete="off" />
                  </label>
                  <label>
                    {{ __('調整金額') }}
                    <input type="text" inputmode="decimal" class="finance-amount-calc" name="adjustmentAmount" value="{{ $account['adjustmentAmount'] ?? 0 }}" autocomplete="off" />
                  </label>
                  <button type="submit" class="button-link secondary">{{ __('残高を保存') }}</button>
                </form>
                @if($account['kind'] === 'credit_card')
                  <form method="post" action="/finance/accounts/{{ $account['id'] }}/linked-bank" class="finance-inline-form">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                    <label>
                      {{ __('引落口座') }}
                      <select name="linkedBankId">
                        <option value="">{{ __('未設定') }}</option>
                        @foreach($bankAccounts as $bank)
                          @if($bank['region'] === $account['region'])
                            <option value="{{ $bank['id'] }}" @selected($account['linkedBankId'] === $bank['id'])>{{ $bank['name'] }}</option>
                          @endif
                        @endforeach
                      </select>
                    </label>
                    <button type="submit" class="button-link secondary">{{ __('引落口座を保存') }}</button>
                  </form>
                @endif
                @if($account['scheduleType'] ?? null)
                  <div class="finance-account-schedule-panel">
                    <h4 class="finance-account-schedule-title">{{ $account['scheduleTypeLabel'] }}</h4>
                    @forelse($account['schedules'] as $schedule)
                      <form method="post" action="/finance/schedules/{{ $schedule['id'] }}/update" class="finance-inline-form finance-schedule-edit-form">
                        @csrf
                        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                        <label>
                          {{ __('予定日') }}
                          <input type="date" name="scheduledDate" value="{{ $schedule['scheduledDate'] }}" required />
                        </label>
                        <label>
                          {{ __('金額') }}
                          <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" value="{{ $schedule['amount'] }}" required autocomplete="off" />
                        </label>
                        <label>
                          {{ __('メモ') }}
                          <input type="text" name="memo" value="{{ $schedule['memo'] }}" />
                        </label>
                        <div class="finance-schedule-edit-actions">
                          <button type="submit" class="button-link secondary">{{ __('更新') }}</button>
                        </div>
                      </form>
                      <form method="post" action="/finance/schedules/{{ $schedule['id'] }}/delete" class="finance-inline-form finance-schedule-delete-form" onsubmit='return confirm(@json(__('この予定を削除しますか？') . "\n" . __('既に引落済みの取引がある場合はそれも削除されます。')))'>
                        @csrf
                        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                        <button type="submit" class="text-btn danger">{{ __('予定を削除') }}</button>
                      </form>
                    @empty
                      <p class="hint">{{ __('登録された予定はありません。') }}</p>
                    @endforelse
                    <form method="post" action="/finance/accounts/{{ $account['id'] }}/schedules" class="finance-inline-form">
                      @csrf
                      <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                      <label>
                        {{ __('予定日') }}
                        <input type="date" name="scheduledDate" value="{{ $defaultDate }}" required />
                      </label>
                      <label>
                        {{ __('金額') }}
                        <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" required placeholder="1000+340" autocomplete="off" />
                      </label>
                      <label>
                        {{ __('メモ') }}
                        <input type="text" name="memo" placeholder="{{ __('給与、カード引落 など') }}" />
                      </label>
                      <button type="submit" class="button-link secondary">{{ __('予定を追加') }}</button>
                    </form>
                  </div>
                @endif
              </details>
            @endforeach
          </div>
        </section>
      @endif

      <section class="finance-transactions panel" id="finance-transactions">
        <div class="finance-transactions-header">
          <div>
            <h2 class="finance-section-title">{{ __('取引一覧') }}</h2>
        <p class="hint finance-transactions-hint">{{ __('年・月・日と口座で絞り込み。金額は入金/出金/振替、残高は総残高・全銀行・現金現在残高・クレカ支払い/残金です。') }}</p>
          </div>
          <div class="finance-transactions-header-actions">
            <form class="finance-transaction-filter-form" method="get" action="/finance" id="finance-tx-filter-form">
              <input type="hidden" name="tab" value="{{ $filters['tab'] }}" />
              <input type="hidden" name="period" id="finance-tx-period" value="{{ $periodValue }}" />
              @php
                $filterYear = (int) $filters['year'];
                $filterMonth = (int) $filters['month'];
                $filterDay = $filters['day'] ?? null;
                $daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $filterYear, $filterMonth)));
              @endphp
              <label class="finance-transaction-filter-label">
                {{ __('年') }}
                <select name="year" id="finance-tx-year" class="finance-transaction-filter-select">
                  @for($y = $filterYear - 2; $y <= $filterYear + 1; $y++)
                    <option value="{{ $y }}" @selected($y === $filterYear)>{{ __(':year年', ['year' => $y]) }}</option>
                  @endfor
                </select>
              </label>
              <label class="finance-transaction-filter-label">
                {{ __('月') }}
                <select name="month" id="finance-tx-month" class="finance-transaction-filter-select">
                  @for($m = 1; $m <= 12; $m++)
                    <option value="{{ sprintf('%02d', $m) }}" @selected($m === $filterMonth)>{{ __(':month月', ['month' => $m]) }}</option>
                  @endfor
                </select>
              </label>
              <label class="finance-transaction-filter-label">
                {{ __('日') }}
                <select name="day" id="finance-tx-day" class="finance-transaction-filter-select">
                  <option value="" @selected($filterDay === null)>{{ __('すべて') }}</option>
                  @for($d = 1; $d <= $daysInMonth; $d++)
                    <option value="{{ sprintf('%02d', $d) }}" @selected((int) $filterDay === $d)>{{ __(':day日', ['day' => $d]) }}</option>
                  @endfor
                </select>
              </label>
              <label class="finance-transaction-filter-label">
                {{ __('口座') }}
                <select name="account" class="finance-transaction-filter-select" onchange="this.form.submit()">
                  <option value="">{{ __('すべて') }}</option>
                  @foreach($allAccounts as $account)
                    <option value="{{ $account['id'] }}" @selected($filters['accountId'] === $account['id'])>
                      [{{ $account['regionLabel'] }}] {{ $account['name'] }}
                    </option>
                  @endforeach
                </select>
              </label>
            </form>
          </div>
        </div>

        @if(count($transactions) > 0)
          <div class="finance-tx-bulk-bar" id="finance-tx-bulk-bar">
            <label class="finance-tx-bulk-select-all">
              <input type="checkbox" id="finance-tx-select-all" />
              {{ __('すべて選択') }}
            </label>
            <span class="finance-tx-bulk-count" id="finance-tx-bulk-count">0{{ __('件選択') }}</span>
            <button
              type="button"
              class="text-btn danger finance-tx-bulk-btn"
              id="finance-tx-bulk-delete"
              data-bulk-url="/finance/bulk/delete"
              data-confirm="{{ __('選択した取引を削除しますか？') }}&#10;{{ __('カード引落の場合は支払予定も削除されます。') }}"
            >{{ __('一括削除') }}</button>
            <input type="hidden" id="finance-tx-bulk-return" value="{{ $returnTo }}" />
          </div>
        @endif

        @if(!empty($transactionBalanceContext))
          <div class="finance-transaction-balance-summary">
            <span class="finance-transaction-balance-account">
              {{ $transactionBalanceContext['kindLabel'] }}: {{ $transactionBalanceContext['accountName'] }}
            </span>
            <span class="finance-transaction-balance-item">
              {{ $monthLabel }} {{ __('月初残高:') }}
              <strong>{{ $formatMoney($transactionBalanceContext['openingBalance'], $transactionBalanceContext['currency']) }}</strong>
            </span>
            <span class="finance-transaction-balance-item">
              {{ __('現在残高:') }}
              <strong>{{ $formatMoney($transactionBalanceContext['currentBalance'], $transactionBalanceContext['currency']) }}</strong>
            </span>
          </div>
        @endif

        @if(count($transactions) === 0)
          <p class="hint">{{ __('この条件の取引はありません。「＋ 取引を追加」から登録できます。') }}</p>
        @else
          <div class="finance-transaction-table-wrap" id="finance-tx-table-wrap">
            <table class="finance-transaction-table is-resizable" id="finance-tx-table">
              <colgroup>
                <col data-col="check" style="width: 36px" />
                <col data-col="date" style="width: 110px" />
                <col data-col="account" style="width: 140px" />
                <col data-col="income" style="width: 88px" />
                <col data-col="expense" style="width: 88px" />
                <col data-col="transfer" style="width: 88px" />
                <col data-col="total" style="width: 96px" />
                <col data-col="bank" style="width: 96px" />
                <col data-col="cash" style="width: 80px" />
                <col data-col="card_payment" style="width: 100px" />
                <col data-col="card_remaining" style="width: 100px" />
                <col data-col="memo" style="width: 140px" />
                <col data-col="actions" style="width: 96px" />
              </colgroup>
              <thead>
                <tr>
                  <th scope="col" class="is-check" data-col="check"><span class="visually-hidden">{{ __('選択') }}</span></th>
                  <th scope="col" data-col="date">{{ __('日付') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" data-col="account">{{ __('口座') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" class="is-col-left" data-col="income">{{ __('入金') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" class="is-col-left" data-col="expense">{{ __('出金') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" class="is-col-left" data-col="transfer">{{ __('振替') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" class="is-col-left" data-col="total">{{ __('総残高') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" class="is-col-left" data-col="bank">{{ __('銀行') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" class="is-col-left" data-col="cash">{{ __('現金') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" class="is-col-left" data-col="card_payment">{{ __('クレカ支払い') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" class="is-col-left" data-col="card_remaining">{{ __('クレカ残金') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" data-col="memo">{{ __('メモ') }}<span class="finance-col-resize" aria-hidden="true"></span></th>
                  <th scope="col" class="is-col-left is-actions" data-col="actions">{{ __('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                @foreach($transactions as $transaction)
                  @php
                    $transactionMemo = $transaction['displayMemo'] ?? $transaction['memo'];
                    $currency = $transaction['portfolioCurrency'] ?? $transaction['balanceCurrency'] ?? $transaction['currency'];
                    $incomeAmount = $transaction['type'] === 'income' ? $transaction['amount'] : null;
                    $expenseAmount = $transaction['type'] === 'expense' ? $transaction['amount'] : null;
                    $transferAmount = $transaction['type'] === 'transfer' ? $transaction['amount'] : null;
                  @endphp
                  <tr class="finance-transaction-row type-{{ $transaction['type'] }} @if(!empty($transaction['isScheduled'])) is-scheduled @endif" data-transaction='@json($transaction)'>
                    <td class="finance-transaction-check-cell" onclick="event.stopPropagation()">
                      @if(empty($transaction['isScheduleOnly']))
                        <input
                          type="checkbox"
                          class="finance-tx-check"
                          value="{{ $transaction['id'] }}"
                          aria-label="{{ ($transaction['displayDate'] ?? $transaction['transactionDate']).' '.__('の取引を選択') }}"
                        />
                      @endif
                    </td>
                    <td class="finance-transaction-date-cell">
                      <span class="finance-transaction-date">{{ $transaction['displayDate'] ?? $transaction['transactionDate'] }}</span>
                      @if(!empty($transaction['purchaseDate']))
                        <span class="finance-transaction-purchase-date" title="{{ __('利用日') }}">({{ $transaction['purchaseDate'] }})</span>
                      @endif
                      @if(!empty($transaction['isScheduled']))
                        <span class="finance-transaction-badge finance-transaction-badge-scheduled">{{ $transaction['scheduledLabel'] ?? __('予定') }}</span>
                      @endif
                    </td>
                    <td class="finance-transaction-account-cell">
                      @if($transaction['type'] === 'transfer')
                        {{ $transaction['accountName'] }} → {{ $transaction['toAccountName'] }}
                      @else
                        {{ $transaction['accountName'] }}
                      @endif
                    </td>
                    <td class="finance-transaction-amount-cell is-col-left">
                      @if($incomeAmount !== null)
                        <span class="finance-transaction-amount income">{{ $formatMoney($incomeAmount, $transaction['currency']) }}</span>
                      @else
                        <span class="finance-transaction-balance is-empty">—</span>
                      @endif
                    </td>
                    <td class="finance-transaction-amount-cell is-col-left">
                      @if($expenseAmount !== null)
                        <span class="finance-transaction-amount expense">{{ $formatMoney($expenseAmount, $transaction['currency']) }}</span>
                      @else
                        <span class="finance-transaction-balance is-empty">—</span>
                      @endif
                    </td>
                    <td class="finance-transaction-amount-cell is-col-left">
                      @if($transferAmount !== null)
                        <span class="finance-transaction-amount">
                          {{ $formatMoney($transferAmount, $transaction['currency']) }}
                          @if($transaction['toAmount'] !== null && ($transaction['toCurrency'] ?? '') !== $transaction['currency'])
                            / {{ $formatMoney($transaction['toAmount'], $transaction['toCurrency'] ?? $transaction['currency']) }}
                          @endif
                        </span>
                      @else
                        <span class="finance-transaction-balance is-empty">—</span>
                      @endif
                    </td>
                    <td class="finance-transaction-balance-cell is-col-left">
                      @if($transaction['portfolioTotal'] !== null)
                        {{ $formatMoney($transaction['portfolioTotal'], $currency) }}
                      @else
                        <span class="finance-transaction-balance is-empty">—</span>
                      @endif
                    </td>
                    <td class="finance-transaction-balance-cell is-col-left">
                      @if($transaction['portfolioBank'] !== null)
                        {{ $formatMoney($transaction['portfolioBank'], $currency) }}
                      @else
                        <span class="finance-transaction-balance is-empty">—</span>
                      @endif
                    </td>
                    <td class="finance-transaction-balance-cell is-col-left">
                      @if($transaction['portfolioCash'] !== null)
                        {{ $formatMoney($transaction['portfolioCash'], $currency) }}
                      @else
                        <span class="finance-transaction-balance is-empty">—</span>
                      @endif
                    </td>
                    <td class="finance-transaction-balance-cell is-col-left">
                      @if($transaction['portfolioCardPayment'] !== null)
                        {{ $formatMoney($transaction['portfolioCardPayment'], $currency) }}
                      @else
                        <span class="finance-transaction-balance is-empty">—</span>
                      @endif
                    </td>
                    <td class="finance-transaction-balance-cell is-col-left">
                      @if($transaction['portfolioCardRemaining'] !== null)
                        {{ $formatMoney($transaction['portfolioCardRemaining'], $currency) }}
                      @else
                        <span class="finance-transaction-balance is-empty">—</span>
                      @endif
                    </td>
                    <td class="finance-transaction-memo-cell">
                      @if(filled($transactionMemo))
                        <button
                          type="button"
                          class="finance-transaction-memo-trigger"
                          data-memo="{{ e($transactionMemo) }}"
                          aria-label="{{ __('メモ全文を表示') }}"
                        >{{ $transactionMemo }}</button>
                      @else
                        <span class="finance-transaction-balance is-empty">—</span>
                      @endif
                    </td>
                    <td class="finance-transaction-actions-cell is-col-left">
                      <div class="finance-transaction-actions" onclick="event.stopPropagation()">
                        @if(empty($transaction['isScheduleOnly']))
                          <button type="button" class="text-btn finance-edit-btn">{{ __('編集') }}</button>
                          <form method="post" action="/finance/{{ $transaction['id'] }}/delete" class="finance-inline-form finance-delete-form" onsubmit='return confirm(@json(__('この取引を削除しますか？') . (!empty($transaction['scheduleId']) ? "\n".__('対応する支払予定も削除されます。') : '')))'>
                            @csrf
                            <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                            <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
                          </form>
                        @else
                          <form method="post" action="/finance/schedules/{{ $transaction['scheduleId'] }}/delete" class="finance-inline-form finance-delete-form" onsubmit='return confirm(@json(__('この予定を削除しますか？')))'>
                            @csrf
                            <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                            <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
                          </form>
                        @endif
                      </div>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </section>
    </main>

    <div id="finance-memo-bubble" class="finance-memo-bubble" hidden role="tooltip"></div>

    <div class="modal modal-centered" id="finance-transaction-modal" hidden>
      <div class="modal-backdrop" data-close-finance-modal></div>
      <div class="modal-dialog finance-modal-dialog" role="dialog" aria-labelledby="finance-modal-title">
        <div class="modal-header">
          <h2 id="finance-modal-title">{{ __('取引を追加') }}</h2>
          <button type="button" class="modal-close" data-close-finance-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" action="/finance" id="finance-transaction-form" class="modal-form finance-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <input type="hidden" name="transaction_id" id="finance-transaction-id" value="" />

          <fieldset class="finance-type-fieldset">
            <legend>{{ __('種別') }}</legend>
            <label class="inline-check"><input type="radio" name="type" value="expense" checked /> {{ __('支出') }}</label>
            <label class="inline-check"><input type="radio" name="type" value="income" /> {{ __('入金') }}</label>
            <label class="inline-check"><input type="radio" name="type" value="transfer" /> {{ __('振替・送金') }}</label>
          </fieldset>

          <p class="hint finance-transaction-type-hint" id="finance-transaction-type-hint">
            {{ __('支出を登録すると、選んだ口座の残高から差し引かれます。') }}
          </p>

          <label>
            {{ __('日付') }}
            <input type="date" name="transactionDate" id="finance-date" value="{{ $defaultDate }}" required />
          </label>

          <label>
            {{ __('口座') }}
            <select name="accountId" id="finance-account-id" required>
              @foreach($accounts as $account)
                <option value="{{ $account['id'] }}" data-region="{{ $account['region'] }}" data-currency="{{ $account['currency'] }}" data-kind="{{ $account['kind'] }}">
                  [{{ $account['regionLabel'] }}] {{ $account['kindLabel'] }}: {{ $account['name'] }}
                </option>
              @endforeach
            </select>
          </label>

          <div id="finance-transfer-fields" hidden>
            <label>
              {{ __('入金口座') }}
              <select name="toAccountId" id="finance-to-account-id">
                @foreach($accounts as $account)
                  <option value="{{ $account['id'] }}" data-region="{{ $account['region'] }}" data-currency="{{ $account['currency'] }}" data-kind="{{ $account['kind'] }}">
                    [{{ $account['regionLabel'] }}] {{ $account['kindLabel'] }}: {{ $account['name'] }}
                  </option>
                @endforeach
              </select>
            </label>
            <label id="finance-to-amount-wrap" hidden>
              {{ __('入金側金額（異なる通貨の送金）') }}
              <input type="text" inputmode="decimal" class="finance-amount-calc" name="toAmount" id="finance-to-amount" placeholder="1000+340" autocomplete="off" />
            </label>
          </div>

          <label>
            {{ __('金額') }}
            <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" id="finance-amount" required placeholder="1000+340" autocomplete="off" />
            <span class="hint finance-calc-hint">{{ __('例: 1000+340 / 5000-200 / 100*1.1') }}</span>
          </label>

          <label id="finance-category-field">
            {{ __('支出カテゴリー') }}
            <select name="category" id="finance-category">
              <option value="">{{ __('未分類') }}</option>
              @foreach($expenseCategoryLabels as $slug => $label)
                <option value="{{ $slug }}">{{ $label }}</option>
              @endforeach
            </select>
          </label>

          <label>
            {{ __('メモ') }}
            <input type="text" name="memo" id="finance-memo" placeholder="{{ __('国保、PH送金 など') }}" />
          </label>

          <div class="finance-form-actions">
            <button type="button" class="secondary" data-close-finance-modal>{{ __('キャンセル') }}</button>
            <button type="submit" class="button-link" id="finance-submit-btn">{{ __('保存') }}</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal modal-centered" id="finance-expense-other-modal" hidden>
      <div class="modal-backdrop" data-close-expense-other-modal></div>
      <div class="modal-dialog finance-modal-dialog" role="dialog" aria-labelledby="finance-expense-other-title">
        <div class="modal-header">
          <h2 id="finance-expense-other-title">{{ __('その他の支出カテゴリー') }}</h2>
          <button type="button" class="modal-close" data-close-expense-other-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <div class="finance-expense-other-grid" id="finance-expense-other-grid">
          @foreach($expenseCategoryOther as $slug => $label)
            <div class="finance-expense-other-item{{ isset($expenseCategoryCustom[$slug]) ? ' is-custom' : '' }}">
              <button type="button" class="finance-expense-other-option" data-category="{{ $slug }}">{{ $label }}</button>
              @if(isset($expenseCategoryCustom[$slug]))
                <button
                  type="button"
                  class="finance-expense-other-delete"
                  data-category="{{ $slug }}"
                  aria-label="{{ $label }}{{ __('を削除') }}"
                  title="{{ __('削除') }}"
                >×</button>
              @endif
            </div>
          @endforeach
        </div>
        <form class="finance-expense-other-add" id="finance-expense-other-add-form">
          <label class="finance-expense-other-add-label">
            <span class="finance-expense-other-add-caption">{{ __('カテゴリーを追加') }}</span>
            <input
              type="text"
              id="finance-expense-other-add-input"
              maxlength="40"
              placeholder="{{ __('例：ガス、保険') }}"
              autocomplete="off"
            />
          </label>
          <button type="submit" class="button-link" id="finance-expense-other-add-btn">{{ __('追加') }}</button>
          <p class="hint finance-expense-other-add-error" id="finance-expense-other-add-error" hidden></p>
        </form>
      </div>
    </div>

    <div class="modal modal-centered" id="finance-summary-detail-modal" hidden>
      <div class="modal-backdrop" data-close-summary-detail-modal></div>
      <div class="modal-dialog finance-modal-dialog finance-summary-detail-dialog" role="dialog" aria-labelledby="finance-summary-detail-title">
        <div class="modal-header">
          <h2 id="finance-summary-detail-title">{{ __('サマリー詳細') }}</h2>
          <button type="button" class="modal-close" data-close-summary-detail-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <div class="finance-summary-detail-meta">
          <strong id="finance-summary-detail-total"></strong>
          <span class="hint" id="finance-summary-detail-count"></span>
        </div>
        <div class="finance-summary-detail-categories" id="finance-summary-detail-categories" hidden></div>
        <div class="finance-summary-detail-table-wrap">
          <table class="finance-summary-detail-table">
            <thead>
              <tr>
                <th scope="col">{{ __('日付') }}</th>
                <th scope="col">{{ __('内容') }}</th>
                <th scope="col" class="is-num">{{ __('金額') }}</th>
              </tr>
            </thead>
            <tbody id="finance-summary-detail-body"></tbody>
          </table>
          <p class="hint finance-summary-detail-empty" id="finance-summary-detail-empty" hidden>{{ __('該当はありません。') }}</p>
        </div>
      </div>
    </div>

    <div class="modal modal-centered" id="finance-account-modal" hidden>
      <div class="modal-backdrop" data-close-finance-account-modal></div>
      <div class="modal-dialog finance-modal-dialog" role="dialog" aria-labelledby="finance-account-modal-title">
        <div class="modal-header">
          <h2 id="finance-account-modal-title">{{ __('口座を追加') }}</h2>
          <button type="button" class="modal-close" data-close-finance-account-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <form method="post" action="/finance/accounts" id="finance-account-form" class="modal-form finance-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <input type="hidden" name="account_id" id="finance-account-id-input" value="" />
          <input type="hidden" name="show_in_overview" id="finance-account-show-in-overview" value="0" />

          <label>
            {{ __('口座名') }}
            <input type="text" name="name" id="finance-account-name" required maxlength="100" placeholder="{{ __('楽天銀行、Rakuten など') }}" />
          </label>

          <label id="finance-account-region-wrap">
            {{ __('地域') }}
            <select name="region" id="finance-account-region" required>
              @foreach(\App\Services\FinanceService::REGION_LABELS as $regionKey => $regionLabel)
                <option value="{{ $regionKey }}" @selected($filters['tab'] === $regionKey)>{{ __($regionLabel) }}</option>
              @endforeach
            </select>
          </label>

          <label>
            {{ __('種別') }}
            <select name="kind" id="finance-account-kind" required>
              @foreach(\App\Services\FinanceService::KIND_LABELS as $kindKey => $kindLabel)
                <option value="{{ $kindKey }}">{{ __($kindLabel) }}</option>
              @endforeach
            </select>
          </label>

          <label>
            <span id="finance-account-initial-balance-label">{{ __('開始残高') }}</span>
            <input type="text" inputmode="decimal" class="finance-amount-calc" name="initialBalance" id="finance-account-initial-balance" value="0" autocomplete="off" />
          </label>

          <label>
            {{ __('調整金額') }}
            <input type="text" inputmode="decimal" class="finance-amount-calc" name="adjustmentAmount" id="finance-account-adjustment" value="0" autocomplete="off" />
          </label>

          <label id="finance-account-linked-bank-wrap" hidden>
            {{ __('引落口座（クレカのみ）') }}
            <select name="linkedBankId" id="finance-account-linked-bank">
              <option value="">{{ __('未設定') }}</option>
              @foreach($bankAccounts as $bank)
                <option value="{{ $bank['id'] }}" data-region="{{ $bank['region'] }}">{{ $bank['name'] }}（{{ $bank['regionLabel'] }}）</option>
              @endforeach
            </select>
          </label>

          <div class="finance-form-actions">
            <button type="button" class="secondary" data-close-finance-account-modal>{{ __('キャンセル') }}</button>
            <button type="submit" class="button-link" id="finance-account-submit-btn">{{ __('保存') }}</button>
          </div>
        </form>
        <form
          method="post"
          action=""
          id="finance-account-delete-form"
          class="finance-account-modal-delete"
          hidden
          onsubmit='return confirm(@json(__('この口座を削除しますか？') . "\n" . __('一覧から非表示になります（過去の取引は残ります）。')))'
        >
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <button type="submit" class="text-btn danger">{{ __('この口座を削除') }}</button>
        </form>
      </div>
    </div>

    <div class="modal modal-centered" id="finance-overview-add-modal" hidden>
      <div class="modal-backdrop" data-close-finance-overview-add-modal></div>
      <div class="modal-dialog finance-modal-dialog" role="dialog" aria-labelledby="finance-overview-add-modal-title">
        <div class="modal-header">
          <h2 id="finance-overview-add-modal-title">{{ __('残高カードを追加') }}</h2>
          <button type="button" class="modal-close" data-close-finance-overview-add-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <div class="modal-form finance-form">
          @if(count($unpinnedAccounts) > 0)
            <form method="post" id="finance-overview-pin-form" class="finance-overview-pin-form">
              @csrf
              <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
              <input type="hidden" name="show" value="1" />
              <label>
                {{ __('既存の口座をカードに追加') }}
                <select name="account_id" id="finance-overview-pin-account" required>
                  @foreach($unpinnedAccounts as $account)
                    <option value="{{ $account['id'] }}">{{ $account['kindLabel'] }}: {{ $account['name'] }}</option>
                  @endforeach
                </select>
              </label>
              <button type="submit" class="button-link">{{ __('カードに追加') }}</button>
            </form>
            <p class="hint finance-overview-add-divider">{{ __('または') }}</p>
          @else
            <p class="hint">{{ __('カードに追加できる口座がありません。新しい口座を登録してください。') }}</p>
          @endif
          <button type="button" class="button-link secondary" id="finance-overview-open-new-account">{{ __('新しい口座を登録して追加') }}</button>
          <div class="finance-form-actions">
          <button type="button" class="secondary" data-close-finance-overview-add-modal>{{ __('閉じる') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal modal-centered" id="finance-schedule-modal" hidden>
      <div class="modal-backdrop" data-close-finance-schedule-modal></div>
      <div class="modal-dialog finance-modal-dialog" role="dialog" aria-labelledby="finance-schedule-modal-title">
        <div class="modal-header">
          <h2 id="finance-schedule-modal-title">{{ __('予定') }}</h2>
          <button type="button" class="modal-close" data-close-finance-schedule-modal aria-label="{{ __('閉じる') }}">×</button>
        </div>
        <div class="modal-form finance-form">
          <p class="finance-schedule-account-name" id="finance-schedule-account-name"></p>
          <div class="finance-account-schedule-panel">
            <h3 class="finance-account-schedule-title" id="finance-schedule-list-title">{{ __('予定一覧') }}</h3>
            <div id="finance-schedule-list" class="finance-schedule-list"></div>
          </div>
          <form method="post" action="/finance/accounts/0/schedules" id="finance-schedule-form" class="finance-inline-form finance-schedule-form">
            @csrf
            <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
            <label>
              {{ __('予定日') }}
              <input type="date" name="scheduledDate" id="finance-schedule-date" value="{{ $defaultDate }}" required />
            </label>
            <label>
              {{ __('金額') }}
              <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" id="finance-schedule-amount" required placeholder="1000+340" autocomplete="off" />
            </label>
            <label>
              {{ __('メモ') }}
              <input type="text" name="memo" id="finance-schedule-memo" placeholder="{{ __('給与、カード引落 など') }}" />
            </label>
            <div class="finance-form-actions">
              <button type="button" class="secondary" data-close-finance-schedule-modal>{{ __('閉じる') }}</button>
              <button type="submit" class="button-link">{{ __('予定を追加') }}</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    @include('finance.partials.easy-amount-modal')

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

        function createFinanceEasyAmountButton() {
          const btn = document.createElement('button')
          btn.type = 'button'
          btn.className = 'finance-easy-amount-btn finance-easy-amount-btn--icon text-btn'
          btn.setAttribute('aria-label', '金額を簡単入力')
          btn.setAttribute('title', '簡単入力')
          btn.innerHTML = '<svg class="finance-easy-amount-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="6" rx="1.5"></rect><rect x="14" y="3" width="7" height="6" rx="1.5"></rect><rect x="3" y="11" width="7" height="6" rx="1.5"></rect><rect x="14" y="11" width="7" height="6" rx="1.5"></rect></svg>'
          return btn
        }

        function ensureFinanceAmountEasyButtons(root = document) {
          root.querySelectorAll('.finance-amount-calc').forEach((input) => {
            if (input.closest('.finance-amount-field-wrap')) return
            if (input.disabled) return
            const parent = input.parentElement
            if (!parent) return
            const isCompact = Boolean(input.closest('.finance-list-schedule-form, .finance-card-schedule-form'))
            const wrap = document.createElement('div')
            wrap.className = 'finance-amount-field-wrap' + (isCompact ? ' finance-amount-field-wrap--compact' : '')
            parent.insertBefore(wrap, input)
            wrap.appendChild(input)
            wrap.appendChild(createFinanceEasyAmountButton())
          })
        }

        function initFinanceEasyAmountModal() {
          const modal = document.getElementById('finance-easy-amount-modal')
          const displayEl = document.getElementById('finance-easy-amount-current')
          const titleEl = document.getElementById('finance-easy-amount-modal-title')
          let targetInput = null
          let mode = 'add'

          function parseAmountInputValue(input) {
            const raw = String(input?.value || '').trim()
            if (!raw) return 0
            const result = evaluateAmountExpression(raw)
            if (result !== null) return Math.max(0, result)
            const num = parseFloat(raw.replace(/,/g, ''))
            return Number.isFinite(num) ? Math.max(0, num) : 0
          }

          function formatEasyAmountDisplay(value) {
            return Number(value).toLocaleString('ja-JP', {
              maximumFractionDigits: Number.isInteger(value) ? 0 : 2,
            })
          }

          function getWorkingValue() {
            return targetInput ? parseAmountInputValue(targetInput) : 0
          }

          function updateDisplay() {
            if (displayEl) displayEl.textContent = formatEasyAmountDisplay(getWorkingValue())
          }

          function syncInputFromWorking(value) {
            if (!targetInput) return
            const rounded = Math.round(value * 100) / 100
            targetInput.value = Number.isInteger(rounded) ? String(rounded) : String(rounded)
            targetInput.classList.remove('is-invalid-calc')
            targetInput.setCustomValidity('')
            targetInput.dispatchEvent(new Event('input', { bubbles: true }))
            updateDisplay()
          }

          function updateModeButtons() {
            modal?.querySelectorAll('[data-finance-easy-amount-mode]').forEach((btn) => {
              const isActive = btn.dataset.financeEasyAmountMode === mode
              btn.classList.toggle('is-active', isActive)
              btn.setAttribute('aria-pressed', isActive ? 'true' : 'false')
            })
          }

          function resolveEasyAmountTitle(input) {
            const label = input.closest('label')
            const labelText = label?.querySelector('.finance-quick-field-label, span')?.textContent?.trim()
            if (labelText) return `${labelText} 簡単入力`
            if (input.id === 'finance-quick-to-amount' || input.name === 'toAmount') return '振替先金額 簡単入力'
            return '金額 簡単入力'
          }

          function openEasyAmountModal(input) {
            targetInput = input
            mode = 'add'
            if (titleEl) titleEl.textContent = resolveEasyAmountTitle(input)
            updateModeButtons()
            updateDisplay()
            modal?.removeAttribute('hidden')
          }

          function closeEasyAmountModal() {
            modal?.setAttribute('hidden', '')
            targetInput = null
          }

          function applyEasyAmountDelta(delta) {
            let next = getWorkingValue() + (mode === 'subtract' ? -delta : delta)
            if (next < 0) next = 0
            syncInputFromWorking(next)
          }

          document.body.addEventListener('click', (event) => {
            const btn = event.target.closest('.finance-easy-amount-btn')
            if (!btn) return
            event.preventDefault()
            const input = btn.closest('.finance-amount-field-wrap')?.querySelector('.finance-amount-calc')
            if (input) openEasyAmountModal(input)
          })

          modal?.querySelectorAll('[data-finance-easy-amount-mode]').forEach((btn) => {
            btn.addEventListener('click', () => {
              mode = btn.dataset.financeEasyAmountMode === 'subtract' ? 'subtract' : 'add'
              updateModeButtons()
            })
          })

          modal?.querySelectorAll('[data-finance-easy-amount-delta]').forEach((btn) => {
            btn.addEventListener('click', () => {
              const delta = parseFloat(btn.dataset.financeEasyAmountDelta || '0')
              if (delta > 0) applyEasyAmountDelta(delta)
            })
          })

          document.getElementById('finance-easy-amount-clear')?.addEventListener('click', () => syncInputFromWorking(0))
          document.querySelectorAll('[data-close-finance-easy-amount]').forEach((el) => {
            el.addEventListener('click', closeEasyAmountModal)
          })
        }

        ensureFinanceAmountEasyButtons()
        initFinanceEasyAmountModal()

        const modal = document.getElementById('finance-transaction-modal')
        const form = document.getElementById('finance-transaction-form')
        const quickForm = document.getElementById('finance-quick-entry-form')
        const quickEntrySection = document.getElementById('finance-quick-entry')
        const quickTypeRadios = quickForm?.querySelectorAll('input[name="type"]') || []
        const quickTypeTabs = quickForm?.querySelectorAll('.finance-quick-type-tab') || []
        const quickTransferToAccountField = document.getElementById('finance-quick-to-account-field')
        const quickToAmountWrap = document.getElementById('finance-quick-to-amount-wrap')
        const quickEntryRowMain = document.getElementById('finance-quick-entry-row-main')
        const quickAccountSelect = document.getElementById('finance-quick-account')
        const quickToAccountSelect = document.getElementById('finance-quick-to-account')
        const quickAmountInput = document.getElementById('finance-quick-amount')
        const quickMemoInput = document.getElementById('finance-quick-memo')
        const quickDateInput = document.getElementById('finance-quick-date')
        const quickAccountLabel = document.getElementById('finance-quick-account-label')
        const quickAmountLabel = document.getElementById('finance-quick-amount-label')
        const quickSubmitBtn = document.getElementById('finance-quick-submit')
        const expenseCategories = document.getElementById('finance-expense-categories')
        const quickCategoryInput = document.getElementById('finance-quick-category')
        const expenseCategoryBtns = expenseCategories?.querySelectorAll('.finance-expense-category-btn') || []
        const expenseOtherModal = document.getElementById('finance-expense-other-modal')
        const expenseOtherBtn = document.getElementById('finance-expense-category-other')
        const categoryField = document.getElementById('finance-category-field')
        const categorySelect = document.getElementById('finance-category')
        const expenseCategoryOtherKeys = @json(array_keys($expenseCategoryOther));
        let expenseCategoryOtherKeyList = Array.isArray(expenseCategoryOtherKeys)
          ? expenseCategoryOtherKeys.slice()
          : Object.keys(expenseCategoryOtherKeys || {});
        const transactionTypeHint = document.getElementById('finance-transaction-type-hint')
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

        ;(function initFinanceTxDateFilters() {
          const form = document.getElementById('finance-tx-filter-form')
          const yearEl = document.getElementById('finance-tx-year')
          const monthEl = document.getElementById('finance-tx-month')
          const dayEl = document.getElementById('finance-tx-day')
          const periodEl = document.getElementById('finance-tx-period')
          if (!form || !yearEl || !monthEl || !dayEl || !periodEl) return

          function syncPeriodAndSubmit() {
            periodEl.value = yearEl.value + '-' + monthEl.value
            form.submit()
          }

          yearEl.addEventListener('change', syncPeriodAndSubmit)
          monthEl.addEventListener('change', syncPeriodAndSubmit)
          dayEl.addEventListener('change', () => form.submit())
        })()

        ;(function initFinanceTxColumnResize() {
          const table = document.getElementById('finance-tx-table')
          if (!table) return

          const storageKey = 'finance-tx-col-widths-v1'
          const minWidth = 48
          const cols = Array.from(table.querySelectorAll('colgroup col[data-col]'))
          const defaults = {}
          cols.forEach((col) => {
            defaults[col.dataset.col] = parseInt(col.style.width, 10) || minWidth
          })

          function applyWidths(widths) {
            let total = 0
            cols.forEach((col) => {
              const key = col.dataset.col
              const width = Math.max(minWidth, Number(widths[key]) || defaults[key] || minWidth)
              col.style.width = width + 'px'
              total += width
            })
            table.style.width = total + 'px'
          }

          function loadWidths() {
            try {
              const raw = localStorage.getItem(storageKey)
              if (!raw) return { ...defaults }
              const parsed = JSON.parse(raw)
              return { ...defaults, ...parsed }
            } catch (_) {
              return { ...defaults }
            }
          }

          function saveWidths(widths) {
            try {
              localStorage.setItem(storageKey, JSON.stringify(widths))
            } catch (_) {}
          }

          const current = loadWidths()
          applyWidths(current)

          table.querySelectorAll('.finance-col-resize').forEach((handle) => {
            handle.addEventListener('pointerdown', (event) => {
              event.preventDefault()
              event.stopPropagation()

              const th = handle.closest('th')
              const key = th?.dataset.col
              const col = key ? table.querySelector(`colgroup col[data-col="${key}"]`) : null
              if (!col) return

              const startX = event.clientX
              const startWidth = col.getBoundingClientRect().width
              handle.classList.add('is-active')
              document.body.classList.add('is-finance-col-resizing')
              handle.setPointerCapture?.(event.pointerId)

              function onMove(moveEvent) {
                const next = Math.max(minWidth, Math.round(startWidth + (moveEvent.clientX - startX)))
                current[key] = next
                applyWidths(current)
              }

              function onUp() {
                handle.classList.remove('is-active')
                document.body.classList.remove('is-finance-col-resizing')
                saveWidths(current)
                window.removeEventListener('pointermove', onMove)
                window.removeEventListener('pointerup', onUp)
                window.removeEventListener('pointercancel', onUp)
              }

              window.addEventListener('pointermove', onMove)
              window.addEventListener('pointerup', onUp)
              window.addEventListener('pointercancel', onUp)
            })
          })
        })()

        ;(function initFinanceMemoBubble() {
          const bubble = document.getElementById('finance-memo-bubble')
          if (!bubble) return

          let pinnedTrigger = null
          let hoverTrigger = null

          function escapeHtml(text) {
            return String(text)
              .replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
          }

          function placeBubble(trigger) {
            const rect = trigger.getBoundingClientRect()
            const gap = 10
            bubble.removeAttribute('hidden')
            bubble.classList.remove('is-below')
            bubble.style.left = '0px'
            bubble.style.top = '0px'

            const bubbleRect = bubble.getBoundingClientRect()
            let left = rect.left
            let top = rect.top - bubbleRect.height - gap
            if (top < 8) {
              top = rect.bottom + gap
              bubble.classList.add('is-below')
            }
            left = Math.min(Math.max(8, left), window.innerWidth - bubbleRect.width - 8)
            bubble.style.left = left + 'px'
            bubble.style.top = top + 'px'
          }

          function showBubble(trigger, pinned) {
            const memo = trigger.dataset.memo || ''
            if (!memo) return
            bubble.innerHTML = escapeHtml(memo)
            bubble.classList.toggle('is-pinned', pinned)
            placeBubble(trigger)
            if (pinned) {
              pinnedTrigger = trigger
              hoverTrigger = null
            } else {
              hoverTrigger = trigger
            }
          }

          function hideBubble(force) {
            if (!force && pinnedTrigger) return
            pinnedTrigger = null
            hoverTrigger = null
            bubble.setAttribute('hidden', '')
            bubble.classList.remove('is-pinned', 'is-below')
            bubble.innerHTML = ''
          }

          document.querySelectorAll('.finance-transaction-memo-trigger').forEach((trigger) => {
            trigger.addEventListener('mouseenter', () => {
              if (pinnedTrigger) return
              showBubble(trigger, false)
            })
            trigger.addEventListener('mouseleave', () => {
              if (pinnedTrigger) return
              if (hoverTrigger === trigger) hideBubble(true)
            })
            trigger.addEventListener('click', (event) => {
              event.preventDefault()
              event.stopPropagation()
              if (pinnedTrigger === trigger) {
                hideBubble(true)
                return
              }
              showBubble(trigger, true)
            })
          })

          document.addEventListener('click', (event) => {
            if (!pinnedTrigger) return
            if (event.target.closest('.finance-transaction-memo-trigger') === pinnedTrigger) return
            if (event.target.closest('#finance-memo-bubble')) return
            hideBubble(true)
          })

          document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') hideBubble(true)
          })

          window.addEventListener('scroll', () => {
            if (pinnedTrigger) placeBubble(pinnedTrigger)
            else if (hoverTrigger) placeBubble(hoverTrigger)
          }, true)
        })()

        settingsToggle?.addEventListener('click', () => {
          if (!settingsPanel) return
          const hidden = settingsPanel.hasAttribute('hidden')
          if (hidden) {
            settingsPanel.removeAttribute('hidden')
            settingsToggle.setAttribute('aria-expanded', 'true')
            settingsToggle.textContent = @json(__('口座設定を閉じる'));
            settingsToggle.classList.add('is-active')
            settingsPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
          } else {
            settingsPanel.setAttribute('hidden', '')
            settingsToggle.setAttribute('aria-expanded', 'false')
            settingsToggle.textContent = @json(__('口座設定'));
            settingsToggle.classList.remove('is-active')
          }
        })

        function closeModal() {
          modal?.setAttribute('hidden', '')
        }

        function syncTransferVisibility() {
          const type = form.querySelector('input[name="type"]:checked')?.value || 'expense'
          const isTransfer = type === 'transfer'
          const isExpense = type === 'expense'
          transferFields.hidden = !isTransfer
          toAccountSelect.required = isTransfer
          if (categoryField) categoryField.hidden = !isExpense
          if (!isExpense && categorySelect) categorySelect.value = ''
          syncCrossCurrency()
        }

        function syncCrossCurrency() {
          const fromOption = accountSelect.selectedOptions[0]
          const toOption = toAccountSelect.selectedOptions[0]
          if (!fromOption || !toOption) return
          const cross = fromOption.dataset.currency !== toOption.dataset.currency
          toAmountWrap.hidden = !cross
        }

        typeRadios.forEach((radio) => {
          radio.addEventListener('change', () => {
            syncTransferVisibility()
            const type = radio.value
            if (!transactionIdInput.value && typeTitles[type]) {
              modalTitle.textContent = typeTitles[type]
            }
            syncTransactionTypeHint()
          })
        })
        accountSelect?.addEventListener('change', syncCrossCurrency)
        toAccountSelect?.addEventListener('change', syncCrossCurrency)

        const defaultTransactionAccountId = @json(
          $filters['accountId']
            ?? ($filters['tab'] === 'jp' ? (collect($jpAccounts)->first()['id'] ?? null) : null)
            ?? ($filters['tab'] === 'ph' ? (collect($phAccounts)->first()['id'] ?? null) : null)
        );

        function applyDefaultTransactionAccount() {
          if (defaultTransactionAccountId && accountSelect) {
            accountSelect.value = String(defaultTransactionAccountId)
          }
          if (defaultTransactionAccountId && quickAccountSelect) {
            quickAccountSelect.value = String(defaultTransactionAccountId)
          }
        }

        const quickSubmitLabels = {
          income: '入金を登録',
          expense: '支出を登録',
          transfer: '振替を登録',
        }

        const quickAccountLabels = {
          income: '入金先',
          expense: '支出元',
          transfer: '振替元',
        }

        function setQuickExpenseCategory(category) {
          if (quickCategoryInput) quickCategoryInput.value = category || ''
          const isOther = Boolean(category && expenseCategoryOtherKeyList.includes(category))
          expenseCategoryBtns.forEach((btn) => {
            const btnCategory = btn.dataset.category
            if (btnCategory === '__other__') {
              btn.classList.toggle('is-active', isOther)
              if (isOther) {
                const option = expenseOtherModal?.querySelector(
                  `.finance-expense-other-option[data-category="${CSS.escape(category)}"]`
                )
                const label = option?.textContent.trim() || @json(__('その他'));
                btn.dataset.selectedLabel = label
                btn.textContent = label
              } else {
                delete btn.dataset.selectedLabel
                btn.textContent = @json(__('その他'));
              }
              return
            }
            btn.classList.toggle('is-active', btnCategory === category)
          })
        }

        function clearQuickExpenseCategory() {
          if (expenseOtherBtn) {
            delete expenseOtherBtn.dataset.selectedLabel
            expenseOtherBtn.textContent = @json(__('その他'));
          }
          setQuickExpenseCategory('')
        }

        function openExpenseOtherModal() {
          const errorEl = document.getElementById('finance-expense-other-add-error')
          const inputEl = document.getElementById('finance-expense-other-add-input')
          if (errorEl) {
            errorEl.hidden = true
            errorEl.textContent = ''
          }
          if (inputEl) inputEl.value = ''
          expenseOtherModal?.removeAttribute('hidden')
        }

        function closeExpenseOtherModal() {
          expenseOtherModal?.setAttribute('hidden', '')
        }

        function appendExpenseCategoryOption(slug, label) {
          const grid = document.getElementById('finance-expense-other-grid')
          if (grid && !grid.querySelector(`.finance-expense-other-option[data-category="${CSS.escape(slug)}"]`)) {
            const item = document.createElement('div')
            item.className = 'finance-expense-other-item is-custom'
            const btn = document.createElement('button')
            btn.type = 'button'
            btn.className = 'finance-expense-other-option'
            btn.dataset.category = slug
            btn.textContent = label
            const del = document.createElement('button')
            del.type = 'button'
            del.className = 'finance-expense-other-delete'
            del.dataset.category = slug
            del.setAttribute('aria-label', `${label}を削除`)
            del.title = '削除'
            del.textContent = '×'
            item.appendChild(btn)
            item.appendChild(del)
            grid.appendChild(item)
            bindExpenseOtherOption(btn)
            bindExpenseOtherDelete(del)
          }
          if (categorySelect && !categorySelect.querySelector(`option[value="${CSS.escape(slug)}"]`)) {
            const option = document.createElement('option')
            option.value = slug
            option.textContent = label
            categorySelect.appendChild(option)
          }
          if (!expenseCategoryOtherKeyList.includes(slug)) {
            expenseCategoryOtherKeyList.push(slug)
          }
        }

        function removeExpenseCategoryOption(slug) {
          document
            .querySelectorAll(`#finance-expense-other-grid .finance-expense-other-item .finance-expense-other-option[data-category="${CSS.escape(slug)}"]`)
            .forEach((btn) => btn.closest('.finance-expense-other-item')?.remove())
          categorySelect?.querySelector(`option[value="${CSS.escape(slug)}"]`)?.remove()
          expenseCategoryOtherKeyList = expenseCategoryOtherKeyList.filter((key) => key !== slug)
          if (quickCategoryInput?.value === slug) {
            clearQuickExpenseCategory()
          }
          if (categorySelect?.value === slug) {
            categorySelect.value = ''
          }
        }

        function bindExpenseOtherOption(btn) {
          if (!btn || btn.dataset.bound === '1') return
          btn.dataset.bound = '1'
          btn.addEventListener('click', () => {
            setQuickExpenseCategory(btn.dataset.category)
            closeExpenseOtherModal()
          })
        }

        function bindExpenseOtherDelete(btn) {
          if (!btn || btn.dataset.bound === '1') return
          btn.dataset.bound = '1'
          btn.addEventListener('click', async (event) => {
            event.preventDefault()
            event.stopPropagation()
            const slug = btn.dataset.category
            if (!slug) return
            const label = btn.closest('.finance-expense-other-item')
              ?.querySelector('.finance-expense-other-option')
              ?.textContent
              ?.trim() || slug
            if (!window.confirm(`「${label}」を削除しますか？`)) return
            btn.disabled = true
            try {
              const response = await fetch(`/finance/categories/${encodeURIComponent(slug)}/delete`, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'Accept': 'application/json',
                  'X-CSRF-TOKEN': csrfToken || '',
                },
              })
              const data = await response.json().catch(() => ({}))
              if (!response.ok || !data?.ok) {
                throw new Error(data?.message || 'カテゴリーの削除に失敗しました')
              }
              removeExpenseCategoryOption(slug)
            } catch (error) {
              window.alert(error?.message || 'カテゴリーの削除に失敗しました')
              btn.disabled = false
            }
          })
        }

        function syncQuickTypeTabs() {
          const type = quickForm?.querySelector('input[name="type"]:checked')?.value || 'expense'
          const isExpense = type === 'expense'
          quickTypeTabs.forEach((tab) => {
            const input = tab.querySelector('input[name="type"]')
            tab.classList.toggle('is-active', input?.value === type)
          })
          if (expenseCategories) expenseCategories.hidden = !isExpense
          if (!isExpense) {
            clearQuickExpenseCategory()
            closeExpenseOtherModal()
          }
          if (quickSubmitBtn) quickSubmitBtn.textContent = quickSubmitLabels[type] || '登録'
          if (quickAccountLabel) quickAccountLabel.textContent = quickAccountLabels[type] || '口座'
          if (quickAmountLabel) quickAmountLabel.textContent = type === 'transfer' ? '振替元金額' : '金額'
          quickSubmitBtn?.classList.toggle('is-income', type === 'income')
          quickSubmitBtn?.classList.toggle('is-expense', type === 'expense')
          quickSubmitBtn?.classList.toggle('is-transfer', type === 'transfer')
        }

        function syncQuickCrossCurrency() {
          // 振替時は振替先金額を常に1行表示する（異通貨時は入力必須の目安）
          const type = quickForm?.querySelector('input[name="type"]:checked')?.value || 'expense'
          if (type !== 'transfer') {
            if (quickToAmountWrap) quickToAmountWrap.hidden = true
            quickEntryRowMain?.classList.remove('is-cross-currency')
            return
          }
          const fromOption = quickAccountSelect?.selectedOptions[0]
          const toOption = quickToAccountSelect?.selectedOptions[0]
          const cross = Boolean(
            fromOption && toOption && fromOption.dataset.currency !== toOption.dataset.currency
          )
          if (quickToAmountWrap) quickToAmountWrap.hidden = false
          quickEntryRowMain?.classList.toggle('is-cross-currency', cross)
        }

        function syncQuickTransferVisibility() {
          const type = quickForm?.querySelector('input[name="type"]:checked')?.value || 'expense'
          const isTransfer = type === 'transfer'
          if (quickTransferToAccountField) quickTransferToAccountField.hidden = !isTransfer
          if (quickToAccountSelect) quickToAccountSelect.required = isTransfer
          quickEntryRowMain?.classList.toggle('is-transfer', isTransfer)
          if (!isTransfer && quickToAmountWrap) quickToAmountWrap.hidden = true
          syncQuickCrossCurrency()
          syncQuickTypeTabs()
        }

        function presetQuickEntry(type = 'expense', accountId = null) {
          if (!quickForm) return
          const allowedTypes = ['income', 'expense', 'transfer']
          const resolvedType = allowedTypes.includes(type) ? type : 'expense'
          const typeRadio = quickForm.querySelector(`input[name="type"][value="${resolvedType}"]`)
          if (typeRadio) typeRadio.checked = true
          if (accountId && quickAccountSelect) {
            quickAccountSelect.value = String(accountId)
          } else {
            applyDefaultTransactionAccount()
          }
          if (quickDateInput && !quickDateInput.value) {
            quickDateInput.value = @json($defaultDate);
          }
          syncQuickTransferVisibility()
          quickEntrySection?.scrollIntoView({ behavior: 'smooth', block: 'start' })
          window.setTimeout(() => quickAmountInput?.focus(), 120)
        }

        quickTypeRadios.forEach((radio) => {
          radio.addEventListener('change', syncQuickTransferVisibility)
        })
        quickAccountSelect?.addEventListener('change', syncQuickCrossCurrency)
        quickToAccountSelect?.addEventListener('change', syncQuickCrossCurrency)
        expenseCategoryBtns.forEach((btn) => {
          btn.addEventListener('click', () => {
            const category = btn.dataset.category
            if (category === '__other__') {
              openExpenseOtherModal()
              return
            }
            setQuickExpenseCategory(category)
          })
        })
        document.querySelectorAll('.finance-expense-other-option').forEach((btn) => {
          bindExpenseOtherOption(btn)
        })
        document.querySelectorAll('.finance-expense-other-delete').forEach((btn) => {
          bindExpenseOtherDelete(btn)
        })
        document.querySelectorAll('[data-close-expense-other-modal]').forEach((el) => {
          el.addEventListener('click', closeExpenseOtherModal)
        })
        document.getElementById('finance-expense-other-add-form')?.addEventListener('submit', async (event) => {
          event.preventDefault()
          const inputEl = document.getElementById('finance-expense-other-add-input')
          const errorEl = document.getElementById('finance-expense-other-add-error')
          const submitBtn = document.getElementById('finance-expense-other-add-btn')
          const label = String(inputEl?.value || '').trim()
          if (errorEl) {
            errorEl.hidden = true
            errorEl.textContent = ''
          }
          if (!label) {
            if (errorEl) {
              errorEl.textContent = @json(__('カテゴリー名を入力してください'));
              errorEl.hidden = false
            }
            inputEl?.focus()
            return
          }
          if (submitBtn) submitBtn.disabled = true
          try {
            const response = await fetch('/finance/categories', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
              },
              body: JSON.stringify({ label }),
            })
            const data = await response.json().catch(() => ({}))
            if (!response.ok || !data?.ok || !data?.category) {
              throw new Error(data?.message || 'カテゴリーの追加に失敗しました')
            }
            appendExpenseCategoryOption(data.category.slug, data.category.label)
            setQuickExpenseCategory(data.category.slug)
            if (inputEl) inputEl.value = ''
            closeExpenseOtherModal()
          } catch (error) {
            if (errorEl) {
              errorEl.textContent = error?.message || 'カテゴリーの追加に失敗しました'
              errorEl.hidden = false
            }
          } finally {
            if (submitBtn) submitBtn.disabled = false
          }
        })
        document.querySelectorAll('[data-quick-type]').forEach((btn) => {
          btn.addEventListener('click', (event) => {
            event.stopPropagation()
            presetQuickEntry(btn.dataset.quickType, btn.dataset.accountId)
          })
        })
        syncQuickTransferVisibility()

        const typeHints = {
          income: '入金を登録すると、選んだ口座の残高にすぐ加算されます（給与・振込受取など）。',
          expense: '支出を登録すると、選んだ口座の残高からすぐ差し引かれます。クレカ利用は口座でクレカを選び、メモに内容を書きます。',
          transfer: '口座間の移動です。送金元から減り、送金先に加わります。',
        }

        const typeTitles = {
          income: '入金を登録',
          expense: '支出を登録',
          transfer: '振替・送金を登録',
        }

        function syncTransactionTypeHint() {
          const type = form.querySelector('input[name="type"]:checked')?.value || 'expense'
          if (transactionTypeHint) {
            transactionTypeHint.textContent = typeHints[type] || ''
          }
        }

        function openAddModal(presetType = 'expense') {
          presetQuickEntry(presetType)
        }

        function openEditModal(data) {
          modalTitle.textContent = @json(__('取引を編集'));
          submitBtn.textContent = @json(__('更新'));
          transactionIdInput.value = String(data.id)
          form.action = `/finance/${data.id}/update`
          form.querySelector('#finance-date').value = data.transactionDate
          form.querySelector('#finance-amount').value = data.amount
          form.querySelector('#finance-memo').value = data.memo || ''
          form.querySelector(`input[name="type"][value="${data.type}"]`).checked = true
          accountSelect.value = String(data.accountId)
          if (data.toAccountId) toAccountSelect.value = String(data.toAccountId)
          if (data.toAmount != null) form.querySelector('#finance-to-amount').value = data.toAmount
          if (categorySelect) categorySelect.value = data.category || ''
          syncTransferVisibility()
          modal?.removeAttribute('hidden')
        }

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

        const summaryDetails = @json($summaryDetails);
        const summaryDetailModal = document.getElementById('finance-summary-detail-modal')
        const summaryDetailTitle = document.getElementById('finance-summary-detail-title')
        const summaryDetailTotal = document.getElementById('finance-summary-detail-total')
        const summaryDetailCount = document.getElementById('finance-summary-detail-count')
        const summaryDetailCategories = document.getElementById('finance-summary-detail-categories')
        const summaryDetailBody = document.getElementById('finance-summary-detail-body')
        const summaryDetailEmpty = document.getElementById('finance-summary-detail-empty')
        let summaryDetailKey = null
        let summaryDetailCategoryFilter = 'all'

        const summaryDetailTitles = {
          income: @json(__('収入（入金）の内訳')),
          expense: @json(__('支出の内訳')),
          net: @json(__('収支の内訳')),
          transferOut: @json(__('振替出の内訳')),
          transferIn: @json(__('振替入の内訳')),
        }
        const summaryDetailI18n = {
          detailFallback: @json(__('サマリー詳細')),
          all: @json(__('すべて')),
          count: @json(__(':count件')),
          netLine: @json(__('収支 :net（収入 :income / 支出 :expense）')),
          noMemo: @json(__('（メモなし）')),
        }

        function formatSummaryMoney(amount, currency) {
          const prefix = currency === 'PHP' ? '₱' : '¥'
          const decimals = currency === 'PHP' ? 2 : 0
          return prefix + Number(amount || 0).toLocaleString('ja-JP', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
          })
        }

        function escapeHtml(value) {
          return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
        }

        function getSummaryDetailSection(key) {
          return summaryDetails?.[key] || { total: 0, items: [], categories: [] }
        }

        function filteredSummaryDetailItems(section) {
          const items = Array.isArray(section.items) ? section.items : []
          if (summaryDetailKey !== 'expense' || summaryDetailCategoryFilter === 'all') {
            return items
          }
          return items.filter((item) => String(item.category ?? '') === summaryDetailCategoryFilter)
        }

        function renderSummaryDetailCategories(section) {
          if (!summaryDetailCategories) return
          if (summaryDetailKey !== 'expense') {
            summaryDetailCategories.hidden = true
            summaryDetailCategories.innerHTML = ''
            return
          }
          const categories = Array.isArray(section.categories) ? section.categories : []
          const currency = summaryDetails?.currency || 'JPY'
          const chips = [
            {
              slug: 'all',
              label: summaryDetailI18n.all,
              total: section.total || 0,
              count: Array.isArray(section.items) ? section.items.length : 0,
            },
            ...categories,
          ]
          summaryDetailCategories.innerHTML = chips.map((chip) => {
            const slug = String(chip.slug ?? '')
            const active = summaryDetailCategoryFilter === slug
              || (summaryDetailCategoryFilter === 'all' && slug === 'all')
            return `<button type="button" class="finance-summary-detail-category-chip${active ? ' is-active' : ''}" data-summary-category="${escapeHtml(slug)}">
              <span class="finance-summary-detail-category-label">${escapeHtml(chip.label)}</span>
              <span class="finance-summary-detail-category-meta">${escapeHtml(formatSummaryMoney(chip.total, currency))} · ${escapeHtml(summaryDetailI18n.count.replace(':count', String(Number(chip.count || 0))))}</span>
            </button>`
          }).join('')
          summaryDetailCategories.hidden = false
        }

        function renderSummaryDetailList() {
          const section = getSummaryDetailSection(summaryDetailKey)
          const currency = summaryDetails?.currency || 'JPY'
          const items = filteredSummaryDetailItems(section)
          const total = items.reduce((sum, item) => sum + Number(item.amount || 0), 0)

          if (summaryDetailTitle) {
            summaryDetailTitle.textContent = summaryDetailTitles[summaryDetailKey] || summaryDetailI18n.detailFallback
          }
          if (summaryDetailTotal) {
            const tone = summaryDetailKey === 'income' || (summaryDetailKey === 'net' && total >= 0)
              ? 'income'
              : (summaryDetailKey === 'expense' || (summaryDetailKey === 'net' && total < 0) ? 'expense' : '')
            summaryDetailTotal.className = tone
            if (summaryDetailKey === 'net' && summaryDetailCategoryFilter === 'all') {
              summaryDetailTotal.textContent = summaryDetailI18n.netLine
                .replace(':net', formatSummaryMoney(section.total || 0, currency))
                .replace(':income', formatSummaryMoney(section.income || 0, currency))
                .replace(':expense', formatSummaryMoney(section.expense || 0, currency))
            } else {
              summaryDetailTotal.textContent = formatSummaryMoney(total, currency)
            }
          }
          if (summaryDetailCount) {
            summaryDetailCount.textContent = summaryDetailI18n.count.replace(':count', String(items.length))
          }

          renderSummaryDetailCategories(section)

          if (!summaryDetailBody) return
          if (items.length === 0) {
            summaryDetailBody.innerHTML = ''
            if (summaryDetailEmpty) summaryDetailEmpty.hidden = false
            return
          }
          if (summaryDetailEmpty) summaryDetailEmpty.hidden = true
          summaryDetailBody.innerHTML = items.map((item) => {
            const accountText = item.type === 'transfer'
              ? `${item.accountName || ''} → ${item.toAccountName || ''}`
              : (item.accountName || '')
            const badges = []
            if (item.typeLabel) badges.push(`<span class="finance-type-badge">${escapeHtml(item.typeLabel)}</span>`)
            if (item.categoryLabel) badges.push(`<span class="finance-category-badge">${escapeHtml(item.categoryLabel)}</span>`)
            const memo = item.memo ? escapeHtml(item.memo) : `<span class="hint">${escapeHtml(summaryDetailI18n.noMemo)}</span>`
            const amountPrefix = item.type === 'expense' ? '−' : (item.type === 'income' ? '+' : '')
            return `<tr>
              <td>${escapeHtml(item.transactionDate || '')}</td>
              <td>
                <div class="finance-summary-detail-content">
                  <div class="finance-summary-detail-badges">${badges.join('')}</div>
                  <div class="finance-summary-detail-account">${escapeHtml(accountText)}</div>
                  <div class="finance-summary-detail-memo">${memo}</div>
                </div>
              </td>
              <td class="is-num">${amountPrefix}${escapeHtml(formatSummaryMoney(item.amount, item.currency || currency))}</td>
            </tr>`
          }).join('')
        }

        function openSummaryDetailModal(key) {
          if (!summaryDetails?.[key]) return
          summaryDetailKey = key
          summaryDetailCategoryFilter = 'all'
          renderSummaryDetailList()
          summaryDetailModal?.removeAttribute('hidden')
        }

        function closeSummaryDetailModal() {
          summaryDetailModal?.setAttribute('hidden', '')
        }

        document.querySelectorAll('[data-summary-detail]').forEach((btn) => {
          btn.addEventListener('click', () => openSummaryDetailModal(btn.dataset.summaryDetail))
        })
        document.querySelectorAll('[data-close-summary-detail-modal]').forEach((el) => {
          el.addEventListener('click', closeSummaryDetailModal)
        })
        summaryDetailCategories?.addEventListener('click', (event) => {
          const chip = event.target.closest('[data-summary-category]')
          if (!chip) return
          summaryDetailCategoryFilter = chip.dataset.summaryCategory ?? 'all'
          renderSummaryDetailList()
        })

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
        const accountShowInOverviewInput = document.getElementById('finance-account-show-in-overview')
        const accountDeleteForm = document.getElementById('finance-account-delete-form')
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

        function syncAccountInitialBalanceLabel() {
          const label = document.getElementById('finance-account-initial-balance-label')
          const isCreditCard = accountKindSelect?.value === 'credit_card'
          if (label) label.textContent = isCreditCard ? '利用額（開始）' : '開始残高'
        }

        function openAddAccountModal(options = {}) {
          const showInOverview = Boolean(options.showInOverview)
          accountModalTitle.textContent = showInOverview ? @json(__('残高カード用の口座を登録')) : @json(__('口座を追加'));
          accountSubmitBtn.textContent = @json(__('登録'));
          accountIdInput.value = ''
          accountForm.action = '/finance/accounts'
          if (accountDeleteForm) {
            accountDeleteForm.hidden = true
            accountDeleteForm.action = ''
          }
          if (accountShowInOverviewInput) accountShowInOverviewInput.value = showInOverview ? '1' : '0'
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
          accountKindSelect.value = options.kind || 'bank'
          if (accountLinkedBankSelect) accountLinkedBankSelect.value = ''
          filterLinkedBankOptions(accountRegionSelect?.value || 'jp')
          syncAccountLinkedBankVisibility()
          syncAccountInitialBalanceLabel()
          accountModal?.removeAttribute('hidden')
        }

        function openEditAccountModal(data) {
          accountModalTitle.textContent = @json(__('口座を編集'));
          accountSubmitBtn.textContent = @json(__('更新'));
          accountIdInput.value = String(data.id)
          if (accountShowInOverviewInput) accountShowInOverviewInput.value = '0'
          accountForm.action = `/finance/accounts/${data.id}/update`
          if (accountDeleteForm) {
            accountDeleteForm.hidden = false
            accountDeleteForm.action = `/finance/accounts/${data.id}/delete`
          }
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
          syncAccountInitialBalanceLabel()
          accountModal?.removeAttribute('hidden')
        }

        openAccountBtn?.addEventListener('click', () => openAddAccountModal())
        accountKindSelect?.addEventListener('change', () => {
          syncAccountLinkedBankVisibility()
          syncAccountInitialBalanceLabel()
        })
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

        document.querySelectorAll('.finance-edit-account-card-btn').forEach((btn) => {
          btn.addEventListener('click', (event) => {
            event.preventDefault()
            event.stopPropagation()
            const host = btn.closest('[data-account]')
            if (host) openAccountEditorFromElement(host)
          })
        })

        const overviewAddModal = document.getElementById('finance-overview-add-modal')
        const overviewPinForm = document.getElementById('finance-overview-pin-form')
        const overviewPinAccount = document.getElementById('finance-overview-pin-account')

        function closeOverviewAddModal() {
          overviewAddModal?.setAttribute('hidden', '')
        }

        document.getElementById('finance-open-overview-add')?.addEventListener('click', () => {
          overviewAddModal?.removeAttribute('hidden')
        })
        document.querySelectorAll('[data-close-finance-overview-add-modal]').forEach((el) => {
          el.addEventListener('click', closeOverviewAddModal)
        })
        document.getElementById('finance-overview-open-new-account')?.addEventListener('click', () => {
          closeOverviewAddModal()
          openAddAccountModal({ showInOverview: true })
        })
        overviewPinForm?.addEventListener('submit', (event) => {
          const accountId = overviewPinAccount?.value
          if (!accountId) return
          overviewPinForm.action = `/finance/accounts/${accountId}/overview`
        })
        document.querySelectorAll('.finance-balance-overview-account').forEach((card) => {
          card.addEventListener('click', (event) => {
            if (event.target.closest('.finance-balance-overview-remove-form')) return
            if (!card.dataset.account) return
            openEditAccountModal(JSON.parse(card.dataset.account))
          })
          card.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return
            if (event.target.closest('.finance-balance-overview-remove-form')) return
            event.preventDefault()
            if (!card.dataset.account) return
            openEditAccountModal(JSON.parse(card.dataset.account))
          })
        })

        const accountsView = document.getElementById('finance-accounts-view')
        const viewToggleButtons = document.querySelectorAll('[data-accounts-view]')
        const ACCOUNTS_VIEW_KEY = 'finance-accounts-view-v2'

        function setAccountsView(view) {
          if (!accountsView) return
          const mode = view === 'cards' ? 'cards' : 'list'
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
          setAccountsView(savedView === 'cards' ? 'cards' : 'list')
        } catch (_) {
          setAccountsView('list')
        }

        function openAccountEditorFromElement(el) {
          if (!el?.dataset?.account) return
          openEditAccountModal(JSON.parse(el.dataset.account))
        }

        accountsView?.addEventListener('click', (event) => {
          if (event.target.closest('.finance-account-filter-link')) return
          if (event.target.closest('.finance-account-delete-form')) return
          if (event.target.closest('.finance-account-drag-handle')) return
          if (event.target.closest('.finance-account-schedule-btn')) return
          if (event.target.closest('.finance-card-schedule-form')) return
          if (event.target.closest('.finance-list-schedule-form')) return
          if (event.target.closest('.finance-edit-account-card-btn')) return
          if (event.target.closest('.finance-account-list-actions')) return
          const item = event.target.closest('.finance-account-card-body, .finance-account-list-row')
          if (item) {
            openAccountEditorFromElement(item.closest('[data-account]') || item)
          }
        })

        accountsView?.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' && event.key !== ' ') return
          if (event.target.closest('.finance-account-list-actions, .finance-list-schedule-form')) return
          const item = event.target.closest('.finance-account-card-body, .finance-account-list-row')
          if (!item) return
          event.preventDefault()
          openAccountEditorFromElement(item.closest('[data-account]') || item)
        })

        function scrollToFinanceTransactions() {
          const section = document.getElementById('finance-transactions')
          if (!section) return
          section.scrollIntoView({ behavior: 'smooth', block: 'start' })
          section.classList.add('is-highlight')
          window.setTimeout(() => section.classList.remove('is-highlight'), 1200)
        }

        if (window.location.hash === '#finance-transactions') {
          window.setTimeout(scrollToFinanceTransactions, 100)
        }

        document.querySelectorAll('[data-finance-account-filter]').forEach((link) => {
          link.addEventListener('click', (event) => {
            const linkUrl = new URL(link.href, window.location.origin)
            const currentUrl = new URL(window.location.href)
            if (linkUrl.pathname === currentUrl.pathname && linkUrl.search === currentUrl.search) {
              event.preventDefault()
              scrollToFinanceTransactions()
            }
          })
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
            scheduleList.innerHTML = `<p class="hint">${@json(__('登録された予定はありません。'))}</p>`
          } else {
            schedules.forEach((schedule) => {
              const row = document.createElement('div')
              row.className = 'finance-account-schedule-item'
              row.innerHTML = `
                <form method="post" action="/finance/schedules/${schedule.id}/update" class="finance-inline-form finance-schedule-edit-form">
                  <input type="hidden" name="_token" value="${csrfToken || ''}" />
                  <input type="hidden" name="returnTo" value="${scheduleReturnTo}" />
                  <label>${@json(__('予定日'))} <input type="date" name="scheduledDate" value="${schedule.scheduledDate}" required /></label>
                  <label>${@json(__('金額'))} <input type="text" inputmode="decimal" class="finance-amount-calc" name="amount" value="${schedule.amount}" required autocomplete="off" /></label>
                  <label>${@json(__('メモ'))} <input type="text" name="memo" value="${schedule.memo || ''}" /></label>
                  <button type="submit" class="text-btn">${@json(__('更新'))}</button>
                </form>
                <form method="post" action="/finance/schedules/${schedule.id}/delete" class="finance-inline-form finance-schedule-delete-form">
                  <input type="hidden" name="_token" value="${csrfToken || ''}" />
                  <input type="hidden" name="returnTo" value="${scheduleReturnTo}" />
                  <button type="submit" class="text-btn danger" onclick='return confirm(@json(__('予定を削除しますか？') . "\n" . __('既に反映済みの取引がある場合はそれも削除されます。')))'>{{ __('予定を削除') }}</button>
                </form>
              `
              scheduleList.appendChild(row)
            })
          }
          bindAmountCalcInputs(scheduleModal)
          ensureFinanceAmountEasyButtons(scheduleModal)
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

        ;(function initFinanceTxBulk() {
          const selectAll = document.getElementById('finance-tx-select-all')
          const countEl = document.getElementById('finance-tx-bulk-count')
          const deleteBtn = document.getElementById('finance-tx-bulk-delete')
          const returnToEl = document.getElementById('finance-tx-bulk-return')
          if (!deleteBtn) return

          function checks() {
            return Array.from(document.querySelectorAll('.finance-tx-check'))
          }

          function updateBulkUi() {
            const all = checks()
            const selected = all.filter((cb) => cb.checked)
            if (countEl) countEl.textContent = selected.length + '件選択'
            if (selectAll) {
              selectAll.checked = all.length > 0 && selected.length === all.length
              selectAll.indeterminate = selected.length > 0 && selected.length < all.length
            }
          }

          selectAll?.addEventListener('change', () => {
            checks().forEach((cb) => { cb.checked = selectAll.checked })
            updateBulkUi()
          })
          document.querySelectorAll('.finance-tx-check').forEach((cb) => {
            cb.addEventListener('change', updateBulkUi)
          })
          deleteBtn.addEventListener('click', () => {
            const selected = checks().filter((cb) => cb.checked)
            if (selected.length === 0) {
              window.alert(@json(__('対象が選択されていません')));
              return
            }
            const msg = deleteBtn.dataset.confirm || '選択した取引を削除しますか？'
            if (!window.confirm(msg)) return
            const form = document.createElement('form')
            form.method = 'POST'
            form.action = deleteBtn.dataset.bulkUrl || '/finance/bulk/delete'
            form.style.display = 'none'
            const token = document.createElement('input')
            token.type = 'hidden'
            token.name = '_token'
            token.value = csrfToken || ''
            form.appendChild(token)
            const returnTo = document.createElement('input')
            returnTo.type = 'hidden'
            returnTo.name = 'returnTo'
            returnTo.value = returnToEl?.value || '/finance'
            form.appendChild(returnTo)
            selected.forEach((cb) => {
              const idInput = document.createElement('input')
              idInput.type = 'hidden'
              idInput.name = 'ids[]'
              idInput.value = cb.value
              form.appendChild(idInput)
            })
            document.body.appendChild(form)
            form.submit()
          })
          updateBulkUi()
        })()
      })()
    </script>
  </body>
</html>
