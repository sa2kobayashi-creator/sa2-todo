@php
  $notifyIdPrefix = $notifyIdPrefix ?? 'notify';
  $selectedReminders = $selectedReminders ?? [];
  $selectedNotifyVia = $selectedNotifyVia ?? null;
  $selectedReminderTime = $selectedReminderTime ?? \App\Services\TodoService::reminderTimeFromList(is_array($selectedReminders) ? $selectedReminders : []);
  $uiReminderKeys = \App\Services\TodoService::reminderUiKeys(is_array($selectedReminders) ? $selectedReminders : []);
  $fixedTimingOptions = ['10min', '30min', '1hour', '1day'];
  $isModal = ! empty($notifyIsModal);
@endphp
<div class="notify-settings-wrap is-disabled" data-notify-settings>
<fieldset class="notify-settings">
  <legend>{{ __('通知設定') }}</legend>
  <div class="notify-settings-grid">
    <div class="notify-settings-col">
      <div class="notify-settings-heading">{{ __('通知方法') }}</div>
      <div class="notify-method-list" role="radiogroup" aria-label="{{ __('通知方法') }}">
        @foreach($notifyViaOptions as $key)
          <label class="notify-option">
            <input
              type="radio"
              name="notifyVia"
              value="{{ $key }}"
              @if($isModal) data-modal-notify="{{ $key }}" @endif
              @checked($selectedNotifyVia === $key)
            />
            <span>{{ $notifyViaLabels[$key] ?? $key }}</span>
          </label>
        @endforeach
      </div>
    </div>
    <div class="notify-settings-col">
      <div class="notify-settings-heading">{{ __('通知タイミング') }}</div>
      <div class="notify-timing-list">
        <div class="notify-timing-top-row">
          <label class="notify-option">
            <input
              type="checkbox"
              name="reminders"
              value="5min"
              @if($isModal) data-modal-reminder="5min" @endif
              @checked(in_array('5min', $uiReminderKeys, true))
            />
            <span>{{ $reminderLabels['5min'] ?? __('5分前') }}</span>
          </label>
          <label class="notify-option notify-at-time-option">
            <input
              type="checkbox"
              name="reminders"
              value="at_time"
              id="{{ $notifyIdPrefix }}-at-time"
              @if($isModal) data-modal-reminder="at_time" @endif
              @checked(in_array('at_time', $uiReminderKeys, true))
            />
            <span>{{ __('当日') }}</span>
            <input
              type="time"
              name="reminderTime"
              id="{{ $notifyIdPrefix }}-reminder-time"
              value="{{ $selectedReminderTime ?? '' }}"
              @if($isModal) data-modal-reminder-time="1" @endif
              aria-label="{{ __('当日の通知時刻') }}"
            />
          </label>
        </div>
        @foreach($fixedTimingOptions as $key)
          <label class="notify-option">
            <input
              type="checkbox"
              name="reminders"
              value="{{ $key }}"
              @if($isModal) data-modal-reminder="{{ $key }}" @endif
              @checked(in_array($key, $uiReminderKeys, true))
            />
            <span>{{ $reminderLabels[$key] ?? $key }}</span>
          </label>
        @endforeach
      </div>
    </div>
  </div>
</fieldset>
<p class="hint notify-settings-note">{{ __('※当日は時間指定') }}</p>
<p class="hint notify-settings-disabled-hint">{{ __('時間を追加すると通知を設定できます。') }}</p>
</div>
@once
<script>
(function () {
  if (window.syncTodoNotifySettings) return;

  function isTimeReady(form) {
    if (!form) return false;
    const enableRange = form.querySelector('#enable-time-range, #modal-enable-time-range, .edit-enable-time-range');
    const enablePoint = form.querySelector('#enable-time-point, #modal-enable-time-point, .edit-enable-time-point');
    const startTime = form.querySelector('#modal-start-time, #todo-start-time, input[name="startTime"]');
    if ((enableRange || enablePoint) && !enableRange?.checked && !enablePoint?.checked) return false;
    return Boolean(startTime && startTime.value && !startTime.disabled);
  }

  function syncWrap(wrap) {
    const ready = isTimeReady(wrap.closest('form'));
    wrap.classList.toggle('is-disabled', !ready);
    wrap.querySelectorAll('input, select, textarea').forEach((el) => {
      el.disabled = !ready;
    });
  }

  window.syncTodoNotifySettings = function (scope) {
    const root = scope && scope.querySelectorAll ? scope : document;
    if (scope && scope.matches && scope.matches('[data-notify-settings]')) {
      syncWrap(scope);
      return;
    }
    root.querySelectorAll('[data-notify-settings]').forEach(syncWrap);
  };

  document.addEventListener('change', (e) => {
    const target = e.target;
    if (!target || !target.matches) return;
    if (!target.matches('#enable-time-range, #modal-enable-time-range, .edit-enable-time-range, #enable-time-point, #modal-enable-time-point, .edit-enable-time-point, #todo-start-time, #modal-start-time, input[name="startTime"]')) {
      return;
    }
    window.syncTodoNotifySettings(target.closest('form') || document);
  });
  document.addEventListener('input', (e) => {
    const target = e.target;
    if (!target || !target.matches) return;
    if (!target.matches('#todo-start-time, #modal-start-time, input[name="startTime"]')) return;
    window.syncTodoNotifySettings(target.closest('form') || document);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.syncTodoNotifySettings());
  } else {
    window.syncTodoNotifySettings();
  }
})();
</script>
@endonce
