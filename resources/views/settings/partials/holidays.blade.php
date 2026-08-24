@php
  $holidayBase = $holidayBase ?? '/settings';
  $prevHolidayHref = $prevHolidayHref ?? ($holidayBase.'/holidays');
  $nextHolidayHref = $nextHolidayHref ?? ($holidayBase.'/holidays');
@endphp
<div class="panel" id="weekday-holidays">
  <h2>{{ __('曜日による休日') }}</h2>
  <p class="hint">{{ __('期間内でチェックした曜日を定休日にします。除外日を指定すると、その日だけ休日になりません。') }}</p>

  <form method="post" action="{{ $holidayBase }}/weekday-rules/add" class="weekday-rule-form">
    @csrf
    <input type="hidden" name="year" value="{{ $holidayYear }}" />
    <label>{{ __('名称') }}<input type="text" name="name" placeholder="{{ __('定休日（土日）') }}" /></label>
    <label>{{ __('開始日') }}<input type="date" name="startDate" required /></label>
    <label>{{ __('終了日') }}<input type="date" name="endDate" required /></label>
    <fieldset class="weekday-checkboxes">
      <legend>{{ __('休日にする曜日') }}</legend>
      <div class="weekday-check-row">
        @foreach($weekdayLabels as $index => $label)
          <label class="weekday-check">
            <input type="checkbox" name="weekdays[]" value="{{ $index }}" class="weekday-check-input" data-label="{{ $label }}" />
            {{ $label }}
          </label>
        @endforeach
      </div>
      <div class="selected-preview" id="weekday-selected-preview" hidden>
        <span class="selected-preview-label">{{ __('選択中:') }}</span>
        <span class="selected-preview-items" id="weekday-selected-items"></span>
      </div>
    </fieldset>
    <div class="exception-picker-field">
      <span class="field-label">{{ __('除外日（任意）') }}</span>
      <div class="exception-picker">
        <input type="date" id="new-rule-exception-date" />
        <button type="button" class="mini-btn secondary" id="new-rule-exception-add">{{ __('追加') }}</button>
      </div>
      <div class="selected-preview exception-preview" id="new-rule-exception-preview" hidden>
        <span class="selected-preview-label">{{ __('登録する除外日:') }}</span>
        <span class="selected-preview-items" id="new-rule-exception-items"></span>
      </div>
      <div id="new-rule-exception-hidden"></div>
    </div>
    <button type="submit" class="secondary">{{ __('ルールを追加') }}</button>
  </form>

  @if(count($weekdayRules) === 0)
    <p class="hint empty-hint">{{ __('曜日休日ルールはまだありません。') }}</p>
  @endif

  @foreach($weekdayRules as $rule)
    <div class="weekday-rule-card">
      <div class="weekday-rule-head">
        <strong>{{ $rule['name'] }}</strong>
        <span class="weekday-rule-period">{{ $rule['startDate'] }} 〜 {{ $rule['endDate'] }}</span>
        <form method="post" action="{{ $holidayBase }}/weekday-rules/{{ $rule['id'] }}/delete" class="inline-form" onsubmit='return confirm(@json(__('このルールを削除しますか？')))'>
          @csrf
          <input type="hidden" name="year" value="{{ $holidayYear }}" />
          <button type="submit" class="danger mini-btn">{{ __('削除') }}</button>
        </form>
      </div>
      <div class="weekday-rule-days">
        @foreach($rule['weekdays'] as $dow)
          <span class="weekday-chip">{{ $weekdayLabels[$dow] ?? $dow }}</span>
        @endforeach
      </div>
      <div class="weekday-exceptions">
        <span class="exceptions-label">{{ __('除外日:') }}</span>
        @if(empty($rule['exceptions']))
          <span class="hint inline-hint">{{ __('なし') }}</span>
        @endif
        @foreach($rule['exceptions'] ?? [] as $exDate)
          <form method="post" action="{{ $holidayBase }}/weekday-rules/{{ $rule['id'] }}/exceptions/delete" class="inline-form exception-chip-form">
            @csrf
            <input type="hidden" name="year" value="{{ $holidayYear }}" />
            <input type="hidden" name="date" value="{{ $exDate }}" />
            <button type="submit" class="exception-chip" title="{{ __('除外を解除') }}">{{ $exDate }} ×</button>
          </form>
        @endforeach
        <form method="post" action="{{ $holidayBase }}/weekday-rules/{{ $rule['id'] }}/exceptions/add" class="inline-form exception-add-form">
          @csrf
          <input type="hidden" name="year" value="{{ $holidayYear }}" />
          <input type="date" name="date" required />
          <button type="submit" class="mini-btn secondary">{{ __('除外日を追加') }}</button>
        </form>
      </div>
    </div>
  @endforeach
