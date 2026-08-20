@php
  $shortcuts = $todoShortcuts ?? [];
  $iconChoices = $todoShortcutIcons ?? \App\Services\TodoShortcutService::ICON_CHOICES;
  $settingsReturnTo = '/todos?display=settings';
@endphp
<section class="panel todo-shortcut-settings" id="todo-shortcut-settings">
  <h3>{{ __('クイック入力設定') }}</h3>
  <p class="hint">{{ __('カテゴリにアイコンとタイトル（時間・通知は任意）を登録すると、ToDo 追加モーダルのタイトル横から素早く選べます。') }}</p>

  <details class="todo-shortcut-fold todo-shortcut-add-cat-fold">
    <summary class="todo-shortcut-fold-summary">
      <span>{{ __('カテゴリを追加') }}</span>
      <span class="todo-shortcut-fold-caret" aria-hidden="true">▾</span>
    </summary>
    <form method="post" action="/todos/shortcuts/categories" class="todo-shortcut-card todo-shortcut-add-cat">
      @csrf
      <input type="hidden" name="returnTo" value="{{ $settingsReturnTo }}" />
      <label class="todo-shortcut-field">
        <span>{{ __('カテゴリ名') }}</span>
        <input type="text" name="name" class="todo-shortcut-input" maxlength="40" required placeholder="{{ __('例: 移動') }}" autocomplete="off" />
      </label>
      <fieldset class="todo-shortcut-icon-pick">
        <legend>{{ __('アイコン') }}</legend>
        <div class="todo-shortcut-icon-choices">
          @foreach($iconChoices as $i => $icon)
            <label class="todo-shortcut-icon-choice">
              <input type="radio" name="icon" value="{{ $icon }}" @checked($i === 0) required />
              <span aria-hidden="true">{{ $icon }}</span>
            </label>
          @endforeach
        </div>
      </fieldset>
      <button type="submit" class="todo-shortcut-primary-btn">{{ __('カテゴリを追加') }}</button>
    </form>
  </details>

  @if(count($shortcuts) === 0)
    <p class="empty-msg">{{ __('まだカテゴリがありません。上から追加してください。') }}</p>
  @endif

  <div class="todo-shortcut-cat-list">
    @foreach($shortcuts as $cat)
      @php
        $titles = $cat['titles'] ?? [];
      @endphp
      <article class="todo-shortcut-card todo-shortcut-cat-card">
        <header class="todo-shortcut-cat-head">
          <span class="todo-shortcut-cat-icon" aria-hidden="true">{{ $cat['icon'] }}</span>
          <div class="todo-shortcut-cat-meta">
            <strong>{{ $cat['name'] }}</strong>
            <span>{{ __(':count件のタイトル', ['count' => count($titles)]) }}</span>
          </div>
          <form method="post" action="/todos/shortcuts/categories/{{ $cat['id'] }}/delete" class="todo-shortcut-inline-form" onsubmit='return confirm(@json(__('このカテゴリと登録タイトルを削除しますか？')))'>
            @csrf
            <input type="hidden" name="returnTo" value="{{ $settingsReturnTo }}" />
            <button type="submit" class="todo-shortcut-text-danger">{{ __('削除') }}</button>
          </form>
        </header>

        <details class="todo-shortcut-fold todo-shortcut-edit-details">
          <summary class="todo-shortcut-fold-summary">
            <span>{{ __('カテゴリを編集') }}</span>
            <span class="todo-shortcut-fold-caret" aria-hidden="true">▾</span>
          </summary>
          <form method="post" action="/todos/shortcuts/categories/{{ $cat['id'] }}/update" class="todo-shortcut-edit-cat">
            @csrf
            <input type="hidden" name="returnTo" value="{{ $settingsReturnTo }}" />
            <label class="todo-shortcut-field">
              <span>{{ __('カテゴリ名') }}</span>
              <input type="text" name="name" class="todo-shortcut-input" value="{{ $cat['name'] }}" maxlength="40" required autocomplete="off" />
            </label>
            <fieldset class="todo-shortcut-icon-pick">
              <legend>{{ __('アイコン') }}</legend>
              <div class="todo-shortcut-icon-choices">
                @foreach($iconChoices as $icon)
                  <label class="todo-shortcut-icon-choice">
                    <input type="radio" name="icon" value="{{ $icon }}" @checked($cat['icon'] === $icon) required />
                    <span aria-hidden="true">{{ $icon }}</span>
                  </label>
                @endforeach
              </div>
            </fieldset>
            <button type="submit" class="todo-shortcut-secondary-btn">{{ __('カテゴリを保存') }}</button>
          </form>
        </details>

        <ul class="todo-shortcut-title-list">
          @foreach($titles as $row)
            @php
              $hasStart = !empty($row['startTime']);
              $hasEnd = !empty($row['endTime']) && ($row['endTime'] !== ($row['startTime'] ?? null));
              $timeMode = ! $hasStart ? 'none' : ($hasEnd ? 'range' : 'point');
              $timeMeta = '';
              if ($timeMode === 'point') {
                $timeMeta = $row['startTime'];
              } elseif ($timeMode === 'range') {
                $timeMeta = ($row['startTime'] ?? '').'～'.($row['endTime'] ?? '');
              }
            @endphp
            <li class="todo-shortcut-title-item">
              <details class="todo-shortcut-fold todo-shortcut-title-fold">
                <summary class="todo-shortcut-fold-summary todo-shortcut-title-summary">
                  <span class="todo-shortcut-title-summary-text">{{ $row['title'] }}</span>
                  @if($timeMeta !== '')
                    <span class="todo-shortcut-title-summary-meta">{{ $timeMeta }}</span>
                  @endif
                  <span class="todo-shortcut-fold-caret" aria-hidden="true">▾</span>
                </summary>
                <div class="todo-shortcut-fold-body">
                  <form method="post" action="/todos/shortcuts/titles/{{ $row['id'] }}/update" class="todo-shortcut-title-form" data-todo-shortcut-time>
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $settingsReturnTo }}" />
                    <label class="todo-shortcut-field">
                      <span>{{ __('タイトル') }}</span>
                      <input type="text" name="title" class="todo-shortcut-input" value="{{ $row['title'] }}" maxlength="255" required autocomplete="off" />
                    </label>
                    <div class="todo-shortcut-time-mode" role="group" aria-label="{{ __('時間（任意）') }}">
                      <span class="todo-shortcut-field-label">{{ __('時間（任意）') }}</span>
                      <div class="todo-shortcut-time-toggles">
                        <label class="todo-shortcut-chip">
                          <input type="radio" name="time_mode" value="none" @checked($timeMode === 'none') />
                          <span>{{ __('なし') }}</span>
                        </label>
                        <label class="todo-shortcut-chip">
                          <input type="radio" name="time_mode" value="point" @checked($timeMode === 'point') />
                          <span>{{ __('短時間') }}</span>
                        </label>
                        <label class="todo-shortcut-chip">
                          <input type="radio" name="time_mode" value="range" @checked($timeMode === 'range') />
                          <span>{{ __('時間帯') }}</span>
                        </label>
                      </div>
                    </div>
                    <div class="todo-shortcut-time-fields" data-time-panel="point" @if($timeMode !== 'point') hidden @endif>
                      <label class="todo-shortcut-field">
                        <span>{{ __('時刻') }}</span>
                        <input type="time" name="point_time" class="todo-shortcut-input" value="{{ $timeMode === 'point' ? ($row['startTime'] ?? '') : '' }}" />
                      </label>
                    </div>
                    <div class="todo-shortcut-time-fields todo-shortcut-time-range" data-time-panel="range" @if($timeMode !== 'range') hidden @endif>
                      <label class="todo-shortcut-field">
                        <span>{{ __('開始') }}</span>
                        <input type="time" name="start_time" class="todo-shortcut-input" value="{{ $timeMode === 'range' ? ($row['startTime'] ?? '') : '' }}" />
                      </label>
                      <span class="todo-shortcut-time-sep" aria-hidden="true">～</span>
                      <label class="todo-shortcut-field">
                        <span>{{ __('終了') }}</span>
                        <input type="time" name="end_time" class="todo-shortcut-input" value="{{ $timeMode === 'range' ? ($row['endTime'] ?? '') : '' }}" />
                      </label>
                    </div>
                    <div class="todo-shortcut-notify" data-shortcut-notify>
                      @include('partials.todo-notify-settings', [
                        'notifyIdPrefix' => 'todo-shortcut-'.$row['id'],
                        'selectedReminders' => $row['reminders'] ?? [],
                        'selectedNotifyVia' => $row['notifyVia'] ?? null,
                        'selectedReminderTime' => $row['reminderTime'] ?? null,
                      ])
                    </div>
                    <div class="todo-shortcut-title-actions">
                      <button type="submit" class="todo-shortcut-secondary-btn">{{ __('保存') }}</button>
                    </div>
                  </form>
                  <form method="post" action="/todos/shortcuts/titles/{{ $row['id'] }}/delete" class="todo-shortcut-inline-form todo-shortcut-title-delete" onsubmit='return confirm(@json(__('このタイトルを削除しますか？')))'>
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ $settingsReturnTo }}" />
                    <button type="submit" class="todo-shortcut-text-danger">{{ __('このタイトルを削除') }}</button>
                  </form>
                </div>
              </details>
            </li>
          @endforeach
        </ul>

        <details class="todo-shortcut-fold todo-shortcut-add-title-fold">
          <summary class="todo-shortcut-fold-summary">
            <span>{{ __('タイトルを追加') }}</span>
            <span class="todo-shortcut-fold-caret" aria-hidden="true">▾</span>
          </summary>
          <form method="post" action="/todos/shortcuts/titles" class="todo-shortcut-title-form todo-shortcut-add-title" data-todo-shortcut-time>
            @csrf
            <input type="hidden" name="returnTo" value="{{ $settingsReturnTo }}" />
            <input type="hidden" name="category_id" value="{{ $cat['id'] }}" />
            <label class="todo-shortcut-field">
              <span>{{ __('タイトル') }}</span>
              <input type="text" name="title" class="todo-shortcut-input" maxlength="255" required placeholder="{{ __('例: 松本整形外科に行く') }}" autocomplete="off" />
            </label>
            <div class="todo-shortcut-time-mode" role="group" aria-label="{{ __('時間（任意）') }}">
              <span class="todo-shortcut-field-label">{{ __('時間（任意）') }}</span>
              <div class="todo-shortcut-time-toggles">
                <label class="todo-shortcut-chip">
                  <input type="radio" name="time_mode" value="none" checked />
                  <span>{{ __('なし') }}</span>
                </label>
                <label class="todo-shortcut-chip">
                  <input type="radio" name="time_mode" value="point" />
                  <span>{{ __('短時間') }}</span>
                </label>
                <label class="todo-shortcut-chip">
                  <input type="radio" name="time_mode" value="range" />
                  <span>{{ __('時間帯') }}</span>
                </label>
              </div>
            </div>
            <div class="todo-shortcut-time-fields" data-time-panel="point" hidden>
              <label class="todo-shortcut-field">
                <span>{{ __('時刻') }}</span>
                <input type="time" name="point_time" class="todo-shortcut-input" />
              </label>
            </div>
            <div class="todo-shortcut-time-fields todo-shortcut-time-range" data-time-panel="range" hidden>
              <label class="todo-shortcut-field">
                <span>{{ __('開始') }}</span>
                <input type="time" name="start_time" class="todo-shortcut-input" />
              </label>
              <span class="todo-shortcut-time-sep" aria-hidden="true">～</span>
              <label class="todo-shortcut-field">
                <span>{{ __('終了') }}</span>
                <input type="time" name="end_time" class="todo-shortcut-input" />
              </label>
            </div>
            <div class="todo-shortcut-notify" data-shortcut-notify>
              @include('partials.todo-notify-settings', [
                'notifyIdPrefix' => 'todo-shortcut-new-'.$cat['id'],
              ])
            </div>
            <button type="submit" class="todo-shortcut-primary-btn">{{ __('タイトルを追加') }}</button>
          </form>
        </details>
      </article>
    @endforeach
  </div>
</section>

<script>
  (() => {
    function selectedTimeMode(form) {
      const checked = form.querySelector('input[name="time_mode"]:checked');
      return checked ? checked.value : 'none';
    }

    function syncTimePanels(form) {
      const mode = selectedTimeMode(form);
      form.querySelectorAll('[data-time-panel]').forEach((panel) => {
        const on = panel.getAttribute('data-time-panel') === mode;
        panel.hidden = !on;
        panel.querySelectorAll('input').forEach((input) => {
          input.disabled = !on;
        });
      });
      syncNotify(form);
    }

    function syncNotify(form) {
      const mode = selectedTimeMode(form);
      const ready = mode === 'point' || mode === 'range';
      const wrap = form.querySelector('[data-notify-settings]');
      if (!wrap) return;
      wrap.classList.toggle('is-disabled', !ready);
      wrap.querySelectorAll('input, select, textarea').forEach((el) => {
        el.disabled = !ready;
      });
    }

    document.querySelectorAll('[data-todo-shortcut-time]').forEach((form) => {
      syncTimePanels(form);
      form.querySelectorAll('input[name="time_mode"]').forEach((radio) => {
        radio.addEventListener('change', function () { syncTimePanels(form); });
      });
    });
  })();
</script>
