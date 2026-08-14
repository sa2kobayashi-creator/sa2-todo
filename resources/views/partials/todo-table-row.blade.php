<tr class="todo-row {{ !empty($row['completed']) ? 'done' : '' }}" data-todo-id="{{ $row['id'] }}">
  <td class="todo-col-check">
    <input type="checkbox" class="todo-check" value="{{ $row['id'] }}" aria-label="{{ __('選択') }}" />
  </td>
  <td class="todo-col-date">{{ $row['dateLabel'] ?? '—' }}</td>
  <td class="todo-col-title title">
    {{ $row['title'] }}
    @if(!empty($row['googleMeetLink']))
      <a
        class="event-meet-badge"
        href="{{ $row['googleMeetLink'] }}"
        target="_blank"
        rel="noopener noreferrer"
        title="Google Meet"
        onclick="event.stopPropagation()"
      >Meet</a>
    @endif
  </td>
  <td class="todo-col-time">{{ $row['timeLabel'] ?? '—' }}</td>
  <td class="todo-col-category">
    <span class="category-badge category-{{ $row['category'] }}">{{ $row['categoryLabel'] ?? '—' }}</span>
  </td>
  <td class="todo-col-importance">
    <span class="importance-badge importance-{{ $row['importance'] }}">{{ $row['importanceLabel'] ?? '—' }}</span>
  </td>
  <td class="todo-col-actions">
    <div class="todo-row-actions">
      <button
        type="button"
        class="secondary todo-row-action todo-row-icon-btn"
        data-action="duplicate"
        data-todo-id="{{ $row['id'] }}"
        data-confirm-mobile="{{ __('この ToDo をコピーしますか？') }}"
        title="{{ __('コピー') }}"
        aria-label="{{ __('コピー') }}"
      >
        <span class="todo-row-icon" aria-hidden="true">⧉</span>
        <span class="todo-row-label">{{ __('コピー') }}</span>
      </button>
      <a
        class="button-link secondary mini todo-row-edit todo-row-icon-btn"
        href="{{ $editUrl }}"
        title="{{ __('編集') }}"
        aria-label="{{ __('編集') }}"
      >
        <span class="todo-row-icon" aria-hidden="true">✎</span>
        <span class="todo-row-label">{{ __('編集') }}</span>
      </a>
      @php
        $toggleLabel = ! empty($row['completed']) ? __('戻す') : __('完了');
        $toggleIcon = ! empty($row['completed']) ? '↺' : '✓';
        $toggleConfirmMobile = empty($row['completed']) ? __('この ToDo を完了にしますか？') : '';
      @endphp
      <button
        type="button"
        class="secondary todo-row-action todo-row-icon-btn"
        data-action="toggle"
        data-todo-id="{{ $row['id'] }}"
        @if($toggleConfirmMobile !== '') data-confirm-mobile="{{ $toggleConfirmMobile }}" @endif
        title="{{ $toggleLabel }}"
        aria-label="{{ $toggleLabel }}"
      >
        <span class="todo-row-icon" aria-hidden="true">{{ $toggleIcon }}</span>
        <span class="todo-row-label">{{ $toggleLabel }}</span>
      </button>
      <button
        type="button"
        class="danger todo-row-action todo-row-icon-btn"
        data-action="delete"
        data-todo-id="{{ $row['id'] }}"
        data-confirm="{{ __('削除しますか？') }}"
        title="{{ __('削除') }}"
        aria-label="{{ __('削除') }}"
      >
        <span class="todo-row-icon" aria-hidden="true">🗑</span>
        <span class="todo-row-label">{{ __('削除') }}</span>
      </button>
    </div>
  </td>
</tr>
