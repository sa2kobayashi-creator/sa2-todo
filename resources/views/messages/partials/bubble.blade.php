@php
  $mine = (int) ($message['userId'] ?? 0) === (int) $currentUserId;
  $isDm = !empty($message['isDirect']) || ($message['threadType'] ?? '') === 'dm';
  $typeLabel = $message['threadTypeLabel'] ?? ($isDm ? __('個別メッセージ') : __('グループメッセージ'));
  $isSticker = !empty($message['isSticker']);

  $actSvg = [
    'edit' => '<path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34a.9959.9959 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>',
    'delete' => '<path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>',
    'copy' => '<path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>',
    'forward' => '<path fill="currentColor" d="M12 8V4l8 8-8 8v-4H4V8h8z"/>',
    'reply' => '<path fill="currentColor" d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/>',
  ];
  $actBtn = function (string $act, int $id) use ($actSvg): string {
    $labelKey = [
      'edit' => '編集',
      'delete' => '削除',
      'copy' => 'コピー',
      'forward' => '転送',
      'reply' => '返信',
    ][$act] ?? $act;
    $label = __($labelKey);
    $path = $actSvg[$act] ?? '';
    return '<button type="button" class="msg-act msg-act-icon" data-act="'.e($act).'" data-id="'.$id.'" title="'.e($label).'" aria-label="'.e($label).'">'
      .'<svg class="msg-act-svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">'.$path.'</svg>'
      .'</button>';
  };
@endphp
<article
  class="msg-bubble {{ $mine ? 'is-mine' : '' }} {{ $isSticker ? 'is-sticker' : '' }}"
  data-id="{{ $message['id'] }}"
  data-mine="{{ $mine ? '1' : '0' }}"
  data-sticker="{{ $isSticker ? '1' : '0' }}"
>
  @if(!$isSticker)
    <div class="msg-bubble-meta">
      <strong>{{ $message['userName'] }}</strong>
      <span class="msg-type-pill {{ $isDm ? 'msg-type-dm' : 'msg-type-channel' }}">{{ $typeLabel }}</span>
      <time>
        {{ $message['createdAt'] }}
        @if(!empty($message['editedAt'])) · {{ __('編集済み') }}@endif
      </time>
    </div>
  @endif
  @if(!empty($message['replyTo']) && !$isSticker)
    <div class="msg-quote">
      <strong>{{ $message['replyTo']['userName'] }}</strong>
      {{ $message['replyTo']['body'] ?? '' }}
    </div>
  @endif
  @if(!empty($message['body']) && !$isSticker)
    <p class="msg-body-text">{{ $message['body'] }}</p>
  @endif
  @if($isSticker && !empty($message['body']))
    <div class="msg-sticker-line">
      <p class="msg-sticker-emoji msg-body-text" title="{{ __('長押しで拡大') }}">{{ $message['body'] }}</p>
    </div>
  @endif
  @if(!empty($message['attachments']))
    <ul class="msg-files">
      @foreach($message['attachments'] as $file)
        <li class="msg-file-item{{ !empty($file['isImage']) ? ' is-image' : '' }}">
          @if(!empty($file['isImage']))
            <a class="msg-file-preview" href="{{ $file['url'] }}" target="_blank" rel="noopener">
              <img src="{{ $file['url'] }}" alt="{{ $file['name'] }}" />
            </a>
          @else
            <a class="msg-file-link" href="{{ $file['downloadUrl'] }}">{{ $file['name'] }}</a>
          @endif
          <div class="msg-file-actions">
            <a class="msg-file-action" href="{{ $file['downloadUrl'] }}" download>{{ __('ダウンロード') }}</a>
            @if(!empty($file['canSaveToPhotos']))
              <button
                type="button"
                class="msg-file-action"
                data-save-to-photos="{{ $file['id'] }}"
                data-save-to-photos-url="{{ $file['saveToPhotosUrl'] ?? ('/messages/attachments/'.$file['id'].'/to-photos') }}"
              >{{ __('Photosに追加') }}</button>
            @endif
          </div>
        </li>
      @endforeach
    </ul>
  @endif
  <div class="msg-footer">
    <div class="msg-reacts">
      @foreach($message['reactions'] ?? [] as $reaction)
        <button
          type="button"
          class="msg-react-chip {{ !empty($reaction['reactedByMe']) ? 'is-mine' : '' }}"
          data-react="{{ $reaction['emoji'] }}"
          data-id="{{ $message['id'] }}"
        >{{ $reaction['emoji'] }} <span>{{ $reaction['count'] }}</span></button>
      @endforeach
      <button
        type="button"
        class="msg-react-open"
        data-react-open="{{ $message['id'] }}"
        aria-label="{{ __('リアクション') }}"
        title="{{ __('リアクション') }}"
      >😊</button>
    </div>
    <div class="msg-bubble-actions">
      @if($isSticker)
        @if($mine)
          {!! $actBtn('delete', (int) $message['id']) !!}
        @else
          {!! $actBtn('reply', (int) $message['id']) !!}
          {!! $actBtn('delete', (int) $message['id']) !!}
        @endif
      @elseif($mine)
        {!! $actBtn('edit', (int) $message['id']) !!}
        {!! $actBtn('delete', (int) $message['id']) !!}
        {!! $actBtn('copy', (int) $message['id']) !!}
        {!! $actBtn('forward', (int) $message['id']) !!}
      @else
        {!! $actBtn('reply', (int) $message['id']) !!}
        {!! $actBtn('copy', (int) $message['id']) !!}
        @if(!empty($canTranslate))
          <button type="button" class="msg-act" data-act="translate" data-id="{{ $message['id'] }}">{{ __('翻訳') }}</button>
        @endif
        {!! $actBtn('delete', (int) $message['id']) !!}
      @endif
    </div>
    <button type="button" class="msg-menu-btn" data-msg-menu="{{ $message['id'] }}">{{ __('操作') }}</button>
  </div>
</article>
