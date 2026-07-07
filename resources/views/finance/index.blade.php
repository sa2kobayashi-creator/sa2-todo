<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
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
      </div>

      <section class="finance-summary panel">
        <h2 class="finance-section-title">{{ $monthLabel }} サマリー</h2>
        <div class="finance-summary-grid">
          <div class="finance-summary-item">
            <span class="finance-summary-label">収入</span>
            <strong class="finance-summary-value income">{{ $formatMoney($summary['income'], $summary['currency']) }}</strong>
          </div>
          <div class="finance-summary-item">
            <span class="finance-summary-label">支出</span>
            <strong class="finance-summary-value expense">{{ $formatMoney($summary['expense'], $summary['currency']) }}</strong>
          </div>
          <div class="finance-summary-item">
            <span class="finance-summary-label">収支</span>
            <strong class="finance-summary-value">{{ $formatMoney($summary['net'], $summary['currency']) }}</strong>
          </div>
          @if($filters['tab'] === 'transfer' || $filters['tab'] === 'all' || $filters['tab'] === 'jp' || $filters['tab'] === 'ph')
            <div class="finance-summary-item">
              <span class="finance-summary-label">振替出</span>
              <strong class="finance-summary-value">{{ $formatMoney($summary['transferOut'], $summary['currency']) }}</strong>
            </div>
            <div class="finance-summary-item">
              <span class="finance-summary-label">振替入</span>
              <strong class="finance-summary-value">{{ $formatMoney($summary['transferIn'], $summary['currency']) }}</strong>
            </div>
          @endif
        </div>
      </section>

      @if($filters['tab'] !== 'transfer')
        <section class="finance-accounts panel">
          <div class="finance-section-head">
            <h2 class="finance-section-title">口座残高</h2>
            <button type="button" class="text-btn" id="finance-toggle-settings">口座設定</button>
          </div>

          @forelse($groupedAccounts as $kind => $kindAccounts)
            <div class="finance-account-group">
              <h3 class="finance-account-group-title">{{ \App\Services\FinanceService::KIND_LABELS[$kind] ?? $kind }}</h3>
              <div class="finance-account-cards">
                @foreach($kindAccounts as $account)
                  <a
                    href="{{ $buildFinanceQuery(array_merge($filters, ['accountId' => $account['id']])) }}"
                    class="finance-account-card @if($filters['accountId'] === $account['id']) is-selected @endif"
                  >
                    <span class="finance-account-name">{{ $account['name'] }}</span>
                    <strong class="finance-account-balance">{{ $formatMoney($account['balance'], $account['currency']) }}</strong>
                  </a>
                @endforeach
              </div>
            </div>
          @empty
            <p class="hint">表示する口座がありません。</p>
          @endforelse

          @if($filters['accountId'])
            <p class="hint inline-hint">
              口座フィルタ中
              <a href="{{ $buildFinanceQuery(array_merge($filters, ['accountId' => null])) }}">解除</a>
            </p>
          @endif

          <div class="finance-account-settings" id="finance-account-settings" hidden>
            <h3 class="finance-account-group-title">開始残高・引落口座</h3>
            @foreach($accounts as $account)
              <details class="finance-account-setting-row">
                <summary>{{ $account['name'] }}（{{ $formatMoney($account['balance'], $account['currency']) }}）</summary>
                <form method="post" action="/finance/accounts/{{ $account['id'] }}/balance" class="finance-inline-form">
                  @csrf
                  <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
                  <label>
                    開始残高
                    <input type="number" name="initialBalance" step="0.01" value="{{ $account['initialBalance'] }}" />
                  </label>
                  <button type="submit" class="button-link secondary">保存</button>
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
                    <button type="submit" class="button-link secondary">保存</button>
                  </form>
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
              <input type="number" name="toAmount" id="finance-to-amount" min="0" step="0.01" />
            </label>
          </div>

          <label>
            金額
            <input type="number" name="amount" id="finance-amount" min="0.01" step="0.01" required />
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

    <script>
      (function () {
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

        function openAddModal() {
          modalTitle.textContent = '取引を追加'
          submitBtn.textContent = '保存'
          transactionIdInput.value = ''
          form.action = '/finance'
          form.method = 'post'
          form.querySelector('#finance-date').value = @json($defaultDate)
          form.querySelector('#finance-amount').value = ''
          form.querySelector('#finance-memo').value = ''
          form.querySelector('#finance-to-amount').value = ''
          form.querySelector('input[name="type"][value="expense"]').checked = true
          syncTransferVisibility()
          modal?.removeAttribute('hidden')
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

        openBtn?.addEventListener('click', openAddModal)
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

        @if($filters['tab'] === 'jp')
          const defaultAccount = @json(collect($jpAccounts)->first()['id'] ?? null)
        @elseif($filters['tab'] === 'ph')
          const defaultAccount = @json(collect($phAccounts)->first()['id'] ?? null)
        @else
          const defaultAccount = null
        @endif

        if (defaultAccount) accountSelect.value = String(defaultAccount)
      })()
    </script>
  </body>
</html>
