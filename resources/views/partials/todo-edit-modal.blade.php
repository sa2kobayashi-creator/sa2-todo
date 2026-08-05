{{-- ダッシュボード / ToDo カレンダー共用の追加・編集モーダル --}}
@php
  $modalReturnTo = $modalReturnTo ?? ($listReturnTo ?? ($returnTo ?? '/todos'));
@endphp
<div class="modal" id="todo-modal" hidden>
  <div class="modal-backdrop" data-close-todo-modal data-close-modal></div>
  <div class="modal-dialog modal-dialog-wide" role="dialog" aria-labelledby="todo-modal-title">
    <div class="modal-header">
      <h2 id="todo-modal-title">{{ __('ToDo 編集') }}</h2>
      <button type="button" class="modal-close" data-close-todo-modal data-close-modal aria-label="{{ __('閉じる') }}">×</button>
    </div>
    <form method="post" id="todo-edit-form" class="modal-form">
      @csrf
      <input type="hidden" name="returnTo" value="{{ $modalReturnTo }}" />
      <input type="hidden" name="splitByLine" id="modal-split-by-line" value="1" />
      <label>
        <span id="modal-title-label">{{ __('タイトル') }}</span>
        <textarea name="title" id="modal-title" rows="4" required></textarea>
      </label>
      <div class="modal-date-mode" role="group" aria-label="{{ __('指定方法') }}">
        <span class="field-label">{{ __('指定方法') }}</span>
        <label class="radio-inline">
          <input type="radio" name="dateMode" id="modal-date-mode-single" value="single" />
          {{ __('単日') }}
        </label>
        <label class="radio-inline">
          <input type="radio" name="dateMode" id="modal-date-mode-range" value="range" />
          {{ __('期間') }}
        </label>
      </div>
      <div class="modal-date-fields">
        <label id="modal-start-date-label">
          <span id="modal-start-date-text">{{ __('開始日') }}</span>
          <input type="date" name="startDate" id="modal-start-date" />
        </label>
        <label id="modal-end-date-label">
          <span>{{ __('終了日') }}</span>
          <input type="date" name="endDate" id="modal-end-date" />
        </label>
      </div>
      <div class="schedule-option">
        <div class="schedule-toggle-row">
          <label class="schedule-toggle">
            <input type="checkbox" id="modal-enable-time-range" />
            {{ __('時間帯を追加') }}
          </label>
          <label class="schedule-toggle">
            <input type="checkbox" id="modal-enable-time-point" />
            {{ __('指定時間を追加') }}
          </label>
        </div>
        <div class="time-range-panel date-panel-hidden" id="modal-time-range-panel">
          <div class="time-range-inputs">
            <input type="time" name="startTime" id="modal-start-time" aria-label="{{ __('開始時刻') }}" disabled />
            <span class="time-range-separator" id="modal-time-separator" aria-hidden="true">～</span>
            <input type="time" name="endTime" id="modal-end-time" aria-label="{{ __('終了時刻') }}" disabled />
          </div>
        </div>
      </div>
      <label>
        {{ __('重要度') }}
        <select name="importance" id="modal-importance">
          @foreach($importanceLabels as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
          @endforeach
        </select>
      </label>
      <label>
        {{ __('ステータス') }}
        <select name="category" id="modal-category">
          @foreach($categoryLabels as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
          @endforeach
        </select>
      </label>
      @include('partials.todo-notify-settings', [
        'notifyIdPrefix' => 'todo-modal',
        'notifyIsModal' => true,
      ])
      @unless(!empty($appContextIsWork))
      <label id="modal-group-wrap">
        {{ __('共有先') }}
        <select name="groupId" id="modal-group-id">
          <option value="">{{ __('個人（自分のみ）') }}</option>
          @foreach($approvedGroups ?? [] as $group)
            <option value="{{ $group['id'] }}">{{ $group['name'] }}</option>
          @endforeach
        </select>
      </label>
      @endunless
      <label class="inline-check" id="modal-completed-label">
        <input type="checkbox" name="completed" value="1" />
        {{ __('完了') }}
      </label>
      <p class="hint" id="modal-meet-link" hidden></p>
      <p class="hint" id="modal-html-link" hidden></p>
    </form>
    <form method="post" id="todo-delete-form" class="modal-delete-form" onsubmit='return confirm(@json(__('この ToDo を削除しますか？')))'>
      @csrf
      <input type="hidden" name="returnTo" value="{{ $modalReturnTo }}" />
    </form>
    <div class="modal-actions">
      @if(!empty($showDashboardMemoButton))
        <button type="button" class="secondary" id="todo-modal-memo-btn" hidden>{{ __('メモを追加') }}</button>
      @endif
      <button type="button" class="secondary" data-close-todo-modal data-close-modal>{{ __('キャンセル') }}</button>
      <button type="submit" form="todo-edit-form" id="todo-modal-save">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="todo-modal-copy" title="{{ __('コピーして新規作成') }}">{{ __('コピー') }}</button>
      <button type="submit" form="todo-delete-form" class="danger" id="todo-modal-delete">{{ __('削除') }}</button>
    </div>
  </div>
</div>
