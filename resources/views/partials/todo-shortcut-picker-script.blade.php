{{-- ToDo 追加モーダルのクイック入力アイコン／タイトル選択／予定から登録 --}}
{{-- Blade が ?. をディレクティブと誤認するため、オプショナルチェーンは使わない --}}
<script>
  (() => {
    const shortcuts = @json($todoShortcuts ?? []);
    const list = Array.isArray(shortcuts) ? shortcuts : [];
    const byId = {};
    list.forEach((c) => { byId[String(c.id)] = c; });

    const iconsEl = document.getElementById('todo-shortcut-icons');
    const picker = document.getElementById('todo-shortcut-picker');
    const pickerTitle = document.getElementById('todo-shortcut-picker-title');
    const pickerList = document.getElementById('todo-shortcut-picker-list');
    const pickerClose = document.getElementById('todo-shortcut-picker-close');
    const titleInput = document.getElementById('modal-title');
    const editForm = document.getElementById('todo-edit-form');
    const todoModal = document.getElementById('todo-modal');
    const saveBtn = document.getElementById('todo-modal-save-shortcut');
    const saveForm = document.getElementById('todo-shortcut-save-form');
    const savePicker = document.getElementById('todo-shortcut-save-picker');
    const savePickerList = document.getElementById('todo-shortcut-save-picker-list');
    const savePickerClose = document.getElementById('todo-shortcut-save-picker-close');
    const msgNeedTitle = @json(__('タイトルを入力してください。'));
    const msgNeedCategory = @json(__('先に設定からクイック入力カテゴリを追加してください。設定ページを開きますか？'));

    function closeSavePicker() {
      if (savePicker) savePicker.hidden = true;
      if (savePickerList) savePickerList.innerHTML = '';
    }

    function modalTimeMode() {
      const rangeOn = !!(document.getElementById('modal-enable-time-range')
        && document.getElementById('modal-enable-time-range').checked);
      const pointOn = !!(document.getElementById('modal-enable-time-point')
        && document.getElementById('modal-enable-time-point').checked);
      if (rangeOn) return 'range';
      if (pointOn) return 'point';
      return 'none';
    }

    function submitSaveToCategory(catId) {
      if (!saveForm || !editForm) return;
      const title = titleInput ? String(titleInput.value || '').trim() : '';
      if (title === '') {
        window.alert(msgNeedTitle);
        return;
      }

      const returnEl = document.getElementById('todo-shortcut-save-return');
      const returnFromEdit = editForm.querySelector('input[name="returnTo"]');
      if (returnEl && returnFromEdit) returnEl.value = returnFromEdit.value || returnEl.value;

      document.getElementById('todo-shortcut-save-category').value = String(catId);
      document.getElementById('todo-shortcut-save-title').value = title;

      const mode = modalTimeMode();
      const startEl = document.getElementById('modal-start-time');
      const endEl = document.getElementById('modal-end-time');
      const startVal = startEl ? String(startEl.value || '').trim() : '';
      const endVal = endEl ? String(endEl.value || '').trim() : '';
      document.getElementById('todo-shortcut-save-time-mode').value = mode;
      document.getElementById('todo-shortcut-save-point').value = mode === 'point' ? startVal : '';
      document.getElementById('todo-shortcut-save-start').value = mode === 'range' ? startVal : '';
      document.getElementById('todo-shortcut-save-end').value = mode === 'range' ? endVal : '';

      let notifyVia = '';
      editForm.querySelectorAll('[data-modal-notify]').forEach(function (radio) {
        if (radio.checked && !radio.disabled) notifyVia = String(radio.value || '');
      });
      document.getElementById('todo-shortcut-save-notify').value = notifyVia;

      const reminderTimeEl = editForm.querySelector('[data-modal-reminder-time]');
      document.getElementById('todo-shortcut-save-reminder-time').value =
        reminderTimeEl ? String(reminderTimeEl.value || '') : '';

      const remindersWrap = document.getElementById('todo-shortcut-save-reminders');
      if (remindersWrap) {
        remindersWrap.innerHTML = '';
        editForm.querySelectorAll('[data-modal-reminder]').forEach(function (cb) {
          if (!cb.checked || cb.disabled) return;
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'reminders';
          input.value = String(cb.value || '');
          remindersWrap.appendChild(input);
        });
      }

      closeSavePicker();
      saveForm.submit();
    }

    function openSavePicker() {
      if (!savePicker || !savePickerList) return;
      savePickerList.innerHTML = '';
      list.forEach(function (cat) {
        const li = document.createElement('li');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'todo-shortcut-picker-item';
        btn.textContent = (cat.icon || '') + ' ' + (cat.name || '');
        btn.addEventListener('click', function () { submitSaveToCategory(cat.id); });
        li.appendChild(btn);
        savePickerList.appendChild(li);
      });
      savePicker.hidden = false;
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const title = titleInput ? String(titleInput.value || '').trim() : '';
        if (title === '') {
          window.alert(msgNeedTitle);
          if (titleInput) titleInput.focus();
          return;
        }
        if (list.length === 0) {
          if (window.confirm(msgNeedCategory)) {
            window.location.href = '/todos?display=settings';
          }
          return;
        }
        if (list.length === 1) {
          submitSaveToCategory(list[0].id);
          return;
        }
        if (picker && !picker.hidden) picker.hidden = true;
        openSavePicker();
      });
    }
    if (savePickerClose) savePickerClose.addEventListener('click', closeSavePicker);
    if (todoModal) {
      todoModal.addEventListener('click', function (e) {
        if (!savePicker || savePicker.hidden) return;
        if (savePicker.contains(e.target) || (saveBtn && saveBtn.contains(e.target))) return;
        closeSavePicker();
      });
    }

    if (!iconsEl || list.length === 0) return;

    let openCategoryId = null;
    const msgEmpty = @json(__('このカテゴリにタイトルがありません。設定から追加してください。'));
    const msgMore = @json(__('ほかのカテゴリ'));
    const msgPickCat = @json(__('カテゴリを選ぶ'));

    function isMobile() {
      try {
        return window.matchMedia('(max-width: 768px)').matches;
      } catch (_) {
        return false;
      }
    }

    function iconWidth() {
      return isMobile() ? 36 : 40;
    }

    function maxIcons() {
      const row = iconsEl.parentElement;
      if (!row) return Math.min(list.length, 8);
      const modalHidden = todoModal && todoModal.hasAttribute('hidden');
      if (modalHidden || row.clientWidth < 40) {
        return Math.min(list.length, isMobile() ? 5 : 8);
      }
      const label = document.getElementById('modal-title-label');
      const labelW = label ? label.getBoundingClientRect().width : 120;
      const gap = 6;
      const moreSlot = list.length > 1 ? (iconWidth() + gap) : 0;
      const available = Math.max(0, row.clientWidth - labelW - 12 - moreSlot);
      const n = Math.floor((available + gap) / (iconWidth() + gap));
      return Math.max(1, Math.min(list.length, n || 1));
    }

    function applyTitleShortcut(item) {
      if (!titleInput || !item) return;
      titleInput.value = item.title || '';
      titleInput.dispatchEvent(new Event('input', { bubbles: true }));

      if (item.startTime && editForm) {
        const rangeToggle = document.getElementById('modal-enable-time-range');
        const pointToggle = document.getElementById('modal-enable-time-point');
        const startEl = document.getElementById('modal-start-time');
        const endEl = document.getElementById('modal-end-time');
        const hasEnd = !!(item.endTime && item.endTime !== item.startTime);
        if (hasEnd) {
          if (rangeToggle && !rangeToggle.checked) {
            rangeToggle.checked = true;
            rangeToggle.dispatchEvent(new Event('change', { bubbles: true }));
          }
          if (pointToggle && pointToggle.checked) {
            pointToggle.checked = false;
            pointToggle.dispatchEvent(new Event('change', { bubbles: true }));
          }
          if (startEl) startEl.value = item.startTime;
          if (endEl) endEl.value = item.endTime;
        } else {
          if (pointToggle && !pointToggle.checked) {
            pointToggle.checked = true;
            pointToggle.dispatchEvent(new Event('change', { bubbles: true }));
          }
          if (rangeToggle && rangeToggle.checked) {
            rangeToggle.checked = false;
            rangeToggle.dispatchEvent(new Event('change', { bubbles: true }));
          }
          if (startEl) startEl.value = item.startTime;
          if (endEl) endEl.value = item.startTime;
        }
      }

      if (editForm) {
        const reminderVals = Array.isArray(item.reminders) ? item.reminders.map(String) : [];
        const uiKeys = {};
        let reminderTime = item.reminderTime ? String(item.reminderTime) : '';
        reminderVals.forEach(function (key) {
          if (key === 'at9am') {
            uiKeys.at_time = true;
            if (!reminderTime) reminderTime = '09:00';
            return;
          }
          const atMatch = String(key).match(/^at:(\d{2}:\d{2})$/);
          if (atMatch) {
            uiKeys.at_time = true;
            if (!reminderTime) reminderTime = atMatch[1];
            return;
          }
          uiKeys[key] = true;
        });
        editForm.querySelectorAll('[data-modal-reminder]').forEach(function (cb) {
          cb.checked = !!uiKeys[String(cb.value)];
        });
        const reminderTimeEl = editForm.querySelector('[data-modal-reminder-time]');
        if (reminderTimeEl) reminderTimeEl.value = reminderTime || '';
        const notify = item.notifyVia ? String(item.notifyVia) : '';
        editForm.querySelectorAll('[data-modal-notify]').forEach(function (radio) {
          radio.checked = notify !== '' && String(radio.value) === notify;
        });
        if (window.syncTodoNotifySettings) {
          window.syncTodoNotifySettings(editForm);
        }
      }

      closePicker();
      titleInput.focus();
    }

    function closePicker() {
      openCategoryId = null;
      if (picker) picker.hidden = true;
      if (pickerList) pickerList.innerHTML = '';
    }

    function openPicker(cat) {
      if (!picker || !pickerList || !cat) return;
      closeSavePicker();
      openCategoryId = cat.id;
      if (pickerTitle) {
        pickerTitle.textContent = (cat.icon || '') + ' ' + (cat.name || '');
      }
      pickerList.innerHTML = '';
      const titles = Array.isArray(cat.titles) ? cat.titles : [];
      if (titles.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'todo-shortcut-picker-empty';
        empty.textContent = msgEmpty;
        pickerList.appendChild(empty);
      } else {
        titles.forEach((item) => {
          const li = document.createElement('li');
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'todo-shortcut-picker-item';
          let timeLabel = '';
          if (item.startTime) {
            timeLabel = (item.endTime && item.endTime !== item.startTime)
              ? (' (' + item.startTime + '\u301c' + item.endTime + ')')
              : (' (' + item.startTime + ')');
          }
          btn.textContent = (item.title || '') + timeLabel;
          btn.addEventListener('click', function () { applyTitleShortcut(item); });
          li.appendChild(btn);
          pickerList.appendChild(li);
        });
      }
      picker.hidden = false;
    }

    function bindIconClick(btn, cat) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (openCategoryId === cat.id && picker && !picker.hidden) {
          closePicker();
          return;
        }
        openPicker(cat);
      });
    }

    function renderIcons() {
      const limit = maxIcons();
      iconsEl.innerHTML = '';
      iconsEl.hidden = false;
      list.slice(0, limit).forEach((cat) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'todo-shortcut-icon-btn';
        btn.setAttribute('data-shortcut-cat-id', String(cat.id));
        btn.title = cat.name || '';
        btn.setAttribute('aria-label', cat.name || '');
        btn.textContent = cat.icon || '\uD83D\uDCCC';
        bindIconClick(btn, cat);
        iconsEl.appendChild(btn);
      });
      if (list.length > limit) {
        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'todo-shortcut-icon-btn is-more';
        more.title = msgMore;
        more.setAttribute('aria-label', msgMore);
        more.textContent = '\u22EF';
        more.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          if (!picker || !pickerList) return;
          if (pickerTitle) pickerTitle.textContent = msgPickCat;
          pickerList.innerHTML = '';
          list.forEach((cat) => {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'todo-shortcut-picker-item';
            btn.textContent = (cat.icon || '') + ' ' + (cat.name || '');
            btn.addEventListener('click', function () { openPicker(cat); });
            li.appendChild(btn);
            pickerList.appendChild(li);
          });
          picker.hidden = false;
          openCategoryId = null;
        });
        iconsEl.appendChild(more);
      }
    }

    iconsEl.querySelectorAll('[data-shortcut-cat-id]').forEach((btn) => {
      const cat = byId[String(btn.getAttribute('data-shortcut-cat-id'))];
      if (cat) bindIconClick(btn, cat);
    });

    if (pickerClose) pickerClose.addEventListener('click', closePicker);
    if (todoModal) {
      todoModal.addEventListener('click', function (e) {
        if (!picker || picker.hidden) return;
        if (picker.contains(e.target) || iconsEl.contains(e.target)) return;
        closePicker();
      });
    }

    renderIcons();
    window.addEventListener('resize', function () { renderIcons(); }, { passive: true });
    if (typeof ResizeObserver !== 'undefined' && iconsEl.parentElement) {
      new ResizeObserver(function () { renderIcons(); }).observe(iconsEl.parentElement);
    }
    if (todoModal && typeof MutationObserver !== 'undefined') {
      new MutationObserver(function () {
        if (!todoModal.hasAttribute('hidden')) {
          requestAnimationFrame(function () { renderIcons(); });
        } else {
          closePicker();
          closeSavePicker();
        }
      }).observe(todoModal, { attributes: true, attributeFilter: ['hidden'] });
    }
  })();
</script>