</div>

<div class="panel">
  <h2>{{ __('休日マスタ') }}</h2>
  <p class="hint">{{ $holidayMasterHint ?? __('日本・フィリピンの祝日を取り込めます。会社独自の休日も追加できます。フィリピン祝日はカレンダーで水色の日付になります。') }}</p>

  <div class="holiday-toolbar">
    <a class="button-link secondary icon-btn" href="{{ $prevHolidayHref }}">‹</a>
    <strong>{{ $holidayYear }}{{ __('年') }}</strong>
    <a class="button-link secondary icon-btn" href="{{ $nextHolidayHref }}">›</a>
    <form method="post" action="{{ $holidayBase }}/holidays/import" class="inline-form">
      @csrf
      <input type="hidden" name="year" value="{{ $holidayYear }}" />
      <input type="hidden" name="country" value="jp" />
      <button type="submit">{{ __('日本の祝日を取り込み') }}</button>
    </form>
    <form method="post" action="{{ $holidayBase }}/holidays/import" class="inline-form">
      @csrf
      <input type="hidden" name="year" value="{{ $holidayYear }}" />
      <input type="hidden" name="country" value="ph" />
      <button type="submit" class="secondary">{{ __('フィリピンの祝日を取り込み') }}</button>
    </form>
  </div>

  <form method="post" action="{{ $holidayBase }}/holidays/add" class="holiday-add-form" id="holiday-add-form">
    @csrf
    <input type="hidden" name="year" value="{{ $holidayYear }}" />
    <div class="holiday-date-block date-mode-row add-form-row">
      <span class="field-label">{{ __('日付') }}</span>
      <div class="date-mode-radios">
        <label class="radio-inline"><input type="radio" name="dateMode" value="single" checked /> {{ __('単日') }}</label>
        <label class="radio-inline"><input type="radio" name="dateMode" value="range" /> {{ __('期間') }}</label>
      </div>
      <div class="date-mode-fields">
        <div class="date-mode-inputs" id="holiday-single-inputs">
          <input type="date" name="date" id="holiday-date-single" required />
        </div>
        <div class="date-mode-inputs date-range-inputs date-panel-hidden" id="holiday-range-inputs">
          <label class="inline-date-label">{{ __('開始') }}<input type="date" name="startDate" id="holiday-date-start" /></label>
          <label class="inline-date-label">{{ __('終了') }}<input type="date" name="endDate" id="holiday-date-end" /></label>
        </div>
      </div>
    </div>
    <label>{{ __('名称') }}<input type="text" name="name" placeholder="{{ __('会社休業日') }}" required /></label>
    <button type="submit" class="secondary">{{ __('休日を追加') }}</button>
  </form>

  <table class="holiday-table">
    <thead>
      <tr><th>{{ __('日付') }}</th><th>{{ __('名称') }}</th><th>{{ __('種別') }}</th><th></th></tr>
    </thead>
    <tbody>
      @if(count($holidays) === 0)
        <tr><td colspan="4" class="empty-cell">{{ __('登録がありません。祝日の取り込みボタンを押してください。') }}</td></tr>
      @endif
      @foreach($holidays as $item)
        <tr>
          <td>{{ $item['date'] }}</td>
          <td>{{ $item['name'] }}</td>
          <td>
            <span class="holiday-type {{ $item['source'] }}">
              @switch($item['source'])
                @case('national') {{ __('日本祝日') }} @break
                @case('national_ph') {{ __('PH祝日') }} @break
                @default {{ __('独自') }}
              @endswitch
            </span>
          </td>
          <td>
            <form method="post" action="{{ $holidayBase }}/holidays/{{ $item['id'] }}/delete" onsubmit='return confirm(@json(__('削除しますか？')))'>
              @csrf
              <input type="hidden" name="year" value="{{ $holidayYear }}" />
              <button type="submit" class="danger mini-btn">{{ __('削除') }}</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
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
