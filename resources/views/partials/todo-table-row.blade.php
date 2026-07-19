<tr class="todo-row {{ !empty($row['completed']) ? 'done' : '' }}" data-todo-id="{{ $row['id'] }}">
  <td class="todo-col-check">
    <input type="checkbox" class="todo-check" value="{{ $row['id'] }}" aria-label="{{ __('選択') }}" />
  </td>
  <td class="todo-col-date">{{ $row['dateLabel'] ?? '—' }}</td>
  <td class="todo-col-title title">{{ $row['title'] }}</td>
  <td class="todo-col-time">{{ $row['timeLabel'] ?? '—' }}</td>
  <td class="todo-col-category">
    <span class="category-badge category-{{ $row['category'] }}">{{ $row['categoryLabel'] ?? '—' }}</span>
  </td>
  <td class="todo-col-importance">
    <span class="importance-badge importance-{{ $row['importance'] }}">{{ $row['importanceLabel'] ?? '—' }}</span>
  </td>
  <td class="todo-col-actions">
    <div class="todo-row-actions">
      <button type="button" class="secondary todo-row-action" data-action="duplicate" data-todo-id="{{ $row['id'] }}" title="{{ __('ToDo を複製') }}">{{ __('コピー') }}</button>
      <a class="button-link secondary mini todo-row-edit" href="{{ $editUrl }}">{{ __('編集') }}</a>
      <button type="button" class="secondary todo-row-action" data-action="toggle" data-todo-id="{{ $row['id'] }}">{{ !empty($row['completed']) ? __('戻す') : __('完了') }}</button>
      <button type="button" class="danger todo-row-action" data-action="delete" data-todo-id="{{ $row['id'] }}" data-confirm="{{ __('削除しますか？') }}">{{ __('削除') }}</button>
    </div>
  </td>
</tr>
