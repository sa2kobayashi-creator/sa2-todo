@php
  $aiId = $aiId ?? 'ai-assist';
  $aiSamples = $aiSamples ?? [];
  $aiReady = ! empty($aiReady);
@endphp
<section class="panel ai-assist" id="{{ $aiId }}">
  <header class="ai-assist-head">
    <span class="guide-stage-icon" aria-hidden="true">{{ $aiIcon ?? '💬' }}</span>
    <div>
      <h2>{{ $aiTitle }}</h2>
      <p>{{ $aiHint ?? '' }}</p>
    </div>
  </header>

  @if(! $aiReady)
    <p class="hint">{{ __('Cloudflare Workers AI が未設定です。設定 → AI にアカウント ID と API トークンを入れてください。') }}</p>
  @endif

  <div class="guide-log ai-assist-log" id="{{ $aiId }}-log" aria-live="polite">
    <div class="guide-empty" id="{{ $aiId }}-empty">
      <span class="guide-empty-icon" aria-hidden="true">{{ $aiIcon ?? '💬' }}</span>
      <h3>{{ __('何でも聞いてください') }}</h3>
      @if($aiReady && $aiSamples !== [])
        <p>{{ __('たとえば、こんなことを聞けます。') }}</p>
        <div class="guide-suggests">
          @foreach($aiSamples as $sample)
            <button type="button" class="guide-suggest" data-ai-sample="{{ $aiId }}" data-prompt="{{ $sample }}">{{ $sample }}</button>
          @endforeach
        </div>
      @endif
    </div>
  </div>

  <form class="guide-composer ai-assist-composer" id="{{ $aiId }}-form" method="post" action="{{ $aiEndpoint }}">
    @csrf
    <label class="visually-hidden" for="{{ $aiId }}-prompt">{{ __('質問') }}</label>
    <div class="guide-composer-box">
      <textarea id="{{ $aiId }}-prompt" name="prompt" rows="1" maxlength="2000" required placeholder="{{ __('聞きたいことを書いてください') }}" @disabled(! $aiReady)></textarea>
      <button type="submit" class="guide-send" id="{{ $aiId }}-submit" @disabled(! $aiReady)>{{ __('聞く') }}</button>
    </div>
    <div class="guide-composer-foot">
      <span class="guide-kbd-hint"><kbd>Ctrl</kbd> + <kbd>Enter</kbd> {{ __('で送信') }}</span>
      <span class="guide-status" id="{{ $aiId }}-status" aria-live="polite"></span>
    </div>
  </form>
</section>
<script>
  (() => {
    const id = @json($aiId);
    const endpoint = @json($aiEndpoint);
    const ready = @json($aiReady);
    const strings = {
      you: @json(__('あなた')),
      guide: @json(__('ガイド')),
      thinking: @json(__('考えています...')),
      error: @json(__('通信エラーが発生しました')),
    };
    const form = document.getElementById(id + '-form');
    const log = document.getElementById(id + '-log');
    const empty = document.getElementById(id + '-empty');
    const promptEl = document.getElementById(id + '-prompt');
    const statusEl = document.getElementById(id + '-status');
    const submitEl = document.getElementById(id + '-submit');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const history = [];
    let busy = false;

    const autoGrow = () => {
      if (!promptEl) return;
      promptEl.style.height = 'auto';
      promptEl.style.height = Math.min(promptEl.scrollHeight, 160) + 'px';
    };

    const append = (role, text) => {
      empty?.remove();
      const item = document.createElement('article');
      item.className = 'guide-msg is-' + role;
      const who = document.createElement('span');
      who.className = 'guide-msg-who';
      who.textContent = role === 'user' ? strings.you : strings.guide;
      const body = document.createElement('p');
      body.textContent = text;
      item.append(who, body);
      log.append(item);
      log.scrollTop = log.scrollHeight;
      return item;
    };

    const appendTyping = () => {
      empty?.remove();
      const item = document.createElement('article');
      item.className = 'guide-msg is-assistant is-typing';
      item.innerHTML = '<span class="guide-msg-who"></span><p><span class="guide-dots"><i></i><i></i><i></i></span></p>';
      item.querySelector('.guide-msg-who').textContent = strings.guide;
      log.append(item);
      log.scrollTop = log.scrollHeight;
      return item;
    };

    const send = async () => {
      if (!ready || busy) return;
      const prompt = (promptEl?.value || '').trim();
      if (!prompt) return;
      append('user', prompt);
      promptEl.value = '';
      autoGrow();
      busy = true;
      if (submitEl) submitEl.disabled = true;
      if (statusEl) statusEl.textContent = strings.thinking;
      const typing = appendTyping();
      try {
        const res = await fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ prompt, messages: history }),
        });
        const data = await res.json().catch(() => ({}));
        typing.remove();
        if (!data.ok) {
          append('assistant', data.message || strings.error).classList.add('is-error');
          return;
        }
        history.push({ role: 'user', content: prompt });
        history.push({ role: 'assistant', content: data.text || '' });
        if (history.length > 12) history.splice(0, history.length - 12);
        append('assistant', data.text || '');
      } catch (_) {
        typing.remove();
        append('assistant', strings.error).classList.add('is-error');
      } finally {
        busy = false;
        if (submitEl) submitEl.disabled = !ready;
        if (statusEl) statusEl.textContent = '';
      }
    };

    form?.addEventListener('submit', (e) => {
      e.preventDefault();
      send();
    });
    promptEl?.addEventListener('input', autoGrow);
    promptEl?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        send();
      }
    });
    document.querySelectorAll('[data-ai-sample="' + id + '"]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!promptEl) return;
        promptEl.value = btn.dataset.prompt || '';
        autoGrow();
        send();
      });
    });
  })();
</script>
