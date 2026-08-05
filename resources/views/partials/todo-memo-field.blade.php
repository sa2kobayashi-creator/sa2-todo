@php
  $memoIdPrefix = $memoIdPrefix ?? 'todo-memo';
  $memoValue = trim((string) ($memoValue ?? ''));
  $memoEnabled = $memoEnabled ?? ($memoValue !== '');
@endphp
<div class="todo-memo-option" data-todo-memo>
  <label class="schedule-toggle">
    <input type="checkbox" class="todo-enable-memo" id="{{ $memoIdPrefix }}-enable" @checked($memoEnabled) />
    {{ __('メモ') }}
  </label>
  <div class="todo-memo-panel {{ $memoEnabled ? '' : 'date-panel-hidden' }}">
    <textarea
      name="memo"
      id="{{ $memoIdPrefix }}-input"
      class="todo-memo-input"
      rows="4"
      maxlength="10000"
      placeholder="{{ __('メモを入力') }}"
    >{{ $memoValue }}</textarea>
  </div>
</div>
@once
<script>
(function () {
  if (window.syncTodoMemoFields) return;

  function syncWrap(wrap) {
    const enabled = Boolean(wrap.querySelector('.todo-enable-memo')?.checked);
    const panel = wrap.querySelector('.todo-memo-panel');
    const input = wrap.querySelector('.todo-memo-input');
    panel?.classList.toggle('date-panel-hidden', !enabled);
    if (!enabled && input) input.value = '';
  }

  window.syncTodoMemoFields = function (scope) {
    const root = scope && scope.querySelectorAll ? scope : document;
    if (scope && scope.matches && scope.matches('[data-todo-memo]')) {
      syncWrap(scope);
      return;
    }
    root.querySelectorAll('[data-todo-memo]').forEach(syncWrap);
  };

  window.fillTodoMemoField = function (scope, memo) {
    const wrap = scope?.querySelector ? scope.querySelector('[data-todo-memo]') : null;
    if (!wrap) return;
    const input = wrap.querySelector('.todo-memo-input');
    const checkbox = wrap.querySelector('.todo-enable-memo');
    const value = String(memo || '').trim();
    if (input) input.value = value;
    if (checkbox) checkbox.checked = value !== '';
    const panel = wrap.querySelector('.todo-memo-panel');
    panel?.classList.toggle('date-panel-hidden', value === '');
  };

  document.addEventListener('change', (e) => {
    const target = e.target;
    if (!target || !target.matches || !target.matches('.todo-enable-memo')) return;
    const wrap = target.closest('[data-todo-memo]');
    if (!wrap) return;
    const wasEnabled = target.checked;
    const input = wrap.querySelector('.todo-memo-input');
    const kept = wasEnabled ? String(input?.dataset.keptMemo || '') : String(input?.value || '');
    if (!wasEnabled && input) {
      input.dataset.keptMemo = kept;
      input.value = '';
    } else if (wasEnabled && input && !input.value && input.dataset.keptMemo) {
      input.value = input.dataset.keptMemo;
    }
    wrap.querySelector('.todo-memo-panel')?.classList.toggle('date-panel-hidden', !wasEnabled);
    if (wasEnabled) input?.focus();
  });

  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!form || !form.querySelectorAll) return;
    form.querySelectorAll('[data-todo-memo]').forEach((wrap) => {
      const enabled = Boolean(wrap.querySelector('.todo-enable-memo')?.checked);
      const input = wrap.querySelector('.todo-memo-input');
      if (!enabled && input) input.value = '';
    });
  }, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => window.syncTodoMemoFields());
  } else {
    window.syncTodoMemoFields();
  }
})();
</script>
@endonce
