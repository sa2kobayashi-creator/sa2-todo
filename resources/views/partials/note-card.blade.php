@php
  $colorClass = 'note-color-'.($note['color'] ?? 'default');
  $palette = $noteColors[$note['color'] ?? 'default'] ?? $noteColors['default'];
  $categoryKey = $note['category'] ?? ($defaultCategory ?? 'personal');
  $categoryLabel = $noteCategories[$categoryKey] ?? ($noteCategories['personal'] ?? '個人');
  $notePayload = [
      'id' => $note['id'],
      'title' => $note['title'] ?? '',
      'body' => $note['body'] ?? '',
      'color' => $note['color'] ?? 'default',
      'type' => $note['type'] ?? 'text',
      'category' => $categoryKey,
      'registeredDate' => app(\App\Services\NoteService::class)->getRegisteredDate($note),
      'items' => $note['items'] ?? [],
  ];
@endphp
<article
  class="note-card {{ $colorClass }}@if(!empty($highlightId) && $highlightId === $note['id']) is-highlighted @endif"
  id="note-{{ $note['id'] }}"
  style="--note-bg: {{ $palette['bg'] }}; --note-border: {{ $palette['border'] }}"
  data-note-id="{{ $note['id'] }}"
  data-note-b64="{{ base64_encode(json_encode($notePayload, JSON_UNESCAPED_UNICODE)) }}"
>
  <label class="note-bulk-check">
    <input type="checkbox" class="note-check" value="{{ $note['id'] }}" aria-label="{{ ($note['title'] ?? 'メモ') }}を選択" />
  </label>
  <button
    type="button"
    class="note-drag-handle"
    draggable="true"
    aria-label="{{ ($note['title'] ?? 'メモ') }} の表示順を変更"
    title="ドラッグして並び替え"
  >⠿</button>
  <div class="note-card-view">
    @if(!empty($note['pinned']))<div class="note-pin-badge" title="ピン留め">📌</div>@endif
    <div class="note-card-meta">
      <div class="note-card-date">{{ $notePayload['registeredDate'] }}</div>
      <span class="note-card-category">{{ $categoryLabel }}</span>
    </div>
    @if(!empty($note['title']))<h3 class="note-card-title">{{ $note['title'] }}</h3>@endif
    @if(($note['type'] ?? '') === 'checklist' && !empty($note['items']))
      @php
        $checklistPreviewLimit = 20;
        $checklistPreviewItems = array_slice($note['items'], 0, $checklistPreviewLimit);
        $checklistTruncated = count($note['items']) > $checklistPreviewLimit;
      @endphp
      <ul @class(['note-checklist-preview', 'is-truncated' => $checklistTruncated])>
        @foreach($checklistPreviewItems as $item)
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
</article>
