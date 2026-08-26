@php
  $aiId = $aiId ?? 'ai-assist';
  $aiSamples = $aiSamples ?? [];
  $aiReady = ! empty($aiReady);
  $aiContextHook = $aiContextHook ?? '';
  $aiResultHook = $aiResultHook ?? '';
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
      <button
        type="button"
        class="ai-assist-mic"
        id="{{ $aiId }}-mic"
        aria-pressed="false"
        title="{{ __('音声入力') }}"
        aria-label="{{ __('音声入力') }}"
        @disabled(! $aiReady)
      >
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
          <path fill="currentColor" d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
        </svg>
      </button>
      <textarea id="{{ $aiId }}-prompt" name="prompt" rows="1" maxlength="2000" required placeholder="{{ __('聞きたいことを書いてください') }}" @disabled(! $aiReady)></textarea>
      <button type="submit" class="guide-send" id="{{ $aiId }}-submit" @disabled(! $aiReady)>{{ __('聞く') }}</button>
    </div>
    <div class="guide-composer-foot">
      <span class="guide-kbd-hint"><kbd>Ctrl</kbd> + <kbd>Enter</kbd> {{ __('で送信') }}</span>
      <span class="guide-status" id="{{ $aiId }}-status" aria-live="polite"></span>
    </div>
  </form>
</section>
@once
  <script src="{{ asset('speech-dictation.js') }}?v={{ @filemtime(public_path('speech-dictation.js')) ?: time() }}"></script>
