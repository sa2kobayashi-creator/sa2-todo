<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>AI相談 - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="ai-chat-page">
    @include('partials.header', ['active' => 'ai-chat'])
    <main class="page-main ai-chat-main">
      @if(!$hasApiKey)
        <div class="banner error">
          APIキーが未登録です。先に <a href="/settings?section=ai&tab=chat">設定 &gt; AI設定</a> から OpenAI / Gemini キーを追加してください。
        </div>
      @endif

      <div class="ai-chat-layout">
        <aside class="ai-chat-sidebar">
          <div class="ai-chat-sidebar-head">
            <h1 class="ai-chat-title">AI相談</h1>
            <button type="button" class="ai-chat-new-btn" id="ai-chat-new">＋ 新しい相談</button>
          </div>
          <div class="ai-chat-provider-row">
            <label>
              サービス
              <select id="ai-chat-provider">
                @foreach($providers as $p)
                  <option value="{{ $p['value'] }}" data-default-model="{{ $p['defaultModel'] }}">{{ $p['label'] }}</option>
                @endforeach
              </select>
            </label>
            <label>
              モデル
              <select id="ai-chat-model"></select>
            </label>
          </div>
          <ul class="ai-chat-thread-list" id="ai-chat-thread-list">
            @forelse($conversations as $c)
              <li>
                <a
                  href="/ai-chat?c={{ $c['id'] }}"
                  class="ai-chat-thread-item {{ ($activeConversation['id'] ?? null) === $c['id'] ? 'is-active' : '' }}"
                  data-id="{{ $c['id'] }}"
                >
                  <strong>{{ $c['title'] }}</strong>
                  <span>{{ $c['preview'] ?: ($c['provider'].' / '.$c['model']) }}</span>
                </a>
              </li>
            @empty
              <li class="ai-chat-empty-side">まだ相談がありません</li>
            @endforelse
          </ul>
        </aside>

        <section class="ai-chat-stage">
          <header class="ai-chat-stage-head" id="ai-chat-stage-head">
            <div>
              <h2 id="ai-chat-active-title">{{ $activeConversation['title'] ?? '相談を始めましょう' }}</h2>
              <p class="hint" id="ai-chat-active-meta">
                @if($activeConversation)
                  {{ $activeConversation['provider'] }} / {{ $activeConversation['model'] }}
                @else
                  左の「新しい相談」から開始するか、メッセージを送ると自動で作成されます。
                @endif
              </p>
            </div>
            @if($activeConversation)
              <button type="button" class="text-btn danger" id="ai-chat-delete" data-id="{{ $activeConversation['id'] }}">削除</button>
            @endif
          </header>

          <div class="ai-chat-messages" id="ai-chat-messages" aria-live="polite">
            @if($activeConversation)
              @foreach($activeConversation['messages'] as $msg)
                <div class="ai-chat-bubble is-{{ $msg['role'] }}">
                  <div class="ai-chat-bubble-role">{{ $msg['role'] === 'user' ? 'あなた' : 'AI' }}</div>
                  <div class="ai-chat-bubble-body">{{ $msg['content'] }}</div>
                </div>
              @endforeach
            @else
              <div class="ai-chat-welcome">
                <p>ChatGPT / Gemini に、Web版と同じように相談できます。</p>
                <p class="hint">家計・予定・メモの使い方など、日常の質問からどうぞ。</p>
              </div>
            @endif
          </div>

          <form class="ai-chat-composer" id="ai-chat-form">
            <textarea id="ai-chat-input" rows="2" placeholder="メッセージを入力（Enterで送信 / Shift+Enterで改行）" maxlength="8000"></textarea>
            <button type="submit" id="ai-chat-send" {{ $hasApiKey ? '' : 'disabled' }}>送信</button>
          </form>
        </section>
      </div>
    </main>

    <script>
      (() => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
        const providers = @json($providers)
        const hasApiKey = @json($hasApiKey)
        let conversationId = @json($activeConversation['id'] ?? null)
        let streaming = false

        const providerSelect = document.getElementById('ai-chat-provider')
        const modelSelect = document.getElementById('ai-chat-model')
        const messagesEl = document.getElementById('ai-chat-messages')
        const form = document.getElementById('ai-chat-form')
        const input = document.getElementById('ai-chat-input')
        const sendBtn = document.getElementById('ai-chat-send')
        const titleEl = document.getElementById('ai-chat-active-title')
        const metaEl = document.getElementById('ai-chat-active-meta')

        function currentProviderMeta() {
          return providers.find((p) => p.value === providerSelect.value) || providers[0]
        }

        function fillModels() {
          const meta = currentProviderMeta()
          const models = meta?.models || {}
          modelSelect.innerHTML = Object.entries(models)
            .map(([value, label]) => `<option value="${value}">${label}</option>`)
            .join('')
          modelSelect.value = meta?.defaultModel || Object.keys(models)[0] || ''
        }

        function appendBubble(role, content, streamingClass = false) {
          const welcome = messagesEl.querySelector('.ai-chat-welcome')
          if (welcome) welcome.remove()
          const div = document.createElement('div')
          div.className = `ai-chat-bubble is-${role}` + (streamingClass ? ' is-streaming' : '')
          div.innerHTML = `<div class="ai-chat-bubble-role">${role === 'user' ? 'あなた' : 'AI'}</div><div class="ai-chat-bubble-body"></div>`
          div.querySelector('.ai-chat-bubble-body').textContent = content
          messagesEl.appendChild(div)
          messagesEl.scrollTop = messagesEl.scrollHeight
          return div
        }

        async function ensureConversation() {
          if (conversationId) return conversationId
          const res = await fetch('/ai-chat/conversations', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
              provider: providerSelect.value,
              model: modelSelect.value,
            }),
          })
          const data = await res.json()
          if (!data.ok) throw new Error(data.message || '会話を作成できませんでした')
          conversationId = data.conversation.id
          history.replaceState({}, '', `/ai-chat?c=${conversationId}`)
          titleEl.textContent = data.conversation.title
          metaEl.textContent = `${data.conversation.provider} / ${data.conversation.model}`
          return conversationId
        }

        async function sendMessage(text) {
          if (!hasApiKey || streaming || !text.trim()) return
          streaming = true
          sendBtn.disabled = true
          appendBubble('user', text)
          input.value = ''
          const assistantBubble = appendBubble('assistant', '', true)
          const bodyEl = assistantBubble.querySelector('.ai-chat-bubble-body')

          try {
            const id = await ensureConversation()
            const res = await fetch(`/ai-chat/conversations/${id}/stream`, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'text/event-stream',
                'X-CSRF-TOKEN': csrf,
              },
              body: JSON.stringify({ message: text }),
            })
            if (!res.ok || !res.body) {
              throw new Error('ストリーム接続に失敗しました')
            }
            const reader = res.body.getReader()
            const decoder = new TextDecoder()
            let buffer = ''
            let full = ''
            while (true) {
              const { done, value } = await reader.read()
              if (done) break
              buffer += decoder.decode(value, { stream: true })
              const chunks = buffer.split('\n\n')
              buffer = chunks.pop() || ''
              for (const chunk of chunks) {
                const line = chunk.split('\n').find((l) => l.startsWith('data:'))
                if (!line) continue
                let payload
                try {
                  payload = JSON.parse(line.slice(5).trim())
                } catch (_) {
                  continue
                }
                if (payload.type === 'delta') {
                  full += payload.text || ''
                  bodyEl.textContent = full
                  messagesEl.scrollTop = messagesEl.scrollHeight
                } else if (payload.type === 'done') {
                  full = payload.message?.content || full
                  bodyEl.textContent = full
                  if (payload.conversation) {
                    titleEl.textContent = payload.conversation.title
                    metaEl.textContent = `${payload.conversation.provider} / ${payload.conversation.model}`
                  }
                } else if (payload.type === 'error') {
                  throw new Error(payload.message || 'エラー')
                }
              }
            }
            if (!full) bodyEl.textContent = '（応答なし）'
            assistantBubble.classList.remove('is-streaming')
          } catch (e) {
            bodyEl.textContent = e.message || '送信に失敗しました'
            assistantBubble.classList.add('is-error')
            assistantBubble.classList.remove('is-streaming')
          } finally {
            streaming = false
            sendBtn.disabled = !hasApiKey
          }
        }

        providerSelect?.addEventListener('change', fillModels)
        fillModels()
        @if($activeConversation)
          providerSelect.value = @json($activeConversation['provider'])
          fillModels()
          modelSelect.value = @json($activeConversation['model'])
        @endif

        form?.addEventListener('submit', (e) => {
          e.preventDefault()
          sendMessage(input.value)
        })
        input?.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault()
            sendMessage(input.value)
          }
        })

        document.getElementById('ai-chat-new')?.addEventListener('click', async () => {
          if (!hasApiKey) {
            window.alert('先に AI設定 でAPIキーを登録してください')
            return
          }
          try {
            const res = await fetch('/ai-chat/conversations', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
              },
              body: JSON.stringify({
                provider: providerSelect.value,
                model: modelSelect.value,
              }),
            })
            const data = await res.json()
            if (!data.ok) throw new Error(data.message || '作成に失敗しました')
            location.href = `/ai-chat?c=${data.conversation.id}`
          } catch (e) {
            window.alert(e.message || '作成に失敗しました')
          }
        })

        document.getElementById('ai-chat-delete')?.addEventListener('click', async (e) => {
          const id = e.currentTarget.dataset.id
          if (!id || !confirm('この相談を削除しますか？')) return
          const res = await fetch(`/ai-chat/conversations/${id}/delete`, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
          })
          const data = await res.json()
          if (data.ok) location.href = '/ai-chat'
          else window.alert(data.message || '削除に失敗しました')
        })
      })()
    </script>
  </body>
</html>
