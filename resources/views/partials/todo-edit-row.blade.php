<tr class="edit-row">
  <td colspan="7">
  <div class="edit-form-wrap">
    <form method="post" action="/todos/{{ $todo['id'] }}/update" class="edit-form">
      @csrf
      <input type="hidden" name="returnTo" value="{{ $listReturnTo }}" />
      <textarea name="title" rows="3" required>{{ $todo['title'] }}</textarea>
      <div class="add-form-grid">
        <div class="form-grid-row form-grid-row-labels">
          <span class="field-label">{{ __('開始日') }}</span>
          <span class="field-label">{{ __('終了日') }}</span>
          <span class="field-label">{{ __('重要度') }}</span>
          <span class="field-label">{{ __('ステータス') }}</span>
        </div>
        <div class="form-grid-row form-grid-row-inputs">
          <div class="form-grid-cell">
            <input type="date" name="startDate" value="{{ $todo['startDate'] ?? '' }}" aria-label="{{ __('開始日') }}" />
          </div>
          <div class="form-grid-cell">
            <input type="date" name="endDate" value="{{ $todo['endDate'] ?? '' }}" aria-label="{{ __('終了日') }}" />
          </div>
          <div class="form-grid-cell">
            <select name="importance" aria-label="{{ __('重要度') }}">
              @foreach($importanceLabels as $value => $label)
                <option value="{{ $value }}" @selected(($todo['importance'] ?? '') === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="form-grid-cell">
            <select name="category" aria-label="{{ __('ステータス') }}">
              @foreach($categoryLabels as $value => $label)
                <option value="{{ $value }}" @selected(($todo['category'] ?? '') === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="schedule-option edit-schedule-option">
          <label class="schedule-toggle">
            <input type="checkbox" class="edit-enable-time-range" @checked(!empty($todo['startTime'])) />
            {{ __('時間帯を追加') }}
          </label>
          <div class="time-range-panel {{ empty($todo['startTime']) ? 'date-panel-hidden' : '' }} edit-time-range-panel">
            <div class="time-range-inputs">
              <input type="time" name="startTime" value="{{ $todo['startTime'] ?? '' }}" aria-label="{{ __('開始時刻') }}" @disabled(empty($todo['startTime'])) />
              <span class="time-range-separator" aria-hidden="true">～</span>
              <input type="time" name="endTime" value="{{ $todo['endTime'] ?? '' }}" aria-label="{{ __('終了時刻') }}" @disabled(empty($todo['startTime'])) />
            </div>
          </div>
        </div>
        <fieldset class="reminder-checkboxes edit-reminders">
          <legend>{{ __('通知タイミング') }}</legend>
          <div class="reminder-check-row">
            @foreach($reminderOptions as $key)
              <label class="reminder-check">
                <input type="checkbox" name="reminders" value="{{ $key }}" @checked(in_array($key, $todo['reminders'] ?? [], true)) />
                {{ $reminderLabels[$key] }}
              </label>
            @endforeach
          </div>
        </fieldset>
        <fieldset class="notify-via-fieldset edit-notify-via">
          <legend>{{ __('通知方法') }}</legend>
          <div class="notify-via-row">
            @foreach($notifyViaOptions as $key)
              <label class="notify-via-option">
                <input type="radio" name="notifyVia" value="{{ $key }}" @checked(($todo['notifyVia'] ?? null) === $key) />
                {{ $notifyViaLabels[$key] }}
              </label>
            @endforeach
          </div>
          <p class="hint inline-hint">{{ __('通知タイミングを選ぶ場合は、いずれか1つを選択してください。') }}</p>
        </fieldset>
      </div>
      <label class="inline-check">
        <input type="checkbox" name="completed" value="1" @checked(!empty($todo['completed'])) />
        {{ __('完了') }}
      </label>
      <div class="form-actions">
        <button type="submit">{{ __('保存') }}</button>
        <a class="button-link secondary" href="{{ $listReturnTo }}">{{ __('キャンセル') }}</a>
      </div>
    </form>
  </div>
  </td>
</tr>
