@php
  $colorClass = 'note-color-'.($note['color'] ?? 'default');
  $palette = $noteColors[$note['color'] ?? 'default'] ?? $noteColors['default'];
@endphp
<article class="note-card {{ $colorClass }}@if(!empty($highlightId) && $highlightId === $note['id']) is-highlighted @endif" id="note-{{ $note['id'] }}" style="--note-bg: {{ $palette['bg'] }}; --note-border: {{ $palette['border'] }}">
  <label class="note-bulk-check">
    <input type="checkbox" class="note-check" value="{{ $note['id'] }}" aria-label="{{ ($note['title'] ?? 'メモ') }}を選択" />
  </label>
  <div class="note-card-view">
    @if(!empty($note['pinned']))<div class="note-pin-badge" title="ピン留め">📌</div>@endif
    <div class="note-card-date">{{ app(\App\Services\NoteService::class)->getRegisteredDate($note) }}</div>
    @if(!empty($note['title']))<h3 class="note-card-title">{{ $note['title'] }}</h3>@endif
    @if(($note['type'] ?? '') === 'checklist' && !empty($note['items']))
      <ul class="note-checklist-preview">
        @foreach($note['items'] as $item)
          <li class="{{ !empty($item['checked']) ? 'is-done' : '' }}">
            <span class="check-icon" aria-hidden="true">{{ !empty($item['checked']) ? '☑' : '☐' }}</span>
            <span>{{ $item['text'] }}</span>
          </li>
        @endforeach
      </ul>
    @elseif(!empty($note['body']))
      <div class="note-card-body">{{ $note['body'] }}</div>
    @endif
    <div class="note-card-actions">
      @if(empty($showArchived))
        <form method="post" action="/notes/{{ $note['id'] }}/pin" class="note-inline-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <button type="submit" class="note-icon-btn" title="{{ !empty($note['pinned']) ? 'ピン留めを外す' : 'ピン留め' }}">📌</button>
        </form>
      @endif
      <button type="button" class="note-icon-btn note-action-translate" data-note-id="{{ $note['id'] }}" title="日本語⇔英語に翻訳" aria-label="翻訳" aria-pressed="false">🌐<span class="note-translate-label">訳</span></button>
      <button type="button" class="note-icon-btn note-action-edit" title="編集" aria-label="編集">✎</button>
      <form method="post" action="/notes/{{ $note['id'] }}/archive" class="note-inline-form">
        @csrf
        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
        <button type="submit" class="note-icon-btn" title="{{ $showArchived ? 'アーカイブから戻す' : 'アーカイブ' }}">{{ $showArchived ? '↩' : '📥' }}</button>
      </form>
      <form method="post" action="/notes/{{ $note['id'] }}/delete" class="note-inline-form" onsubmit="return confirm('このメモを削除しますか？')">
        @csrf
        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
        <button type="submit" class="note-icon-btn danger" title="削除" aria-label="削除">🗑</button>
      </form>
    </div>
  </div>

  <form method="post" action="/notes/{{ $note['id'] }}/update" class="note-card-edit">
    @csrf
    <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
    <input type="hidden" name="type" value="{{ $note['type'] }}" />
    <input type="hidden" name="color" value="{{ $note['color'] }}" />
    <label class="note-date-field">
      <span class="field-label">登録日</span>
      <input type="date" name="registeredDate" value="{{ app(\App\Services\NoteService::class)->getRegisteredDate($note) }}" />
    </label>
    <input type="text" name="title" value="{{ $note['title'] }}" placeholder="タイトル" />
    @if(($note['type'] ?? '') === 'checklist')
      <div class="checklist-editor">
        @foreach($note['items'] ?? [] as $index => $item)
          <div class="checklist-row">
            <input type="checkbox" class="checklist-check" @checked(!empty($item['checked'])) aria-label="完了" />
            <input type="text" class="checklist-text" name="items[{{ $index }}][text]" value="{{ $item['text'] }}" />
            <input type="hidden" name="items[{{ $index }}][checked]" value="{{ !empty($item['checked']) ? '1' : '0' }}" class="checklist-checked-hidden" />
            <button type="button" class="checklist-remove" aria-label="削除">×</button>
          </div>
        @endforeach
      </div>
      <button type="button" class="text-btn note-add-check-item">項目を追加</button>
    @else
      <textarea name="body" rows="5" placeholder="メモを入力...">{{ $note['body'] }}</textarea>
    @endif
    <div class="note-composer-footer">
      <div class="note-color-picker" role="group" aria-label="色">
        @foreach($colorKeys as $key)
          <button type="button" class="note-color-dot @if($key === ($note['color'] ?? 'default')) is-selected @endif" data-color="{{ $key }}" style="--note-color: {{ $noteColors[$key]['bg'] }}; --note-border: {{ $noteColors[$key]['border'] }}" title="{{ $noteColors[$key]['label'] }}"></button>
        @endforeach
      </div>
      <div class="note-composer-actions">
        <button type="button" class="text-btn note-edit-cancel">キャンセル</button>
        <button type="submit" class="button-link">保存</button>
      </div>
    </div>
  </form>
</article>
