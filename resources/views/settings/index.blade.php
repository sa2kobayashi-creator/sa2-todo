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
        <a href="/settings?section=ai&tab=translation" @class(['active' => ($section ?? '') === 'ai'])>AI設定</a>
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
      @elseif(($section ?? '') === 'ai')
      <div class="panel" id="ai-settings">
        <h2>AI設定</h2>
        <p class="hint">翻訳は DeepL、相談チャットは OpenAI / Gemini のAPIキーを登録します。無料枠・有料の区分と日次／月次上限を設定できます。</p>
        <div class="ai-settings-tabs" role="tablist">
          <a href="/settings?section=ai&tab=translation" @class(['ai-settings-tab', 'is-active' => ($aiTab ?? 'translation') === 'translation'])>翻訳（DeepL）</a>
          <a href="/settings?section=ai&tab=chat" @class(['ai-settings-tab', 'is-active' => ($aiTab ?? '') === 'chat'])>チャット（OpenAI / Gemini）</a>
        </div>

        @if(($aiTab ?? 'translation') === 'translation')
        <h3 class="ai-settings-subtitle">AI翻訳（DeepL）</h3>
        <p class="hint">複数のAPIキーを登録すると、使用制限に達した場合に自動的に次のキーへ切り替わります。</p>

        <div class="translation-toolbar">
          <button type="button" class="button-link" id="translation-add-btn">APIキーを追加</button>
        </div>

        <table class="holiday-table translation-key-table">
          <thead>
            <tr><th>識別名</th><th>状態</th><th>優先</th><th>使用量</th><th>エラー</th><th>操作</th></tr>
          </thead>
          <tbody>
            @if(count($translationKeys) === 0)
              <tr><td colspan="6" class="empty-cell">APIキーが登録されていません。「APIキーを追加」から登録してください。</td></tr>
            @endif
            @foreach($translationKeys as $key)
              @php
                $dailyRate = $key->getDailyUsageRate();
                $monthlyRate = $key->getMonthlyUsageRate();
              @endphp
              <tr>
                <td>
                  <strong>{{ $key->name }}</strong>
                  @if($key->notes)<div class="hint inline-hint">{{ $key->notes }}</div>@endif
                </td>
                <td><span class="holiday-type {{ $key->is_active ? 'national' : '' }}">{{ $key->is_active ? '有効' : '無効' }}</span></td>
                <td>{{ $key->priority }}</td>
                <td class="translation-usage-cell">
                  <div>
                    日次: {{ number_format($key->current_daily_usage) }}
                    @if($key->daily_limit)
                      / {{ number_format($key->daily_limit) }}
                      @if($dailyRate !== null)
                        <span class="translation-usage-rate {{ $dailyRate > 80 ? 'is-danger' : ($dailyRate > 50 ? 'is-warn' : '') }}">({{ number_format($dailyRate, 1) }}%)</span>
                      @endif
                    @else
                      <span class="hint inline-hint">(制限なし)</span>
                    @endif
                  </div>
                  <div>
                    月次: {{ number_format($key->current_monthly_usage) }}
                    @if($key->monthly_limit)
                      / {{ number_format($key->monthly_limit) }}
                      @if($monthlyRate !== null)
                        <span class="translation-usage-rate {{ $monthlyRate > 80 ? 'is-danger' : ($monthlyRate > 50 ? 'is-warn' : '') }}">({{ number_format($monthlyRate, 1) }}%)</span>
                      @endif
                    @else
                      <span class="hint inline-hint">(制限なし)</span>
                    @endif
                  </div>
                </td>
                <td>
                  @if($key->error_count > 0)
                    <span class="holiday-type {{ $key->error_count >= 5 ? '' : 'national_ph' }}">{{ $key->error_count }}回</span>
                    @if($key->last_error_at)<div class="hint inline-hint">{{ $key->last_error_at->format('Y/m/d H:i') }}</div>@endif
                  @else
                    <span class="hint">-</span>
                  @endif
                </td>
                <td class="translation-key-row-actions">
                  <button type="button" class="mini-btn secondary translation-edit-btn" data-id="{{ $key->id }}">編集</button>
                  <form method="post" action="/settings/translation-keys/{{ $key->id }}/reset-usage" class="inline-form" onsubmit="return confirm('使用量をリセットしますか？')">
                    @csrf
                    <button type="submit" class="mini-btn secondary" title="使用量をリセット">↺</button>
                  </form>
                  <form method="post" action="/settings/translation-keys/{{ $key->id }}/delete" class="inline-form" onsubmit="return confirm('「{{ $key->name }}」を削除しますか？')">
                    @csrf
                    <button type="submit" class="danger mini-btn">削除</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        @else
        <h3 class="ai-settings-subtitle">AI相談チャット（OpenAI / Gemini）</h3>
        <p class="hint">AI相談ページで使うAPIキーです。プラン区分とトークン目安の上限を設定できます。</p>
        <div class="translation-toolbar">
          <button type="button" class="button-link" id="ai-key-add-btn">APIキーを追加</button>
        </div>
        <table class="holiday-table translation-key-table">
          <thead>
            <tr><th>識別名</th><th>プロバイダ</th><th>プラン</th><th>モデル</th><th>状態</th><th>使用量</th><th>操作</th></tr>
          </thead>
          <tbody>
            @if(count($aiChatKeys) === 0)
              <tr><td colspan="7" class="empty-cell">チャット用APIキーがありません。OpenAI または Gemini のキーを追加してください。</td></tr>
            @endif
            @foreach($aiChatKeys as $key)
              <tr>
                <td>
                  <strong>{{ $key->name }}</strong>
                  <div class="hint inline-hint">{{ $key->maskedKey() }}</div>
                  @if($key->notes)<div class="hint inline-hint">{{ $key->notes }}</div>@endif
                </td>
                <td>{{ $key->providerLabel() }}</td>
                <td>{{ $key->planLabel() }}</td>
                <td><code>{{ $key->default_model }}</code></td>
                <td><span class="holiday-type {{ $key->is_active ? 'national' : '' }}">{{ $key->is_active ? '有効' : '無効' }}</span></td>
                <td class="translation-usage-cell">
                  <div>日次: {{ number_format($key->current_daily_usage) }}@if($key->daily_limit) / {{ number_format($key->daily_limit) }}@endif</div>
                  <div>月次: {{ number_format($key->current_monthly_usage) }}@if($key->monthly_limit) / {{ number_format($key->monthly_limit) }}@endif</div>
                </td>
                <td class="translation-key-row-actions">
                  <button type="button" class="mini-btn secondary ai-key-edit-btn" data-id="{{ $key->id }}">編集</button>
                  <form method="post" action="/settings/ai-keys/{{ $key->id }}/reset-usage" class="inline-form" onsubmit="return confirm('使用量をリセットしますか？')">
                    @csrf
                    <button type="submit" class="mini-btn secondary">↺</button>
                  </form>
                  <form method="post" action="/settings/ai-keys/{{ $key->id }}/delete" class="inline-form" onsubmit="return confirm('「{{ $key->name }}」を削除しますか？')">
                    @csrf
                    <button type="submit" class="danger mini-btn">削除</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        @endif
      </div>

      @if(($aiTab ?? 'translation') === 'translation')
      <div class="modal modal-centered" id="translation-key-modal" hidden>
        <div class="modal-backdrop" data-close-translation-modal></div>
        <div class="modal-dialog modal-dialog-wide" role="dialog" aria-labelledby="translation-modal-title">
          <div class="modal-header">
            <h2 id="translation-modal-title">APIキーを追加</h2>
            <button type="button" class="modal-close" data-close-translation-modal aria-label="閉じる">×</button>
          </div>
          <form method="post" action="/settings/translation-keys" id="translation-key-form" class="modal-form translation-modal-form">
            @csrf
            <input type="hidden" name="editing_id" id="translation-editing-id" value="" />
            <label>識別名<input type="text" name="name" id="translation-name" placeholder="DeepL Key 1" required /></label>
            <label>
              APIキー
              <span class="translation-key-input">
                <input type="password" name="api_key" id="translation-api-key-input" placeholder="xxxxxxxx-xxxx-...:fx" autocomplete="off" required />
                <button type="button" class="mini-btn secondary" id="translation-key-toggle" aria-label="表示切替">表示</button>
              </span>
            </label>
            <label>APIエンドポイント（任意）<input type="url" name="api_url" id="translation-api-url" placeholder="未入力ならキーから自動判定" /></label>
            <div class="translation-form-grid">
              <label>優先順位<input type="number" name="priority" id="translation-priority" value="0" min="0" /></label>
              <label>日次制限（文字数）<input type="number" name="daily_limit" id="translation-daily-limit" min="0" placeholder="無制限" /></label>
              <label>月次制限（文字数）<input type="number" name="monthly_limit" id="translation-monthly-limit" min="0" placeholder="無制限" /></label>
              <label>日次使用量（文字数）<input type="number" name="current_daily_usage" id="translation-daily-usage" min="0" placeholder="0" /></label>
              <label>月次使用量（文字数）<input type="number" name="current_monthly_usage" id="translation-monthly-usage" min="0" placeholder="0" /></label>
            </div>
            <div id="translation-deepl-usage-section" hidden>
              <button type="button" class="secondary" id="translation-fetch-usage-btn">DeepLから使用量を取得</button>
              <p class="hint inline-hint">DeepL APIから現在の使用量と制限を取得して、上記フィールドに自動入力します。</p>
            </div>
            <label>メモ<input type="text" name="notes" id="translation-notes" placeholder="用途など" /></label>
            <label class="checkbox-inline"><input type="checkbox" name="is_active" id="translation-is-active" value="1" checked /> 有効にする</label>
            <label class="checkbox-inline translation-limit-exceeded-row" id="translation-limit-exceeded-row" hidden>
              <input type="checkbox" name="set_limit_exceeded" id="translation-set-limit-exceeded" value="1" />
              制限超過を設定（使用量を制限値に設定）
            </label>
            <div class="translation-form-actions">
              <button type="button" class="secondary" id="translation-test-btn">接続テスト</button>
              <span class="translation-test-result hint" id="translation-test-result"></span>
              <div class="translation-modal-submit-actions">
                <button type="button" class="secondary" data-close-translation-modal>キャンセル</button>
                <button type="submit" id="translation-submit-btn">保存</button>
              </div>
            </div>
          </form>
        </div>
      </div>
      @else
      <div class="modal modal-centered" id="ai-key-modal" hidden>
        <div class="modal-backdrop" data-close-ai-key-modal></div>
        <div class="modal-dialog modal-dialog-wide" role="dialog" aria-labelledby="ai-key-modal-title">
          <div class="modal-header">
            <h2 id="ai-key-modal-title">チャットAPIキーを追加</h2>
            <button type="button" class="modal-close" data-close-ai-key-modal aria-label="閉じる">×</button>
          </div>
          <form method="post" action="/settings/ai-keys" id="ai-key-form" class="modal-form translation-modal-form">
            @csrf
            <input type="hidden" name="editing_id" id="ai-key-editing-id" value="" />
            <label>識別名<input type="text" name="name" id="ai-key-name" placeholder="OpenAI メイン" required /></label>
            <label>
              プロバイダ
              <select name="provider" id="ai-key-provider" required>
                @foreach($aiProviders as $value => $meta)
                  <option value="{{ $value }}">{{ $meta['label'] ?? $value }}</option>
                @endforeach
              </select>
            </label>
            <label>
              プラン区分
              <select name="plan" id="ai-key-plan">
                @foreach($aiPlans as $value => $label)
                  <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
              </select>
            </label>
            <label>
              デフォルトモデル
              <select name="default_model" id="ai-key-model"></select>
            </label>
            <label>
              APIキー
              <span class="translation-key-input">
                <input type="password" name="api_key" id="ai-key-input" placeholder="sk-... / AIza..." autocomplete="off" />
                <button type="button" class="mini-btn secondary" id="ai-key-toggle">表示</button>
              </span>
            </label>
            <div class="translation-form-grid">
              <label>優先順位<input type="number" name="priority" id="ai-key-priority" value="0" min="0" /></label>
              <label>日次上限（トークン目安）<input type="number" name="daily_limit" id="ai-key-daily-limit" min="0" placeholder="無制限" /></label>
              <label>月次上限（トークン目安）<input type="number" name="monthly_limit" id="ai-key-monthly-limit" min="0" placeholder="無制限" /></label>
              <label>日次使用量<input type="number" name="current_daily_usage" id="ai-key-daily-usage" min="0" placeholder="0" /></label>
              <label>月次使用量<input type="number" name="current_monthly_usage" id="ai-key-monthly-usage" min="0" placeholder="0" /></label>
            </div>
            <label>メモ<input type="text" name="notes" id="ai-key-notes" placeholder="用途など" /></label>
            <label class="checkbox-inline"><input type="checkbox" name="is_active" id="ai-key-is-active" value="1" checked /> 有効にする</label>
            <div class="translation-form-actions">
              <button type="button" class="secondary" id="ai-key-test-btn">接続テスト</button>
              <span class="translation-test-result hint" id="ai-key-test-result"></span>
              <div class="translation-modal-submit-actions">
                <button type="button" class="secondary" data-close-ai-key-modal>キャンセル</button>
                <button type="submit" id="ai-key-submit-btn">保存</button>
              </div>
            </div>
          </form>
        </div>
      </div>
      @endif
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
    @if(($section ?? '') === 'ai' && ($aiTab ?? 'translation') === 'translation')
    <script>
      (function () {
        const modal = document.getElementById('translation-key-modal');
        const form = document.getElementById('translation-key-form');
        const modalTitle = document.getElementById('translation-modal-title');
        const editingIdInput = document.getElementById('translation-editing-id');
        const keyInput = document.getElementById('translation-api-key-input');
        const apiUrlInput = document.getElementById('translation-api-url');
        const testBtn = document.getElementById('translation-test-btn');
        const resultEl = document.getElementById('translation-test-result');
        const fetchUsageBtn = document.getElementById('translation-fetch-usage-btn');
        const deeplUsageSection = document.getElementById('translation-deepl-usage-section');
        const limitExceededRow = document.getElementById('translation-limit-exceeded-row');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const deeplApiUrls = {
          free: 'https://api-free.deepl.com/v2/translate',
          paid: 'https://api.deepl.com/v2/translate',
        };

        let editingId = null;

        function getDeepLApiUrl(apiKey) {
          if (!apiKey || apiKey.trim() === '') return '';
          return apiKey.includes(':fx') ? deeplApiUrls.free : deeplApiUrls.paid;
        }

        function updateApiUrlFromKey() {
          const apiKey = keyInput?.value || '';
          const currentUrl = apiUrlInput?.value.trim() || '';
          if (!apiKey.trim()) {
            if (apiUrlInput) apiUrlInput.value = '';
            return;
          }
          const deeplUrl = getDeepLApiUrl(apiKey);
          if (!currentUrl || !currentUrl.includes('deepl.com') ||
              (currentUrl !== deeplApiUrls.free && currentUrl !== deeplApiUrls.paid)) {
            if (apiUrlInput) apiUrlInput.value = deeplUrl;
          }
        }

        function openModal() {
          if (modal) modal.hidden = false;
        }

        function closeModal() {
          if (modal) modal.hidden = true;
          editingId = null;
        }

        function setField(id, value) {
          const el = document.getElementById(id);
          if (el) el.value = value ?? '';
        }

        function openAddModal() {
          editingId = null;
          if (editingIdInput) editingIdInput.value = '';
          if (modalTitle) modalTitle.textContent = 'APIキーを追加';
          if (form) {
            form.action = '/settings/translation-keys';
            form.reset();
          }
          setField('translation-priority', '0');
          const activeEl = document.getElementById('translation-is-active');
          if (activeEl) activeEl.checked = true;
          if (keyInput) keyInput.required = true;
          if (deeplUsageSection) deeplUsageSection.hidden = true;
          if (limitExceededRow) limitExceededRow.hidden = true;
          if (resultEl) resultEl.textContent = '';
          openModal();
        }

        async function openEditModal(id) {
          editingId = id;
          try {
            const res = await fetch(`/settings/translation-keys/${id}/edit`, {
              headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (!res.ok) {
              window.alert(data.error || 'データの取得に失敗しました');
              return;
            }

            if (modalTitle) modalTitle.textContent = 'APIキーを編集';
            if (form) form.action = `/settings/translation-keys/${id}/update`;
            if (editingIdInput) editingIdInput.value = String(id);

            setField('translation-name', data.name);
            setField('translation-api-key-input', data.api_key);
            setField('translation-api-url', data.api_url || getDeepLApiUrl(data.api_key || ''));
            setField('translation-priority', data.priority ?? 0);
            setField('translation-daily-limit', data.daily_limit ?? '');
            setField('translation-monthly-limit', data.monthly_limit ?? '');
            setField('translation-daily-usage', data.current_daily_usage ?? 0);
            setField('translation-monthly-usage', data.current_monthly_usage ?? 0);
            setField('translation-notes', data.notes ?? '');

            const activeEl = document.getElementById('translation-is-active');
            if (activeEl) activeEl.checked = data.is_active !== false;
            const limitEl = document.getElementById('translation-set-limit-exceeded');
            if (limitEl) limitEl.checked = false;

            if (keyInput) keyInput.required = false;
            if (deeplUsageSection) deeplUsageSection.hidden = false;
            if (limitExceededRow) limitExceededRow.hidden = false;
            if (resultEl) resultEl.textContent = '';
            openModal();
          } catch (e) {
            window.alert('データの取得に失敗しました');
          }
        }

        document.getElementById('translation-add-btn')?.addEventListener('click', openAddModal);
        document.querySelectorAll('.translation-edit-btn').forEach((btn) => {
          btn.addEventListener('click', () => openEditModal(btn.dataset.id));
        });
        document.querySelectorAll('[data-close-translation-modal]').forEach((el) => {
          el.addEventListener('click', closeModal);
        });

        document.getElementById('translation-key-toggle')?.addEventListener('click', () => {
          if (!keyInput) return;
          const show = keyInput.type === 'password';
          keyInput.type = show ? 'text' : 'password';
          const toggleBtn = document.getElementById('translation-key-toggle');
          if (toggleBtn) toggleBtn.textContent = show ? '隠す' : '表示';
        });

        keyInput?.addEventListener('input', updateApiUrlFromKey);

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
                Accept: 'application/json',
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

        fetchUsageBtn?.addEventListener('click', async () => {
          if (!editingId) {
            window.alert('編集モードでのみ使用量を取得できます');
            return;
          }
          fetchUsageBtn.disabled = true;
          const originalText = fetchUsageBtn.textContent;
          fetchUsageBtn.textContent = '取得中...';
          try {
            const res = await fetch(`/settings/translation-keys/${editingId}/fetch-usage`, {
              method: 'POST',
              headers: {
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
              },
            });
            const data = await res.json();
            if (!data.ok) {
              window.alert(data.message || '使用量の取得に失敗しました');
              return;
            }
            setField('translation-monthly-usage', data.character_count ?? 0);
            if (data.character_limit && !document.getElementById('translation-monthly-limit')?.value) {
              setField('translation-monthly-limit', data.character_limit);
            }
            let message = `使用量を取得しました。\n\n文字数: ${(data.character_count ?? 0).toLocaleString()}`;
            if (data.character_limit) {
              message += `\n制限: ${data.character_limit.toLocaleString()}`;
            }
            if (data.is_paid_plan && data.estimated_cost !== null) {
              message += `\n\n【料金情報（推定）】`;
              message += `\n月額基本料金: €${(data.monthly_base_fee ?? 0).toFixed(2)}`;
              message += `\n従量課金: €${(data.usage_cost ?? 0).toFixed(4)}`;
              message += `\n合計（推定）: €${data.estimated_cost.toFixed(2)}`;
              message += '\n\n※ 実際の請求額はDeepLの請求書を確認してください。';
            }
            window.alert(message);
          } catch (e) {
            window.alert('使用量の取得に失敗しました');
          } finally {
            fetchUsageBtn.disabled = false;
            fetchUsageBtn.textContent = originalText;
          }
        });
      })();
    </script>
    @endif
    @if(($section ?? '') === 'ai' && ($aiTab ?? '') === 'chat')
    <script>
      (function () {
        const providers = @json($aiProviders);
        const modal = document.getElementById('ai-key-modal')
        const form = document.getElementById('ai-key-form')
        const modalTitle = document.getElementById('ai-key-modal-title')
        const providerSelect = document.getElementById('ai-key-provider')
        const modelSelect = document.getElementById('ai-key-model')
        const keyInput = document.getElementById('ai-key-input')
        const resultEl = document.getElementById('ai-key-test-result')
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        let editingId = null

        function fillModels(provider, selected) {
          const models = providers[provider]?.models || {}
          const defaultModel = providers[provider]?.default_model || Object.keys(models)[0] || ''
          modelSelect.innerHTML = Object.entries(models).map(([value, label]) =>
            `<option value="${value}">${label}</option>`
          ).join('')
          modelSelect.value = selected && models[selected] ? selected : defaultModel
        }

        function setField(id, value) {
          const el = document.getElementById(id)
          if (el) el.value = value ?? ''
        }

        function openAdd() {
          editingId = null
          modalTitle.textContent = 'チャットAPIキーを追加'
          form.action = '/settings/ai-keys'
          form.reset()
          document.getElementById('ai-key-is-active').checked = true
          fillModels(providerSelect.value)
          keyInput.required = true
          resultEl.textContent = ''
          modal.hidden = false
        }

        async function openEdit(id) {
          const res = await fetch(`/settings/ai-keys/${id}/edit`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }
          })
          if (!res.ok) {
            window.alert('取得に失敗しました')
            return
          }
          const data = await res.json()
          editingId = id
          modalTitle.textContent = 'チャットAPIキーを編集'
          form.action = `/settings/ai-keys/${id}/update`
          setField('ai-key-name', data.name)
          providerSelect.value = data.provider
          fillModels(data.provider, data.default_model)
          document.getElementById('ai-key-plan').value = data.plan || 'paid'
          setField('ai-key-input', '')
          keyInput.required = false
          keyInput.placeholder = '変更する場合のみ入力'
          setField('ai-key-priority', data.priority ?? 0)
          setField('ai-key-daily-limit', data.daily_limit ?? '')
          setField('ai-key-monthly-limit', data.monthly_limit ?? '')
          setField('ai-key-daily-usage', data.current_daily_usage ?? 0)
          setField('ai-key-monthly-usage', data.current_monthly_usage ?? 0)
          setField('ai-key-notes', data.notes ?? '')
          document.getElementById('ai-key-is-active').checked = !!data.is_active
          resultEl.textContent = ''
          modal.hidden = false
        }

        providerSelect?.addEventListener('change', () => fillModels(providerSelect.value))
        document.getElementById('ai-key-add-btn')?.addEventListener('click', openAdd)
        document.querySelectorAll('.ai-key-edit-btn').forEach((btn) => {
          btn.addEventListener('click', () => openEdit(btn.dataset.id))
        })
        document.querySelectorAll('[data-close-ai-key-modal]').forEach((el) => {
          el.addEventListener('click', () => { modal.hidden = true })
        })
        document.getElementById('ai-key-toggle')?.addEventListener('click', () => {
          const isPassword = keyInput.type === 'password'
          keyInput.type = isPassword ? 'text' : 'password'
          document.getElementById('ai-key-toggle').textContent = isPassword ? '隠す' : '表示'
        })
        document.getElementById('ai-key-test-btn')?.addEventListener('click', async () => {
          resultEl.className = 'translation-test-result hint'
          resultEl.textContent = 'テスト中…'
          try {
            const res = await fetch('/settings/ai-keys/test', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
              },
              body: JSON.stringify({
                provider: providerSelect.value,
                api_key: keyInput.value,
                default_model: modelSelect.value,
              }),
            })
            const data = await res.json()
            resultEl.className = 'translation-test-result hint ' + (data.ok ? 'is-ok' : 'is-error')
            resultEl.textContent = data.message || (data.ok ? 'OK' : '失敗')
          } catch (_) {
            resultEl.className = 'translation-test-result hint is-error'
            resultEl.textContent = 'テストに失敗しました'
          }
        })
        fillModels(providerSelect?.value || 'openai')
      })()
    </script>
    @endif
  </body>
</html>
