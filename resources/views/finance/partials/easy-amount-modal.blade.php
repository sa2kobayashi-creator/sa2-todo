<div class="modal modal-centered" id="finance-easy-amount-modal" hidden>
  <div class="modal-backdrop" data-close-finance-easy-amount></div>
  <div class="modal-dialog finance-easy-amount-dialog" role="dialog" aria-labelledby="finance-easy-amount-modal-title">
    <div class="modal-header">
      <h2 id="finance-easy-amount-modal-title">金額 簡単入力</h2>
      <button type="button" class="modal-close" data-close-finance-easy-amount aria-label="閉じる">×</button>
    </div>
    <div class="finance-easy-amount-body">
      <div class="finance-easy-amount-display-wrap">
        <p class="finance-easy-amount-display-label">現在の金額</p>
        <p id="finance-easy-amount-current" class="finance-easy-amount-current">0</p>
      </div>

      <div class="finance-easy-amount-mode-row" role="group" aria-label="加算・減算・クリア">
        <button type="button" class="finance-easy-amount-mode-btn is-active" data-finance-easy-amount-mode="add" aria-pressed="true">＋ 加算</button>
        <button type="button" class="finance-easy-amount-mode-btn" data-finance-easy-amount-mode="subtract" aria-pressed="false">－ 減算</button>
        <button type="button" class="finance-easy-amount-mode-btn finance-easy-amount-clear-btn" id="finance-easy-amount-clear">クリア</button>
      </div>

      <div class="finance-easy-amount-preset-grid">
        @foreach([1, 10, 100, 500, 1000, 5000, 10000, 50000] as $preset)
          <button type="button" class="finance-easy-amount-preset-btn" data-finance-easy-amount-delta="{{ $preset }}">{{ number_format($preset) }}</button>
        @endforeach
      </div>

      <p class="hint finance-easy-amount-help">ボタンを押すたびに金額が加算（または減算）されます。入力欄をクリックすれば直接入力もできます。モーダルを閉じるまで連続して入力できます。</p>
    </div>
    <div class="finance-easy-amount-footer">
      <button type="button" class="secondary" id="finance-easy-amount-close" data-close-finance-easy-amount>閉じる</button>
    </div>
  </div>
</div>
