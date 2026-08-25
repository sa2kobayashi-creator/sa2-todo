<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#0f766e" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('生活ガイド') }} - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet" />
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'guide'])
    @php
      $current = $topics[$topic] ?? null;
      $limit = (int) ($dailyLimit ?? 20);
      $unlimited = $limit <= 0;
      $used = max(0, (int) ($usedToday ?? 0));
      $ratio = $unlimited ? 0 : min(100, (int) round($used / max(1, $limit) * 100));
      $canAsk = ! empty($configured) && ! empty($current['ready']);
    @endphp
    <main class="page-main guide-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
      @if(empty($configured))
        <div class="banner error">{{ __('Cloudflare Workers AI が未設定です。設定 → AI にアカウント ID と API トークンを入れてください。') }}</div>
      @endif

      <section class="guide-hero">
        <div class="guide-hero-copy">
          <p class="guide-kicker">Cloudflare Workers AI</p>
          <h1>{{ __('生活ガイド') }}</h1>
          <p class="guide-hero-lead">{{ __('Workers AI が、話題を案内します。また話題は自分で追加できます。') }}</p>
        </div>
        <div class="guide-meter" role="group" aria-label="{{ __('本日の利用') }}">
          <span class="guide-meter-label">{{ __('本日の利用') }}</span>
          <strong class="guide-meter-value">{{ $used }}@if(! $unlimited) <small>/ {{ $limit }}</small>@endif</strong>
          @if($unlimited)
            <span class="guide-meter-badge">{{ __('上限なし') }}</span>
          @else
            <div class="guide-meter-bar"><span style="width: {{ $ratio }}%"></span></div>
            <small class="guide-meter-note">{{ __('1日 :limit 回まで', ['limit' => $limit]) }}</small>
          @endif
        </div>
      </section>

      <div class="guide-layout">
        <aside class="guide-rail">
          <p class="guide-rail-label">{{ __('話題') }}</p>
          <nav class="guide-topics" aria-label="{{ __('話題') }}">
            @foreach($topics as $item)
              <a
                href="/guide?topic={{ $item['id'] }}"
                class="guide-topic{{ ($topic ?? '') === $item['id'] ? ' is-active' : '' }}"
                @if(($topic ?? '') === $item['id']) aria-current="page" @endif
              >
                <span class="guide-topic-icon" aria-hidden="true">{{ $item['icon'] ?? '💬' }}</span>
                <span class="guide-topic-copy">
                  <strong>{{ $item['label'] }}</strong>
                  <small>{{ $item['hint'] }}</small>
                </span>
              </a>
            @endforeach
          </nav>

          @php $editing = ! empty($current['custom']) ? $current : null; @endphp

          @if($editing)
            <div class="guide-topic-tools">
              <form method="post" action="/guide/topics/{{ $editing['topic_id'] }}/move" class="guide-inline-form">
                @csrf
                <input type="hidden" name="direction" value="up" />
                <button type="submit" class="text-btn" aria-label="{{ __('上へ移動') }}">↑</button>
              </form>
              <form method="post" action="/guide/topics/{{ $editing['topic_id'] }}/move" class="guide-inline-form">
                @csrf
                <input type="hidden" name="direction" value="down" />
                <button type="submit" class="text-btn" aria-label="{{ __('下へ移動') }}">↓</button>
              </form>
              <form method="post" action="/guide/topics/{{ $editing['topic_id'] }}/delete" class="guide-inline-form" onsubmit='return confirm(@json(__('この話題を削除しますか？')))'>
                @csrf
                <button type="submit" class="text-btn danger">{{ __('削除') }}</button>
              </form>
            </div>

            <details class="guide-topic-form">
              <summary>{{ __('この話題を編集') }}</summary>
              <form method="post" action="/guide/topics/{{ $editing['topic_id'] }}/update">
                @csrf
                <label>
                  {{ __('話題の名前') }}
                  <input type="text" name="label" maxlength="60" required value="{{ $editing['label'] }}" />
                </label>
                <label>
                  {{ __('アイコン（絵文字）') }}
                  <input type="text" name="icon" maxlength="8" value="{{ $editing['icon'] }}" />
                </label>
                <label>
                  {{ __('AI への指示（任意）') }}
                  <textarea name="instruction" rows="3" maxlength="600">{{ $editing['instruction'] }}</textarea>
                </label>
                <button type="submit" class="button-link">{{ __('保存') }}</button>
              </form>
            </details>
          @endif

          @if(! empty($canAddTopic))
            <details class="guide-topic-form">
              <summary>{{ __('＋ 話題を追加') }}</summary>
              <form method="post" action="/guide/topics">
                @csrf
                <label>
                  {{ __('話題の名前') }}
                  <input type="text" name="label" maxlength="60" required placeholder="{{ __('例: 家庭菜園') }}" />
                </label>
                <label>
                  {{ __('アイコン（絵文字）') }}
                  <input type="text" name="icon" maxlength="8" placeholder="🌱" />
                </label>
                <label>
                  {{ __('AI への指示（任意）') }}
                  <textarea name="instruction" rows="3" maxlength="600" placeholder="{{ __('例: 福岡のベランダ栽培を前提に、初心者向けに短く答えて') }}"></textarea>
                </label>
                <button type="submit" class="button-link">{{ __('追加') }}</button>
              </form>
            </details>
          @else
            <p class="hint guide-topic-limit">{{ __('話題は :max 個までです。', ['max' => $maxTopics ?? 12]) }}</p>
          @endif
        </aside>

        <section class="guide-stage">
          <header class="guide-stage-head">
            <span class="guide-stage-icon" aria-hidden="true">{{ $current['icon'] ?? '💬' }}</span>
            <div>
              <h2>{{ $current['label'] ?? __('生活ガイド') }}</h2>
              <p>{{ $current['hint'] ?? '' }}</p>
            </div>
          </header>

          <div class="guide-log" id="guide-log" aria-live="polite">
            <div class="guide-empty" id="guide-empty">
              <span class="guide-empty-icon" aria-hidden="true">{{ $current['icon'] ?? '💬' }}</span>
              <h3>{{ __('何でも聞いてください') }}</h3>
              @if($canAsk && ! empty($current['samples']))
                <p>{{ __('たとえば、こんなことを聞けます。') }}</p>
                <div class="guide-suggests">
                  @foreach($current['samples'] as $sample)
                    <button type="button" class="guide-suggest" data-prompt="{{ $sample }}">{{ $sample }}</button>
                  @endforeach
                </div>
              @else
                <p>{{ $current['hint'] ?? '' }}</p>
              @endif
            </div>
          </div>

          <form id="guide-form" method="post" action="/guide/ask" class="guide-composer">
            @csrf
            <input type="hidden" name="topic" value="{{ $topic }}" />
            <label class="visually-hidden" for="guide-prompt">{{ __('質問') }}</label>
            <div class="guide-composer-box">
              <textarea id="guide-prompt" name="prompt" rows="2" maxlength="2000" required placeholder="{{ __('聞きたいことを書いてください') }}" @disabled(! $canAsk)></textarea>
              <button type="submit" class="guide-send" id="guide-submit" @disabled(! $canAsk)>
                <span>{{ __('聞く') }}</span>
              </button>
            </div>
            <div class="guide-composer-foot">
              <span class="guide-kbd-hint"><kbd>Ctrl</kbd> + <kbd>Enter</kbd> {{ __('で送信') }}</span>
              <span class="guide-status" id="guide-status" aria-live="polite"></span>
            </div>
          </form>
        </section>
      </div>
    </main>
    <script>
      (() => {
        const form = document.getElementById('guide-form');
        const log = document.getElementById('guide-log');
        const empty = document.getElementById('guide-empty');
        const promptEl = document.getElementById('guide-prompt');
        const statusEl = document.getElementById('guide-status');
        const submitEl = document.getElementById('guide-submit');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const history = [];
        const ready = @json($canAsk);
        const strings = {
          you: @json(__('あなた')),
          guide: @json(__('ガイド')),
          thinking: @json(__('考えています...')),
          error: @json(__('通信エラーが発生しました')),
        };
        let busy = false;

        const autoGrow = () => {
          if (!promptEl) return;
          promptEl.style.height = 'auto';
          promptEl.style.height = Math.min(promptEl.scrollHeight, 180) + 'px';
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

        const setBusy = (value) => {
          busy = value;
          if (submitEl) submitEl.disabled = value || !ready;
          if (statusEl) statusEl.textContent = value ? strings.thinking : '';
        };

        const send = async () => {
          if (!ready || busy) return;
          const prompt = (promptEl?.value || '').trim();
          if (!prompt) return;
          append('user', prompt);
          promptEl.value = '';
          autoGrow();
          setBusy(true);
          const typing = appendTyping();
          try {
            const res = await fetch('/guide/ask', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
              body: JSON.stringify({
                prompt,
                topic: form.querySelector('[name="topic"]')?.value || 'life',
                messages: history,
              }),
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
            setBusy(false);
            promptEl?.focus();
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

        document.querySelectorAll('.guide-suggest').forEach((btn) => {
          btn.addEventListener('click', () => {
            if (!promptEl) return;
            promptEl.value = btn.dataset.prompt || '';
            autoGrow();
            send();
          });
        });
      })();
    </script>
    @include('partials.csrf-keepalive')
  </body>
</html>
