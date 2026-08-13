@php
  $mine = (int) ($message['userId'] ?? 0) === (int) $currentUserId;
  $isDm = !empty($message['isDirect']) || ($message['threadType'] ?? '') === 'dm';
  $typeLabel = $message['threadTypeLabel'] ?? ($isDm ? __('個別メッセージ') : __('グループメッセージ'));
  $isSticker = !empty($message['isSticker']);
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
  @if(!empty($message['attachments']))
    <ul class="msg-files">
      @foreach($message['attachments'] as $file)
        <li>
          @if(!empty($file['isImage']))
            <a href="{{ $file['url'] }}" target="_blank" rel="noopener"><img src="{{ $file['url'] }}" alt="{{ $file['name'] }}" /></a>
          @else
            <a class="msg-file-link" href="{{ $file['downloadUrl'] }}">{{ $file['name'] }}</a>
          @endif
        </li>
      @endforeach
    </ul>
  @endif
  @if($isSticker)
    <div class="msg-sticker-line">
      @if(!empty($message['body']))
        <p class="msg-sticker-emoji msg-body-text" title="{{ __('長押しで拡大') }}">{{ $message['body'] }}</p>
      @endif
      <div class="msg-bubble-actions">
        @if($mine)
          <button type="button" class="msg-act" data-act="delete" data-id="{{ $message['id'] }}">{{ __('削除') }}</button>
          <button type="button" class="msg-act" data-act="copy" data-id="{{ $message['id'] }}">{{ __('コピー') }}</button>
          <button type="button" class="msg-act" data-act="forward" data-id="{{ $message['id'] }}">{{ __('転送') }}</button>
        @else
          <button type="button" class="msg-act" data-act="reply" data-id="{{ $message['id'] }}">{{ __('返信') }}</button>
          <button type="button" class="msg-act" data-act="copy" data-id="{{ $message['id'] }}">{{ __('コピー') }}</button>
          <button type="button" class="msg-act" data-act="delete" data-id="{{ $message['id'] }}">{{ __('削除') }}</button>
        @endif
      </div>
      <button type="button" class="msg-menu-btn" data-msg-menu="{{ $message['id'] }}">{{ __('操作') }}</button>
    </div>
  @else
    <div class="msg-bubble-actions">
      @if($mine)
        <button type="button" class="msg-act" data-act="edit" data-id="{{ $message['id'] }}">{{ __('編集') }}</button>
        <button type="button" class="msg-act" data-act="delete" data-id="{{ $message['id'] }}">{{ __('削除') }}</button>
        <button type="button" class="msg-act" data-act="copy" data-id="{{ $message['id'] }}">{{ __('コピー') }}</button>
        <button type="button" class="msg-act" data-act="forward" data-id="{{ $message['id'] }}">{{ __('転送') }}</button>
      @else
        <button type="button" class="msg-act" data-act="reply" data-id="{{ $message['id'] }}">{{ __('返信') }}</button>
        <button type="button" class="msg-act" data-act="copy" data-id="{{ $message['id'] }}">{{ __('コピー') }}</button>
        @if(!empty($canTranslate))
          <button type="button" class="msg-act" data-act="translate" data-id="{{ $message['id'] }}">{{ __('翻訳') }}</button>
        @endif
        <button type="button" class="msg-act" data-act="delete" data-id="{{ $message['id'] }}">{{ __('削除') }}</button>
      @endif
    </div>
    <button type="button" class="msg-menu-btn" data-msg-menu="{{ $message['id'] }}">{{ __('操作') }}</button>
  @endif
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
</article>
