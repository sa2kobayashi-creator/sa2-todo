{{--
  Shared voice entry bar.
  @var string $idPrefix
  @var bool $voiceAiReady
  @var string|null $voiceAiProvider
  @var string $placeholder
--}}
@php
  $idPrefix = $idPrefix ?? 'voice';
  $placeholder = $placeholder ?? __('例: 明日買い物に行く');
@endphp
<div class="finance-voice-entry" id="{{ $idPrefix }}-voice-entry">
  <div class="finance-voice-entry-head">
    <strong>{{ __('音声入力') }}</strong>
    @if(!empty($voiceAiReady))
      <span class="hint">{{ __('使用中:') }} {{ $voiceAiProvider }}</span>
    @else
      <a class="hint" href="/settings?section=ai#ai-llm-settings">{{ __('AI設定で ChatGPT / Gemini を有効化') }}</a>
    @endif
  </div>
  <div class="finance-voice-entry-row">
    <button
      type="button"
      class="finance-voice-mic-btn"
      id="{{ $idPrefix }}-voice-mic-btn"
      aria-pressed="false"
      @disabled(empty($voiceAiReady))
      title="{{ __('マイクで話す') }}"
    >{{ __('話す') }}</button>
    <input
      type="text"
      id="{{ $idPrefix }}-voice-transcript"
      class="finance-voice-transcript"
      placeholder="{{ $placeholder }}"
      autocomplete="off"
      @disabled(empty($voiceAiReady))
    />
    <button
      type="button"
      class="button-link"
      id="{{ $idPrefix }}-voice-parse-btn"
      @disabled(empty($voiceAiReady))
    >{{ __('解析') }}</button>
  </div>
  <p class="hint finance-voice-status" id="{{ $idPrefix }}-voice-status" aria-live="polite"></p>
</div>
