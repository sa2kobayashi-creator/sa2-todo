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
      <input type="hidden" name="splitByLine" id="modal-split-by-line" value="0" />
      <div class="todo-modal-title-block">
        <div class="todo-modal-title-label-row">
          <label for="modal-title" id="modal-title-label">{{ __('タイトル（1行1件）') }}</label>
          @php $shortcutCats = $todoShortcuts ?? []; @endphp
          @if(count($shortcutCats) > 0)
            <div class="todo-shortcut-icons" id="todo-shortcut-icons" aria-label="{{ __('クイック入力') }}">
              @foreach($shortcutCats as $cat)
                <button
                  type="button"
                  class="todo-shortcut-icon-btn"
                  data-shortcut-cat-id="{{ $cat['id'] }}"
                  title="{{ $cat['name'] }}"
                  aria-label="{{ $cat['name'] }}"
                >{{ $cat['icon'] }}</button>
              @endforeach
            </div>
          @endif
        </div>
        <input type="text" name="title" id="modal-title" class="todo-modal-title-input" maxlength="255" placeholder="{{ __('買い物に行く') }}" required autocomplete="off" />
        <div class="todo-shortcut-picker" id="todo-shortcut-picker" hidden>
          <div class="todo-shortcut-picker-head">
            <strong id="todo-shortcut-picker-title">{{ __('タイトルを選ぶ') }}</strong>
            <button type="button" class="modal-close" id="todo-shortcut-picker-close" aria-label="{{ __('閉じる') }}">×</button>
          </div>
          <ul class="todo-shortcut-picker-list" id="todo-shortcut-picker-list"></ul>
        </div>
      </div>
      @include('partials.todo-memo-field', ['memoIdPrefix' => 'todo-modal'])
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
    <form method="post" id="todo-shortcut-save-form" action="/todos/shortcuts/titles" hidden>
      @csrf
      <input type="hidden" name="returnTo" id="todo-shortcut-save-return" value="{{ $modalReturnTo }}" />
      <input type="hidden" name="from_todo" value="1" />
      <input type="hidden" name="category_id" id="todo-shortcut-save-category" value="" />
      <input type="hidden" name="title" id="todo-shortcut-save-title" value="" />
      <input type="hidden" name="time_mode" id="todo-shortcut-save-time-mode" value="none" />
      <input type="hidden" name="point_time" id="todo-shortcut-save-point" value="" />
      <input type="hidden" name="start_time" id="todo-shortcut-save-start" value="" />
      <input type="hidden" name="end_time" id="todo-shortcut-save-end" value="" />
      <input type="hidden" name="notifyVia" id="todo-shortcut-save-notify" value="" />
      <input type="hidden" name="reminderTime" id="todo-shortcut-save-reminder-time" value="" />
      <div id="todo-shortcut-save-reminders"></div>
    </form>
    <div class="todo-shortcut-save-picker" id="todo-shortcut-save-picker" hidden>
      <div class="todo-shortcut-picker-head">
        <strong>{{ __('登録先カテゴリ') }}</strong>
        <button type="button" class="modal-close" id="todo-shortcut-save-picker-close" aria-label="{{ __('閉じる') }}">×</button>
      </div>
      <ul class="todo-shortcut-picker-list" id="todo-shortcut-save-picker-list"></ul>
    </div>
    <div class="modal-actions">
      @if(!empty($showDashboardMemoButton))
        <button type="button" class="secondary" id="todo-modal-memo-btn" hidden>{{ __('メモを追加') }}</button>
      @endif
      <button type="button" class="secondary" data-close-todo-modal data-close-modal>{{ __('キャンセル') }}</button>
      <button type="submit" form="todo-edit-form" id="todo-modal-save">{{ __('保存') }}</button>
      <button type="button" class="secondary" id="todo-modal-copy" title="{{ __('コピーして新規作成') }}">{{ __('コピー') }}</button>
      <button type="button" class="secondary" id="todo-modal-save-shortcut" title="{{ __('いまの内容をクイック入力に登録') }}">{{ __('クイック入力に追加') }}</button>
      <button type="submit" form="todo-delete-form" class="danger" id="todo-modal-delete">{{ __('削除') }}</button>
    </div>
  </div>
</div>
