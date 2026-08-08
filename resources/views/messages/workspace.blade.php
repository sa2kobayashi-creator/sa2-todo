<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#0f766e" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('メッセージ') }} - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet" />
    @include('partials.app-css')
  </head>
  <body class="msg-app">
    @include('partials.header', ['active' => 'messages'])
    <main class="msg-shell">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="msg-layout">
        <aside class="msg-rail" aria-label="{{ __('会話一覧') }}">
          <div class="msg-rail-head">
            <p class="msg-kicker">{{ __('Inbox') }}</p>
            <h1>{{ __('メッセージ') }}</h1>
          </div>

          @if(count($workspace) === 0)
            <div class="msg-empty-rail">
              <p>{{ __('承認済みグループがありません。') }}</p>
              @if(!empty($canGroups))
                <a href="/groups" class="msg-chip-link">{{ __('グループを開く') }}</a>
              @endif
            </div>
          @else
            @foreach($workspace as $room)
              <section class="msg-rail-group">
                <a
                  href="{{ $room['href'] }}"
                  class="msg-rail-item msg-rail-channel {{ ($activeHref ?? '') === $room['href'] ? 'is-active' : '' }}"
                >
                  <span class="msg-rail-badge" aria-hidden="true">#</span>
                  <span class="msg-rail-copy">
                    <strong>{{ $room['name'] }}</strong>
                    <small>{{ $room['lastMessagePreview'] ?: __('グループ全体') }}</small>
                  </span>
                </a>
                @if(!empty($room['members']))
                  <p class="msg-rail-label">{{ __('メンバー') }}</p>
                  @foreach($room['members'] as $member)
                    <a
                      href="{{ $member['href'] }}"
                      class="msg-rail-item msg-rail-dm {{ ($activeHref ?? '') === $member['href'] ? 'is-active' : '' }}"
                    >
                      <span class="msg-avatar" aria-hidden="true">{{ $member['initials'] }}</span>
                      <span class="msg-rail-copy">
                        <strong>{{ $member['displayName'] }}</strong>
                        <small>{{ $member['lastMessagePreview'] ?: __('個別メッセージ') }}</small>
                      </span>
                    </a>
                  @endforeach
                @endif
              </section>
            @endforeach
          @endif
        </aside>

        <section class="msg-stage">
          @if(empty($activeGroup))
            <div class="msg-stage-empty">
              <p class="msg-kicker">{{ __('Ready when you are') }}</p>
              <h2>{{ __('会話を選びましょう') }}</h2>
              <p>{{ __('左のグループ全体、またはメンバーを選ぶとチャットが始まります。') }}</p>
            </div>
          @else
            <header class="msg-stage-head">
              <div>
                <p class="msg-kicker">{{ !empty($isDirect) ? __('Direct') : __('Channel') }}</p>
                <h2>
                  @if(!empty($isDirect) && !empty($activePeer))
                    {{ $activePeer['displayName'] }}
                    <span class="msg-stage-sub">{{ $activeGroup['name'] }}</span>
                  @else
                    # {{ $activeGroup['name'] }}
                  @endif
                </h2>
              </div>
            </header>

            <div
              class="msg-thread"
              id="message-thread"
              data-group-id="{{ $activeGroup['id'] }}"
              data-peer-id="{{ $activePeer['userId'] ?? '' }}"
              data-last-id="{{ count($messages) ? $messages[array_key_last($messages)]['id'] : 0 }}"
            >
              @forelse($messages as $message)
                <article class="msg-bubble {{ (int) $message['userId'] === (int) ($currentUser['id'] ?? 0) ? 'is-mine' : '' }}" data-id="{{ $message['id'] }}">
                  <div class="msg-bubble-meta">
                    <strong>{{ $message['userName'] }}</strong>
                    <time>{{ $message['createdAt'] }}</time>
                  </div>
                  @if(!empty($message['body']))
                    <p>{{ $message['body'] }}</p>
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
                </article>
              @empty
                <p class="msg-thread-hint" id="message-empty">{{ __('まだ静かです。最初の一言をどうぞ。') }}</p>
              @endforelse
            </div>

            <form method="post" action="/messages/{{ $activeGroup['id'] }}" enctype="multipart/form-data" class="msg-compose" id="message-compose">
              @csrf
              @if(!empty($activePeer))
                <input type="hidden" name="peer_user_id" value="{{ $activePeer['userId'] }}" />
              @endif
              <textarea id="message-body" name="body" rows="2" maxlength="5000" placeholder="{{ !empty($isDirect) ? __('個別メッセージを入力') : __('グループにメッセージ') }}"></textarea>
              <div class="msg-compose-bar">
                <label class="msg-attach">
                  <input type="file" name="attachments[]" id="message-attachments" multiple hidden />
                  <span>{{ __('添付') }}</span>
                </label>
                <span class="msg-attach-names" id="message-attach-names"></span>
                <button type="submit" class="msg-send">{{ __('送信') }}</button>
              </div>
              <p class="msg-hint">{{ __('1ファイル最大 :size', ['size' => $maxUploadLabel ?? '20 MB']) }}</p>
            </form>
          @endif
        </section>
      </div>
    </main>

    @if(!empty($activeGroup))
    <script>
      (function () {
        const thread = document.getElementById('message-thread')
        const form = document.getElementById('message-compose')
        const body = document.getElementById('message-body')
        const filesInput = document.getElementById('message-attachments')
        const names = document.getElementById('message-attach-names')
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
        const meId = {{ (int) ($currentUser['id'] ?? 0) }}
        if (!thread || !form) return

        const groupId = thread.dataset.groupId
        const peerId = thread.dataset.peerId || ''
        let lastId = Number(thread.dataset.lastId || 0)

        function escapeHtml(s) {
          return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
        }

        function renderMessage(message) {
          const mine = Number(message.userId) === meId
          const article = document.createElement('article')
          article.className = 'msg-bubble' + (mine ? ' is-mine' : '')
          article.dataset.id = String(message.id)
          let html = `<div class="msg-bubble-meta"><strong>${escapeHtml(message.userName)}</strong><time>${escapeHtml(message.createdAt)}</time></div>`
          if (message.body) html += `<p>${escapeHtml(message.body)}</p>`
          if (Array.isArray(message.attachments) && message.attachments.length) {
            html += '<ul class="msg-files">'
            message.attachments.forEach((file) => {
              if (file.isImage) {
                html += `<li><a href="${escapeHtml(file.url)}" target="_blank" rel="noopener"><img src="${escapeHtml(file.url)}" alt="${escapeHtml(file.name)}" /></a></li>`
              } else {
                html += `<li><a class="msg-file-link" href="${escapeHtml(file.downloadUrl)}">${escapeHtml(file.name)}</a></li>`
              }
            })
            html += '</ul>'
          }
          article.innerHTML = html
          return article
        }

        function appendMessages(list) {
          if (!list.length) return
          document.getElementById('message-empty')?.remove()
          list.forEach((message) => {
            if (thread.querySelector(`[data-id="${message.id}"]`)) return
            thread.appendChild(renderMessage(message))
            lastId = Math.max(lastId, Number(message.id) || 0)
            thread.dataset.lastId = String(lastId)
          })
          thread.scrollTop = thread.scrollHeight
        }

        async function poll() {
          try {
            const q = new URLSearchParams({ after: String(lastId) })
            if (peerId) q.set('peer', peerId)
            const res = await fetch(`/messages/${groupId}/poll?` + q.toString(), {
              headers: { Accept: 'application/json' },
              credentials: 'same-origin',
            })
            const data = await res.json()
            if (data?.ok && Array.isArray(data.messages)) appendMessages(data.messages)
          } catch (_) {}
        }

        filesInput?.addEventListener('change', () => {
          const list = Array.from(filesInput.files || []).map((f) => f.name)
          if (names) names.textContent = list.join(', ')
        })

        form.addEventListener('submit', async (e) => {
          e.preventDefault()
          const fd = new FormData(form)
          try {
            const res = await fetch(form.action, {
              method: 'POST',
              headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
              body: fd,
              credentials: 'same-origin',
            })
            const data = await res.json()
            if (!data?.ok) {
              window.alert(data?.message || @json(__('送信に失敗しました。')))
              return
            }
            body.value = ''
            if (filesInput) filesInput.value = ''
            if (names) names.textContent = ''
            appendMessages([data.message])
          } catch (_) {
            window.alert(@json(__('送信に失敗しました。')))
          }
        })

        thread.scrollTop = thread.scrollHeight
        setInterval(poll, 4000)
      })()
    </script>
    @endif
  </body>
</html>