@endonce
<script>
  (() => {
    const id = @json($aiId);
    const endpoint = @json($aiEndpoint);
    const ready = @json($aiReady);
    const contextHook = @json($aiContextHook);
    const resultHook = @json($aiResultHook);
    const strings = {
      you: @json(__('あなた')),
      guide: @json(__('ガイド')),
      thinking: @json(__('考えています...')),
      error: @json(__('通信エラーが発生しました')),
      voiceListening: @json(__('聞いています…')),
      voiceUnsupported: @json(__('このブラウザでは音声入力に対応していません。')),
      voiceHttps: @json(__('音声入力は HTTPS（または localhost）でのみ利用できます。')),
      voiceStartFailed: @json(__('音声認識を開始できませんでした。')),
      voiceMicDenied: @json(__('マイクの使用が許可されていません。')),
    };
    const form = document.getElementById(id + '-form');
    const log = document.getElementById(id + '-log');
    const empty = document.getElementById(id + '-empty');
    const promptEl = document.getElementById(id + '-prompt');
    const statusEl = document.getElementById(id + '-status');
    const submitEl = document.getElementById(id + '-submit');
    const micBtn = document.getElementById(id + '-mic');
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

    const escapeHtml = (value) => String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

    // transit.js が古いキャッシュのままでも、経路カードは出るようにする
    const showTransitResultsFallback = (data) => {
      const search = data && data.search;
      const box = document.getElementById('transit-search-results');
      const title = document.getElementById('transit-search-results-title');
      const list = document.getElementById('transit-itineraries');
      const links = document.getElementById('transit-search-links');
      if (!search || !box || !list) return;
      if (!data.searched && !(data.itineraries && data.itineraries.length)) return;
      window.__transitLastAiSearch = search;
      const t = (window.TRANSIT_CONFIG && window.TRANSIT_CONFIG.strings) || {};
      const fromEl = document.getElementById('transit-from');
      const toEl = document.getElementById('transit-to');
      if (typeof window.setTransitPlaceField === 'function') {
        if (search.from) window.setTransitPlaceField('from', search.from);
        if (search.to) window.setTransitPlaceField('to', search.to);
      } else {
        if (fromEl && search.from) fromEl.value = search.from;
        if (toEl && search.to) toEl.value = search.to;
      }
      window.__transitLastResult = {
        from: search.from || '',
        to: search.to || '',
        itineraries: data.itineraries || [],
      };
      box.removeAttribute('hidden');
      if (title) {
        title.textContent = (search.from || '') + ' → ' + (search.to || '')
          + (search.engine ? '（' + search.engine + '）' : '');
      }
      const items = data.itineraries || [];
      if (!search.ok) {
        list.innerHTML = '<p class="hint transit-raptor-error">' + escapeHtml(search.message || t.noRoute || '') + '</p>';
        return;
      }
      if (items.length === 0) {
        list.innerHTML = '<p class="hint">' + escapeHtml(search.message || t.noMatchingRoute || '') + '</p>';
        return;
      }
      const note = search.engineNote
        ? '<p class="hint transit-engine-note">' + escapeHtml(search.engineNote) + '</p>'
        : '';
      list.innerHTML = note + items.map((it, index) => {
        const badge = it.usesPreferredOperator
          ? '<span class="transit-itinerary-badge is-preferred">' + escapeHtml((it.preferredOperatorName || '') + (t.operatorPreferred || t.nishitetsuPreferred || '優先')) + '</span>'
          : '';
        const legs = (it.legs || []).map((leg) => {
          if (leg.type === 'walk') {
            const mins = Math.round((leg.durationSec || 0) / 60);
            return '<li class="is-walk">' + escapeHtml(t.walk || '徒歩') + ' '
              + escapeHtml(leg.from || '') + ' → ' + escapeHtml(leg.to || '')
              + '（' + escapeHtml(String(mins)) + escapeHtml(t.min || '分') + '）</li>';
          }
          return '<li class="is-ride"><strong>' + escapeHtml(leg.routeName || '') + '</strong> '
            + escapeHtml(leg.boardTime || '') + ' ' + escapeHtml(leg.from || '')
            + ' → ' + escapeHtml(leg.alightTime || '') + ' ' + escapeHtml(leg.to || '')
            + (leg.waitSec ? ' <span class="hint">' + escapeHtml(t.wait || '待ち')
              + Math.round(leg.waitSec / 60) + escapeHtml(t.min || '分') + '</span>' : '')
            + '</li>';
        }).join('');
        return '<article class="transit-itinerary-card">'
          + '<div class="transit-itinerary-head"><strong>#' + (index + 1) + ' '
          + escapeHtml(it.summary || '') + '</strong>' + badge + '</div>'
          + '<div class="transit-itinerary-meta">'
          + escapeHtml(it.departureTime || '') + ' → ' + escapeHtml(it.arrivalTime || '')
          + ' · ' + escapeHtml(it.durationLabel || '')
          + ' · ' + escapeHtml(t.waitPrefix || '待ち') + ' ' + escapeHtml(it.waitLabel || t.zeroMin || '0分')
          + ' · ' + escapeHtml(t.transfers || '乗換') + ' ' + escapeHtml(String(it.transfers ?? 0)) + escapeHtml(t.times || '回')
          + ' · ' + escapeHtml(it.fareLabel || '')
          + '</div>'
          + '<ol class="transit-itinerary-legs">' + legs + '</ol>'
          + '</article>';
      }).join('');
      if (links && search.from && search.to) {
        const from = encodeURIComponent(search.from);
        const to = encodeURIComponent(search.to);
        links.innerHTML = '<h4 class="transit-external-title">' + escapeHtml(t.externalTitle || '外部サービスでも確認') + '</h4>'
          + '<a class="button-link secondary" target="_blank" rel="noopener noreferrer" href="https://www.google.com/maps/dir/?api=1&travelmode=transit&origin='
          + from + '&destination=' + to + '">' + escapeHtml(t.googleMapsRoute || 'Google Maps でルート') + '</a>'
          + '<a class="button-link secondary" target="_blank" rel="noopener noreferrer" href="https://transit.yahoo.co.jp/search/result?from='
          + from + '&to=' + to + '">' + escapeHtml(t.yahooRoute || 'Yahoo!路線でルート') + '</a>';
      }
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
      const extra = (contextHook && typeof window[contextHook] === 'function')
        ? (window[contextHook]() || {})
        : {};
      try {
        const res = await fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ prompt, messages: history, ...extra }),
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
        if (resultHook && typeof window[resultHook] === 'function') {
          window[resultHook](data);
        } else if (resultHook) {
          showTransitResultsFallback(data);
        }
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

    if (micBtn && window.Sa2SpeechDictation) {
      if (!ready) {
        micBtn.disabled = true;
        micBtn.setAttribute('aria-disabled', 'true');
      }
      window.Sa2SpeechDictation.bind(micBtn, {
        replace: false,
        continuous: /Android|iPhone|iPad|iPod/i.test(navigator.userAgent),
        getValue: () => promptEl ? promptEl.value : '',
        setValue: (text) => {
          if (!promptEl) return;
          promptEl.value = text;
          autoGrow();
        },
        onListening: (on) => {
          if (statusEl && (on || statusEl.textContent === strings.voiceListening)) {
            statusEl.textContent = on ? strings.voiceListening : '';
          }
        },
        onError: (text) => {
          if (statusEl) statusEl.textContent = text;
        },
        messages: {
          unsupported: strings.voiceUnsupported,
          https: strings.voiceHttps,
          startFailed: strings.voiceStartFailed,
          micDenied: strings.voiceMicDenied,
        },
      });
    }
  })();
</script>
