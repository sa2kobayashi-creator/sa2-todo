@php
  $colorClass = 'note-color-'.($note['color'] ?? 'default');
  $palette = $noteColors[$note['color'] ?? 'default'] ?? $noteColors['default'];
  $categoryKey = $note['category'] ?? ($defaultCategory ?? 'personal');
  $categoryLabel = $noteCategories[$categoryKey] ?? ($noteCategories['personal'] ?? __('個人'));
  $notePayload = [
      'id' => $note['id'],
      'title' => $note['title'] ?? '',
      'body' => $note['body'] ?? '',
      'color' => $note['color'] ?? 'default',
      'type' => $note['type'] ?? 'text',
      'category' => $categoryKey,
      'groupId' => $note['groupId'] ?? null,
      'shareLabel' => $note['shareLabel'] ?? __('個人（自分のみ）'),
      'registeredDate' => app(\App\Services\NoteService::class)->getRegisteredDate($note),
      'items' => $note['items'] ?? [],
      'attachments' => $note['attachments'] ?? [],
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
    <input type="checkbox" class="note-check" value="{{ $note['id'] }}" aria-label="{{ ($note['title'] ?? __('メモ')) }}{{ __('を選択') }}" />
  </label>
  <button
    type="button"
    class="note-drag-handle"
    draggable="true"
    aria-label="{{ ($note['title'] ?? __('メモ')) }}{{ __(' の表示順を変更') }}"
    title="{{ __('ドラッグして並び替え') }}"
  >⠿</button>
  <div class="note-card-view">
    @if(!empty($note['pinned']))<div class="note-pin-badge" title="{{ __('ピン留め') }}">📌</div>@endif
    <div class="note-card-meta">
      <div class="note-card-date">{{ $notePayload['registeredDate'] }}</div>
      <span class="note-card-category">{{ $categoryLabel }}</span>
      <span class="note-card-share">{{ $notePayload['shareLabel'] }}</span>
    </div>
    @if(!empty($note['title']))<h3 class="note-card-title">{{ $note['title'] }}</h3>@endif
    @if(($note['type'] ?? '') === 'checklist' && !empty($note['items']))
      @php
        $checklistPreviewLimit = 20;
        $checklistTruncated = count($note['items']) > $checklistPreviewLimit;
      @endphp
      <ul @class(['note-checklist-preview', 'is-truncated' => $checklistTruncated])>
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
    @if(!empty($note['attachments']))
      <ul class="note-card-attachments">
        @foreach($note['attachments'] as $attachment)
          <li>
            @if(!empty($attachment['isImage']))
              <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener" class="note-attachment-thumb" title="{{ $attachment['name'] }}">
                <img src="{{ $attachment['url'] }}" alt="{{ $attachment['name'] }}" loading="lazy" />
              </a>
            @else
              <a href="{{ $attachment['downloadUrl'] ?? $attachment['url'] }}" class="note-attachment-file">
                <span class="note-attachment-name">{{ $attachment['name'] }}</span>
                <span class="note-attachment-size">{{ $attachment['sizeLabel'] ?? '' }}</span>
              </a>
            @endif
          </li>
        @endforeach
      </ul>
    @endif
    <div class="note-card-actions">
      @if(empty($showArchived))
        <form method="post" action="/notes/{{ $note['id'] }}/pin" class="note-inline-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <button type="submit" class="note-icon-btn" title="{{ !empty($note['pinned']) ? __('ピン留めを外す') : __('ピン留め') }}">📌</button>
        </form>
      @endif
      <button type="button" class="note-icon-btn note-action-translate" data-note-id="{{ $note['id'] }}" title="{{ __('日本語⇔英語に翻訳') }}" aria-label="{{ __('翻訳') }}" aria-pressed="false">🌐<span class="note-translate-label">{{ __('訳') }}</span></button>
      <button type="button" class="note-icon-btn note-action-edit" title="{{ __('編集') }}" aria-label="{{ __('編集') }}">✎</button>
      <form method="post" action="/notes/{{ $note['id'] }}/archive" class="note-inline-form">
        @csrf
        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
        <button type="submit" class="note-icon-btn" title="{{ $showArchived ? __('アーカイブから戻す') : __('アーカイブ') }}">{{ $showArchived ? '↩' : '📥' }}</button>
      </form>
      <form method="post" action="/notes/{{ $note['id'] }}/delete" class="note-inline-form" onsubmit='return confirm(@json(__('このメモを削除しますか？')))'>
        @csrf
        <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
        <button type="submit" class="note-icon-btn danger" title="{{ __('削除') }}" aria-label="{{ __('削除') }}">🗑</button>
      </form>
    </div>
  </div>
</article>
