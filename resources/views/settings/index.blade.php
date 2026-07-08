<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>設定 - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'settings', 'settingsSection' => $section ?? 'holidays'])
    <main class="page-main {{ ($section ?? '') === 'integration' ? '' : 'page-main-narrow' }}">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <nav class="settings-subnav" aria-label="設定メニュー">
        <a href="{{ $settingsPath('holidays') }}" @class(['active' => ($section ?? '') === 'holidays'])>休日設定</a>
        <a href="{{ $settingsPath('translation') }}" @class(['active' => ($section ?? '') === 'translation'])>AI翻訳</a>
        <a href="{{ $settingsPath('integration') }}" @class(['active' => ($section ?? '') === 'integration'])>LINE連携</a>
        <a href="{{ $settingsPath('notifications') }}" @class(['active' => ($section ?? '') === 'notifications'])>通知設定</a>
      </nav>

      @if(($section ?? 'holidays') === 'holidays')
      <div class="panel" id="weekday-holidays">
        <h2>曜日による休日</h2>
        <p class="hint">期間内でチェックした曜日を定休日にします。除外日を指定すると、その日だけ休日になりません。</p>

        <form method="post" action="/settings/weekday-rules/add" class="weekday-rule-form">
          @csrf
          <input type="hidden" name="year" value="{{ $holidayYear }}" />
          <label>名称<input type="text" name="name" placeholder="定休日（土日）" /></label>
          <label>開始日<input type="date" name="startDate" required /></label>
          <label>終了日<input type="date" name="endDate" required /></label>
          <fieldset class="weekday-checkboxes">
            <legend>休日にする曜日</legend>
            <div class="weekday-check-row">
              @foreach($weekdayLabels as $index => $label)
                <label class="weekday-check">
                  <input type="checkbox" name="weekdays[]" value="{{ $index }}" class="weekday-check-input" data-label="{{ $label }}" />
                  {{ $label }}
                </label>
              @endforeach
            </div>
            <div class="selected-preview" id="weekday-selected-preview" hidden>
              <span class="selected-preview-label">選択中:</span>
              <span class="selected-preview-items" id="weekday-selected-items"></span>
            </div>
          </fieldset>
          <div class="exception-picker-field">
            <span class="field-label">除外日（任意）</span>
            <div class="exception-picker">
              <input type="date" id="new-rule-exception-date" />
              <button type="button" class="mini-btn secondary" id="new-rule-exception-add">追加</button>
            </div>
            <div class="selected-preview exception-preview" id="new-rule-exception-preview" hidden>
              <span class="selected-preview-label">登録する除外日:</span>
              <span class="selected-preview-items" id="new-rule-exception-items"></span>
            </div>
            <div id="new-rule-exception-hidden"></div>
          </div>
          <button type="submit" class="secondary">ルールを追加</button>
        </form>

        @if(count($weekdayRules) === 0)
          <p class="hint empty-hint">曜日休日ルールはまだありません。</p>
        @endif

        @foreach($weekdayRules as $rule)
          <div class="weekday-rule-card">
            <div class="weekday-rule-head">
              <strong>{{ $rule['name'] }}</strong>
              <span class="weekday-rule-period">{{ $rule['startDate'] }} 〜 {{ $rule['endDate'] }}</span>
              <form method="post" action="/settings/weekday-rules/{{ $rule['id'] }}/delete" class="inline-form" onsubmit="return confirm('このルールを削除しますか？')">
                @csrf
                <input type="hidden" name="year" value="{{ $holidayYear }}" />
                <button type="submit" class="danger mini-btn">削除</button>
              </form>
            </div>
            <div class="weekday-rule-days">
              @foreach($rule['weekdays'] as $dow)
                <span class="weekday-chip">{{ $weekdayLabels[$dow] ?? $dow }}</span>
              @endforeach
            </div>
            <div class="weekday-exceptions">
              <span class="exceptions-label">除外日:</span>
              @if(empty($rule['exceptions']))
                <span class="hint inline-hint">なし</span>
              @endif
              @foreach($rule['exceptions'] ?? [] as $exDate)
                <form method="post" action="/settings/weekday-rules/{{ $rule['id'] }}/exceptions/delete" class="inline-form exception-chip-form">
                  @csrf
                  <input type="hidden" name="year" value="{{ $holidayYear }}" />
                  <input type="hidden" name="date" value="{{ $exDate }}" />
                  <button type="submit" class="exception-chip" title="除外を解除">{{ $exDate }} ×</button>
                </form>
              @endforeach
              <form method="post" action="/settings/weekday-rules/{{ $rule['id'] }}/exceptions/add" class="inline-form exception-add-form">
                @csrf
                <input type="hidden" name="year" value="{{ $holidayYear }}" />
                <input type="date" name="date" required />
                <button type="submit" class="mini-btn secondary">除外日を追加</button>
              </form>
            </div>
          </div>
        @endforeach
      </div>

      <div class="panel">
        <h2>休日マスタ</h2>
        <p class="hint">日本・フィリピンの祝日を取り込めます。会社独自の休日も追加できます。</p>

        <div class="holiday-toolbar">
          <a class="button-link secondary icon-btn" href="{{ $settingsPath('holidays', $prevHolidayYear) }}">‹</a>
          <strong>{{ $holidayYear }}年</strong>
          <a class="button-link secondary icon-btn" href="{{ $settingsPath('holidays', $nextHolidayYear) }}">›</a>
          <form method="post" action="/settings/holidays/import" class="inline-form">
            @csrf
            <input type="hidden" name="year" value="{{ $holidayYear }}" />
            <input type="hidden" name="country" value="jp" />
            <button type="submit">日本の祝日を取り込み</button>
          </form>
          <form method="post" action="/settings/holidays/import" class="inline-form">
            @csrf
            <input type="hidden" name="year" value="{{ $holidayYear }}" />
            <input type="hidden" name="country" value="ph" />
            <button type="submit" class="secondary">フィリピンの祝日を取り込み</button>
          </form>
        </div>

        <form method="post" action="/settings/holidays/add" class="holiday-add-form" id="holiday-add-form">
          @csrf
          <input type="hidden" name="year" value="{{ $holidayYear }}" />
          <div class="holiday-date-block date-mode-row add-form-row">
            <span class="field-label">日付</span>
            <div class="date-mode-radios">
              <label class="radio-inline"><input type="radio" name="dateMode" value="single" checked /> 単日</label>
              <label class="radio-inline"><input type="radio" name="dateMode" value="range" /> 期間</label>
            </div>
            <div class="date-mode-fields">
              <div class="date-mode-inputs" id="holiday-single-inputs">
                <input type="date" name="date" id="holiday-date-single" required />
              </div>
              <div class="date-mode-inputs date-range-inputs date-panel-hidden" id="holiday-range-inputs">
                <label class="inline-date-label">開始<input type="date" name="startDate" id="holiday-date-start" /></label>
                <label class="inline-date-label">終了<input type="date" name="endDate" id="holiday-date-end" /></label>
              </div>
            </div>
          </div>
          <label>名称<input type="text" name="name" placeholder="会社休業日" required /></label>
          <button type="submit" class="secondary">休日を追加</button>
        </form>

        <table class="holiday-table">
          <thead>
            <tr><th>日付</th><th>名称</th><th>種別</th><th></th></tr>
          </thead>
          <tbody>
            @if(count($holidays) === 0)
              <tr><td colspan="4" class="empty-cell">登録がありません。祝日の取り込みボタンを押してください。</td></tr>
            @endif
            @foreach($holidays as $item)
              <tr>
                <td>{{ $item['date'] }}</td>
                <td>{{ $item['name'] }}</td>
                <td>
                  <span class="holiday-type {{ $item['source'] }}">
                    @switch($item['source'])
                      @case('national') 日本祝日 @break
                      @case('national_ph') PH祝日 @break
                      @default 独自
                    @endswitch
                  </span>
                </td>
                <td>
                  <form method="post" action="/settings/holidays/{{ $item['id'] }}/delete" onsubmit="return confirm('削除しますか？')">
                    @csrf
                    <input type="hidden" name="year" value="{{ $holidayYear }}" />
                    <button type="submit" class="danger mini-btn">削除</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @elseif(($section ?? '') === 'translation')
      <div class="panel" id="translation-settings">
        <h2>AI翻訳（DeepL）</h2>
        <p class="hint">DeepL APIキーを登録すると、メモカードの翻訳ボタンで日本語⇔英語の翻訳ができます。無料版キー（末尾が <code>:fx</code>）と有料版キーの両方に対応しています。</p>

        <form method="post" action="/settings/translation-keys" class="translation-key-form" id="translation-key-form">
          @csrf
          <div class="translation-form-grid">
            <label>識別名<input type="text" name="name" placeholder="DeepL Key 1" required /></label>
            <label class="translation-key-field">
              APIキー
              <span class="translation-key-input">
                <input type="password" name="api_key" id="translation-api-key-input" placeholder="xxxxxxxx-xxxx-...:fx" autocomplete="off" required />
                <button type="button" class="mini-btn secondary" id="translation-key-toggle" aria-label="表示切替">表示</button>
              </span>
            </label>
            <label>APIエンドポイント（任意）<input type="url" name="api_url" placeholder="未入力ならキーから自動判定" /></label>
            <label>優先順位<input type="number" name="priority" value="0" min="0" /></label>
            <label>1日の上限文字数（任意）<input type="number" name="daily_limit" min="0" placeholder="無制限" /></label>
            <label>1ヶ月の上限文字数（任意）<input type="number" name="monthly_limit" min="0" placeholder="無制限" /></label>
          </div>
          <label class="translation-notes-field">メモ（任意）<input type="text" name="notes" placeholder="用途など" /></label>
          <label class="checkbox-inline"><input type="checkbox" name="is_active" value="1" checked /> 有効にする</label>
          <div class="translation-form-actions">
            <button type="button" class="secondary" id="translation-test-btn">接続テスト</button>
            <span class="translation-test-result hint" id="translation-test-result"></span>
            <button type="submit">キーを追加</button>
          </div>
        </form>

        <table class="holiday-table translation-key-table">
          <thead>
            <tr><th>識別名</th><th>状態</th><th>優先</th><th>使用量(月)</th><th>エラー</th><th></th></tr>
          </thead>
          <tbody>
            @if(count($translationKeys) === 0)
              <tr><td colspan="6" class="empty-cell">APIキーが登録されていません。上のフォームから追加してください。</td></tr>
            @endif
            @foreach($translationKeys as $key)
              <tr>
                <td>
                  <strong>{{ $key->name }}</strong>
                  @if($key->notes)<div class="hint inline-hint">{{ $key->notes }}</div>@endif
                </td>
                <td><span class="holiday-type {{ $key->is_active ? 'national' : '' }}">{{ $key->is_active ? '有効' : '無効' }}</span></td>
                <td>{{ $key->priority }}</td>
                <td>{{ number_format($key->current_monthly_usage) }}@if($key->monthly_limit) / {{ number_format($key->monthly_limit) }}@endif</td>
                <td>{{ $key->error_count }}</td>
                <td class="translation-key-row-actions">
                  <form method="post" action="/settings/translation-keys/{{ $key->id }}/update" class="inline-form">
                    @csrf
                    <input type="hidden" name="name" value="{{ $key->name }}" />
                    <input type="hidden" name="priority" value="{{ $key->priority }}" />
                    @if($key->daily_limit)<input type="hidden" name="daily_limit" value="{{ $key->daily_limit }}" />@endif
                    @if($key->monthly_limit)<input type="hidden" name="monthly_limit" value="{{ $key->monthly_limit }}" />@endif
                    @if($key->notes)<input type="hidden" name="notes" value="{{ $key->notes }}" />@endif
                    @if(! $key->is_active)<input type="hidden" name="is_active" value="1" />@endif
                    <button type="submit" class="mini-btn secondary">{{ $key->is_active ? '無効化' : '有効化' }}</button>
                  </form>
                  <form method="post" action="/settings/translation-keys/{{ $key->id }}/delete" class="inline-form" onsubmit="return confirm('このAPIキーを削除しますか？')">
                    @csrf
                    <button type="submit" class="danger mini-btn">削除</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @elseif(($section ?? '') === 'integration')
      <div class="panel"><h2>LINE 連携</h2><p class="hint">次のフェーズで移植予定です。</p></div>
      @elseif(($section ?? '') === 'notifications')
      <div class="panel"><h2>通知設定</h2><p class="hint">Web Push / LINE 通知は次のフェーズで移植予定です。</p></div>
      @endif
    </main>
    @if(($section ?? 'holidays') === 'holidays')
    <script>
      (function () {
        const weekdayInputs = document.querySelectorAll('.weekday-check-input');
        const weekdayPreview = document.getElementById('weekday-selected-preview');
        const weekdayItems = document.getElementById('weekday-selected-items');
        if (weekdayInputs.length) {
          function renderWeekdayPreview() {
            const selected = [...weekdayInputs].filter((input) => input.checked);
            if (!weekdayPreview || !weekdayItems) return;
            if (selected.length === 0) { weekdayPreview.hidden = true; weekdayItems.innerHTML = ''; return; }
            weekdayPreview.hidden = false;
            weekdayItems.innerHTML = selected.map((input) => `<span class="weekday-chip">${input.dataset.label}</span>`).join('');
          }
          weekdayInputs.forEach((input) => input.addEventListener('change', renderWeekdayPreview));
        }
        const exceptionDates = [];
        const exceptionDateInput = document.getElementById('new-rule-exception-date');
        const exceptionAddBtn = document.getElementById('new-rule-exception-add');
        const exceptionPreview = document.getElementById('new-rule-exception-preview');
        const exceptionItems = document.getElementById('new-rule-exception-items');
        const exceptionHidden = document.getElementById('new-rule-exception-hidden');
        if (exceptionAddBtn && exceptionDateInput) {
          function renderExceptions() {
            if (exceptionHidden) {
              exceptionHidden.innerHTML = exceptionDates.map((date) => `<input type="hidden" name="exceptions[]" value="${date}" />`).join('');
            }
            if (!exceptionPreview || !exceptionItems) return;
            if (exceptionDates.length === 0) { exceptionPreview.hidden = true; exceptionItems.innerHTML = ''; return; }
            exceptionPreview.hidden = false;
            exceptionItems.innerHTML = exceptionDates.map((date) => `<button type="button" class="exception-chip removable" data-date="${date}">${date} ×</button>`).join('');
            exceptionItems.querySelectorAll('.removable').forEach((btn) => {
              btn.addEventListener('click', () => {
                const index = exceptionDates.indexOf(btn.dataset.date);
                if (index >= 0) exceptionDates.splice(index, 1);
                renderExceptions();
              });
            });
          }
          exceptionAddBtn.addEventListener('click', () => {
            const date = exceptionDateInput.value;
            if (!date || exceptionDates.includes(date)) return;
            exceptionDates.push(date);
            exceptionDates.sort();
            exceptionDateInput.value = '';
            renderExceptions();
          });
        }
        const dateModeRadios = document.querySelectorAll('input[name="dateMode"]');
        const singleInputs = document.getElementById('holiday-single-inputs');
        const rangeInputs = document.getElementById('holiday-range-inputs');
        const singleDate = document.getElementById('holiday-date-single');
        const startDate = document.getElementById('holiday-date-start');
        const endDate = document.getElementById('holiday-date-end');
        if (dateModeRadios.length && singleInputs && rangeInputs) {
          function syncHolidayDateMode() {
            const mode = document.querySelector('input[name="dateMode"]:checked')?.value || 'single';
            const isRange = mode === 'range';
            singleInputs.classList.toggle('date-panel-hidden', isRange);
            rangeInputs.classList.toggle('date-panel-hidden', !isRange);
            if (singleDate) singleDate.required = !isRange;
            if (startDate) startDate.required = isRange;
            if (endDate) endDate.required = isRange;
          }
          dateModeRadios.forEach((radio) => radio.addEventListener('change', syncHolidayDateMode));
          syncHolidayDateMode();
        }
      })();
    </script>
    @endif
    @if(($section ?? '') === 'translation')
    <script>
      (function () {
        const keyInput = document.getElementById('translation-api-key-input');
        const toggleBtn = document.getElementById('translation-key-toggle');
        toggleBtn?.addEventListener('click', () => {
          if (!keyInput) return;
          const show = keyInput.type === 'password';
          keyInput.type = show ? 'text' : 'password';
          toggleBtn.textContent = show ? '隠す' : '表示';
        });

        const testBtn = document.getElementById('translation-test-btn');
        const resultEl = document.getElementById('translation-test-result');
        const apiUrlInput = document.querySelector('#translation-key-form input[name="api_url"]');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        testBtn?.addEventListener('click', async () => {
          const apiKey = keyInput?.value.trim();
          if (!apiKey) {
            resultEl.textContent = 'APIキーを入力してください';
            resultEl.className = 'translation-test-result hint is-error';
            return;
          }
          testBtn.disabled = true;
          resultEl.textContent = 'テスト中...';
          resultEl.className = 'translation-test-result hint';
          try {
            const res = await fetch('/settings/translation-keys/test', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
              },
              body: JSON.stringify({ api_key: apiKey, api_url: apiUrlInput?.value || '' }),
            });
            const data = await res.json();
            resultEl.textContent = data.ok
              ? `${data.message}（Hello, world! → ${data.translated ?? ''}）`
              : data.message;
            resultEl.className = 'translation-test-result hint ' + (data.ok ? 'is-ok' : 'is-error');
          } catch (e) {
            resultEl.textContent = '通信エラーが発生しました';
            resultEl.className = 'translation-test-result hint is-error';
          } finally {
            testBtn.disabled = false;
          }
        });
      })();
    </script>
    @endif
  </body>
</html>
