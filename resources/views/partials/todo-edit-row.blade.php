<tr class="edit-row">
  <td colspan="7">
  <div class="edit-form-wrap">
    <form method="post" action="/todos/{{ $todo['id'] }}/update" class="edit-form">
      @csrf
      <input type="hidden" name="returnTo" value="{{ $listReturnTo }}" />
      <label>
        <span>{{ __('タイトル（1行1件）') }}</span>
        <input type="text" name="title" value="{{ $todo['title'] }}" maxlength="255" required autocomplete="off" />
      </label>
      @include('partials.todo-memo-field', [
        'memoIdPrefix' => 'todo-edit-'.$todo['id'],
        'memoValue' => $todo['memo'] ?? '',
      ])
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
          <div class="schedule-toggle-row">
            <label class="schedule-toggle">
              <input type="checkbox" class="edit-enable-time-range" @checked(!empty($todo['startTime']) && !empty($todo['endTime'])) />
              {{ __('時間帯を追加') }}
            </label>
            <label class="schedule-toggle">
              <input type="checkbox" class="edit-enable-time-point" @checked(!empty($todo['startTime']) && empty($todo['endTime'])) />
              {{ __('指定時間を追加') }}
            </label>
          </div>
          <div class="time-range-panel {{ empty($todo['startTime']) ? 'date-panel-hidden' : '' }} edit-time-range-panel">
            <div class="time-range-inputs">
              <input type="time" name="startTime" value="{{ $todo['startTime'] ?? '' }}" aria-label="{{ __('開始時刻') }}" @disabled(empty($todo['startTime'])) />
              <span class="time-range-separator edit-time-separator {{ empty($todo['endTime']) ? 'date-panel-hidden' : '' }}" aria-hidden="true">～</span>
              <input type="time" name="endTime" class="edit-end-time {{ empty($todo['endTime']) ? 'date-panel-hidden' : '' }}" value="{{ $todo['endTime'] ?? '' }}" aria-label="{{ __('終了時刻') }}" @disabled(empty($todo['endTime'])) />
            </div>
          </div>
        </div>
        @include('partials.todo-notify-settings', [
          'notifyIdPrefix' => 'todo-edit-'.$todo['id'],
          'selectedReminders' => $todo['reminders'] ?? [],
          'selectedNotifyVia' => $todo['notifyVia'] ?? null,
          'selectedReminderTime' => $todo['reminderTime'] ?? null,
        ])
      </div>
      <label class="inline-check">
        <input type="checkbox" name="completed" value="1" @checked(!empty($todo['completed'])) />
        {{ __('完了') }}
      </label>
      @if(!empty($todo['googleMeetLink']))
        <p class="hint todo-meet-link">
          {{ __('Google Meet') }}:
          <a href="{{ $todo['googleMeetLink'] }}" target="_blank" rel="noopener noreferrer">{{ $todo['googleMeetLink'] }}</a>
        </p>
      @endif
      @if(!empty($todo['htmlLink']))
        <p class="hint todo-gcal-link">
          {{ __('Google カレンダー') }}:
          <a href="{{ $todo['htmlLink'] }}" target="_blank" rel="noopener noreferrer">{{ __('予定を開く') }}</a>
        </p>
      @endif
      <div class="form-actions">
        <button type="submit">{{ __('保存') }}</button>
        <a class="button-link secondary" href="{{ $listReturnTo }}">{{ __('キャンセル') }}</a>
      </div>
    </form>
  </div>
  </td>
</tr>
