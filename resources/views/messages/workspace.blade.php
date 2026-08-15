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
  <body class="msg-app{{ !empty($activeGroup) ? ' msg-mobile-chat' : '' }}">
    @include('partials.header', ['active' => 'messages'])
    <main class="msg-shell">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="msg-layout{{ !empty($activeGroup) ? ' has-active-chat' : '' }}">
        <aside class="msg-rail" id="message-rail" aria-label="{{ __('会話一覧') }}">
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
              @php $roomUnread = (int) ($room['unreadCount'] ?? 0); @endphp
              <section class="msg-rail-group{{ $roomUnread > 0 || collect($room['members'] ?? [])->contains(fn ($m) => (int) ($m['unreadCount'] ?? 0) > 0) ? ' has-unread' : '' }}">
                <a
                  href="{{ $room['href'] }}"
                  class="msg-rail-item msg-rail-channel {{ ($activeHref ?? '') === $room['href'] ? 'is-active' : '' }}{{ $roomUnread > 0 ? ' is-unread' : '' }}"
                  @if($roomUnread > 0) aria-label="{{ $room['name'].' '.__('未読 :n', ['n' => $roomUnread]) }}" @endif
                >
                  <span class="msg-rail-badge" aria-hidden="true">#</span>
                  <span class="msg-rail-copy">
                    <strong>
                      <span class="msg-type-pill msg-type-channel">{{ __('グループチャット') }}</span>
                      {{ $room['name'] }}
                    </strong>
                    <small>
                      @if($roomUnread > 0)
                        {{ __('未読 :n', ['n' => $roomUnread]) }}
                        @if(!empty($room['lastMessagePreview'])) · {{ $room['lastMessagePreview'] }}@endif
                      @else
                        {{ __('グループ全体へのメッセージ') }}
                      @endif
                    </small>
                  </span>
                  @if($roomUnread > 0)
                    <span class="msg-rail-unread" aria-hidden="true">{{ $roomUnread > 99 ? '99+' : $roomUnread }}</span>
                  @endif
                </a>
                @if(!empty($room['members']))
                  <p class="msg-rail-label">{{ __('メンバー（個別）') }}</p>
                  @foreach($room['members'] as $member)
                    @php $dmUnread = (int) ($member['unreadCount'] ?? 0); @endphp
                    <a
                      href="{{ $member['href'] }}"
                      class="msg-rail-item msg-rail-dm {{ ($activeHref ?? '') === $member['href'] ? 'is-active' : '' }}{{ $dmUnread > 0 ? ' is-unread' : '' }}"
                      data-presence-user="{{ $member['userId'] }}"
                      @if($dmUnread > 0) aria-label="{{ $member['displayName'].' '.__('未読 :n', ['n' => $dmUnread]) }}" @endif
                    >
                      <span class="msg-avatar-wrap" aria-hidden="true">
                        <span class="msg-avatar">{{ $member['initials'] }}</span>
                        <span class="msg-presence {{ !empty($member['online']) ? 'is-online' : 'is-offline' }}" title="{{ !empty($member['online']) ? __('オンライン') : __('オフライン') }}"></span>
                      </span>
                      <span class="msg-rail-copy">
                        <strong>
                          <span class="msg-type-pill msg-type-dm">{{ __('個別チャット') }}</span>
                          {{ $member['displayName'] }}
                        </strong>
                        <small class="msg-presence-label" data-presence-label>
                          @if($dmUnread > 0)
                            <span class="msg-rail-unread-label">{{ __('未読 :n', ['n' => $dmUnread]) }}</span>
                            @if(!empty($member['lastMessagePreview']))
                              · {{ $member['lastMessagePreview'] }}
                            @endif
                          @else
                            {{ !empty($member['online']) ? __('オンライン') : __('オフライン') }}
                            @if(empty($member['online']) && !empty($member['lastSeenAt']))
                              · {{ $member['lastSeenAt'] }}
                            @endif
                            @if(!empty($member['lastMessagePreview']))
                              · {{ $member['lastMessagePreview'] }}
                            @endif
                          @endif
                        </small>
                      </span>
                      @if($dmUnread > 0)
                        <span class="msg-rail-unread" aria-hidden="true">{{ $dmUnread > 99 ? '99+' : $dmUnread }}</span>
                      @endif
                    </a>
                  @endforeach
                @endif
              </section>
            @endforeach
            @if(!empty($inboxRecentMessages))
              <section class="msg-rail-recent-section" aria-label="{{ __('最近のグループメッセージ') }}">
                <p class="msg-rail-label">{{ __('最近のグループメッセージ') }}</p>
                <ul class="msg-rail-recent">
                  @foreach($inboxRecentMessages as $recent)
                    <li>
                      <a href="{{ $recent['href'] }}" class="msg-rail-recent-item{{ !empty($recent['isUnread']) ? ' is-unread' : '' }}">
                        <span class="msg-rail-recent-meta">
                          <strong>
                            @if(!empty($recent['groupName']))
                              <span class="msg-rail-recent-group">{{ $recent['groupName'] }}</span>
                            @endif
                            {{ $recent['userName'] }}
                            @if(!empty($recent['isUnread']))
                              <span class="msg-unread-dot" aria-label="{{ __('未読') }}">{{ __('未読') }}</span>
                            @endif
                          </strong>
                          @if(!empty($recent['createdAt']))
                            <time>{{ $recent['createdAt'] }}</time>
                          @endif
                        </span>
                        <span class="msg-rail-recent-preview">{{ $recent['preview'] }}</span>
                      </a>
                    </li>
                  @endforeach
                </ul>
              </section>
            @endif
          @endif
        </aside>

        <section class="msg-stage">
          @if(empty($activeGroup))
            <div class="msg-stage-empty">
              <p class="msg-kicker">{{ __('Ready when you are') }}</p>
              <h2>{{ __('会話を選びましょう') }}</h2>
              <p>{{ __('左のグループチャットまたは個別チャットを選ぶと始まります。') }}</p>
            </div>
          @else
            <header class="msg-stage-head">
              <button
                type="button"
                class="msg-back-btn"
                id="message-back-btn"
                title="{{ __('会話一覧へ') }}"
                aria-label="{{ __('会話一覧へ') }}"
              >
                <span aria-hidden="true">←</span>
              </button>
              <div class="msg-stage-head-main">
                <h2>
                  @if(!empty($isDirect) && !empty($activePeer))
                    <span class="msg-stage-name-row">
                      <span class="msg-avatar-wrap msg-avatar-wrap--lg" aria-hidden="true">
                        <span class="msg-avatar">{{ $activePeer['initials'] }}</span>
                        <span class="msg-presence {{ !empty($activePeer['online']) ? 'is-online' : 'is-offline' }}" data-stage-presence></span>
                      </span>
                      <span class="msg-stage-peer-text">
                        <span class="msg-stage-peer-name">{{ $activePeer['displayName'] }}</span>
                        <span class="msg-stage-sub" data-stage-presence-label>{{ !empty($activePeer['online']) ? __('オンライン') : __('オフライン') }}</span>
                      </span>
                    </span>
                  @else
                    <span class="msg-stage-peer-text">
                      <span class="msg-stage-peer-name"># {{ $activeGroup['name'] }}</span>
                      <span class="msg-stage-sub">{{ __('全員に届くグループチャット') }}</span>
                    </span>
                  @endif
                </h2>
              </div>
              @if(!empty($isDirect) && !empty($activePeer))
                <button
                  type="button"
                  class="msg-call-btn"
                  id="message-call-btn"
                  data-token-url="/messages/{{ $activeGroup['id'] }}/dm/{{ $activePeer['userId'] }}/call-token"
                  data-cancel-url="/messages/{{ $activeGroup['id'] }}/dm/{{ $activePeer['userId'] }}/call-cancel"
                  data-peer-name="{{ $activePeer['displayName'] }}"
                  title="{{ __('音声・ビデオ通話') }}"
                  aria-label="{{ __('音声・ビデオ通話') }}"
                >
                  <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M6.62 10.79a15.46 15.46 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24 11.36 11.36 0 0 0 3.57.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.61 21 3 13.39 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11.36 11.36 0 0 0 .57 3.57 1 1 0 0 1-.24 1.02l-2.21 2.2Z"/>
                  </svg>
                </button>
              @endif
              <button type="button" class="msg-bg-btn" id="message-bg-btn" title="{{ __('背景') }}" aria-label="{{ __('背景') }}">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                  <path fill="currentColor" d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                </svg>
              </button>
            </header>

            <div
              class="msg-thread"
              id="message-thread"
              data-group-id="{{ $activeGroup['id'] }}"
              data-peer-id="{{ $activePeer['userId'] ?? '' }}"
              data-thread-type="{{ !empty($isDirect) ? 'dm' : 'channel' }}"
              data-last-id="{{ count($messages) ? $messages[array_key_last($messages)]['id'] : 0 }}"
              data-can-translate="{{ !empty($canTranslate) ? '1' : '0' }}"
            >
              @forelse($messages as $message)
                @if(!empty($firstUnreadMessageId) && (int) $message['id'] === (int) $firstUnreadMessageId)
                  <div class="msg-unread-divider" role="separator" aria-label="{{ __('ここから未読') }}">
                    <span>{{ __('ここから未読') }}</span>
                  </div>
                @endif
                @include('messages.partials.bubble', ['message' => $message, 'currentUserId' => (int) ($currentUser['id'] ?? 0)])
              @empty
                <p class="msg-thread-hint" id="message-empty">{{ __('まだ静かです。最初の一言をどうぞ。') }}</p>
              @endforelse
            </div>

            <div class="msg-compose-dock">
              <div class="msg-reply-bar" id="message-reply-bar" hidden>
                <div>
                  <strong id="message-reply-name"></strong>
                  <span id="message-reply-preview"></span>
                </div>
                <button type="button" class="msg-icon-btn" id="message-reply-clear" aria-label="{{ __('返信をやめる') }}">×</button>
              </div>

              <form method="post" action="/messages/{{ $activeGroup['id'] }}" enctype="multipart/form-data" class="msg-compose" id="message-compose">
                @csrf
                @if(!empty($activePeer))
                  <input type="hidden" name="peer_user_id" value="{{ $activePeer['userId'] }}" />
                @endif
                <input type="hidden" name="reply_to_id" id="message-reply-to" value="" />
                <span class="msg-attach-names" id="message-attach-names"></span>
                <div class="msg-compose-row">
                  <div class="msg-compose-input-wrap">
                    <textarea id="message-body" name="body" rows="1" maxlength="5000" placeholder="{{ __('メッセージ入力') }}"></textarea>
                    <div class="msg-compose-inner-actions">
                      <button type="button" class="msg-compose-emoji" id="message-emoji-btn" aria-label="{{ __('絵文字') }}" title="{{ __('絵文字') }}"><span aria-hidden="true">😊</span></button>
                      <button type="button" class="msg-quick-ok" id="message-quick-ok" aria-label="{{ __('OK') }}" title="{{ __('OKを送る') }}"><span aria-hidden="true">👍</span></button>
                    </div>
                  </div>
                  <div class="msg-compose-side">
                    <button type="submit" class="msg-send" title="{{ __('送信') }}" aria-label="{{ __('送信') }}">
                      <span class="msg-send-label">{{ __('送信') }}</span>
                      <svg class="msg-send-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M2.01 21 23 12 2.01 3 2 10l15 2-15 2z"/>
                      </svg>
                    </button>
                    <label class="msg-icon-tool" for="message-attachments" title="{{ __('添付') }}" aria-label="{{ __('添付') }}">
                      <input type="file" name="attachments[]" id="message-attachments" multiple hidden />
                      <svg class="msg-icon-svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5a2.5 2.5 0 0 1 5 0v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5a2.5 2.5 0 0 0 5 0V5c0-1.93-1.57-3.5-3.5-3.5S8 3.07 8 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-2.5z"/>
                      </svg>
                    </label>
                    <label class="msg-icon-tool" for="message-camera" title="{{ __('カメラ') }}" aria-label="{{ __('カメラ') }}">
                      <input type="file" id="message-camera" accept="image/*" capture="environment" hidden />
                      <svg class="msg-icon-svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M9 2 7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/>
                      </svg>
                    </label>
                    <button type="button" class="msg-icon-tool" id="message-mic" aria-pressed="false" title="{{ __('音声入力') }}" aria-label="{{ __('音声入力') }}">
                      <svg class="msg-icon-svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                      </svg>
                    </button>
                  </div>
                </div>
              </form>
            </div>
          @endif
        </section>
      </div>
    </main>

    <dialog class="msg-dialog" id="message-bg-dialog">
      <form method="dialog" class="msg-dialog-card">
        <h3>{{ __('チャット背景') }}</h3>
        <p class="msg-bg-hint" id="message-bg-hint">
          {{ !empty($isDirect) ? __('個別チャットの背景は自分だけに表示されます（この端末に保存）') : __('グループチャットの背景はメンバー全員に共有されます') }}
        </p>
        <div class="msg-bg-presets" id="message-bg-presets">
          <button type="button" class="msg-bg-preset" data-bg="default" title="{{ __('標準') }}"><span>{{ __('標準') }}</span></button>
          <button type="button" class="msg-bg-preset is-mint" data-bg="mint" title="{{ __('ミント') }}"><span>{{ __('ミント') }}</span></button>
          <button type="button" class="msg-bg-preset is-sky" data-bg="sky" title="{{ __('スカイ') }}"><span>{{ __('スカイ') }}</span></button>
          <button type="button" class="msg-bg-preset is-ocean" data-bg="ocean" title="{{ __('オーシャン') }}"><span>{{ __('オーシャン') }}</span></button>
          <button type="button" class="msg-bg-preset is-aurora" data-bg="aurora" title="{{ __('オーロラ') }}"><span>{{ __('オーロラ') }}</span></button>
          <button type="button" class="msg-bg-preset is-forest" data-bg="forest" title="{{ __('フォレスト') }}"><span>{{ __('フォレスト') }}</span></button>
          <button type="button" class="msg-bg-preset is-meadow" data-bg="meadow" title="{{ __('メドウ') }}"><span>{{ __('メドウ') }}</span></button>
          <button type="button" class="msg-bg-preset is-sakura" data-bg="sakura" title="{{ __('サクラ') }}"><span>{{ __('サクラ') }}</span></button>
          <button type="button" class="msg-bg-preset is-peach" data-bg="peach" title="{{ __('ピーチ') }}"><span>{{ __('ピーチ') }}</span></button>
          <button type="button" class="msg-bg-preset is-citrus" data-bg="citrus" title="{{ __('シトラス') }}"><span>{{ __('シトラス') }}</span></button>
          <button type="button" class="msg-bg-preset is-sand" data-bg="sand" title="{{ __('サンド') }}"><span>{{ __('サンド') }}</span></button>
          <button type="button" class="msg-bg-preset is-paper" data-bg="paper" title="{{ __('ペーパー') }}"><span>{{ __('ペーパー') }}</span></button>
          <button type="button" class="msg-bg-preset is-dusk" data-bg="dusk" title="{{ __('ダスク') }}"><span>{{ __('ダスク') }}</span></button>
          <button type="button" class="msg-bg-preset is-midnight" data-bg="midnight" title="{{ __('ミッドナイト') }}"><span>{{ __('ミッドナイト') }}</span></button>
          <button type="button" class="msg-bg-preset is-slate" data-bg="slate" title="{{ __('スレート') }}"><span>{{ __('スレート') }}</span></button>
          <button type="button" class="msg-bg-preset is-plain" data-bg="plain" title="{{ __('シンプル') }}"><span>{{ __('シンプル') }}</span></button>
        </div>
        <div class="msg-bg-actions">
          <label class="msg-bg-upload">
            <input type="file" id="message-bg-file" accept="image/*" hidden />
            {{ __('写真を選ぶ') }}
          </label>
          <button type="button" class="msg-dialog-cancel" id="message-bg-clear">{{ __('背景をクリア') }}</button>
        </div>
        <button type="submit" class="msg-dialog-cancel" value="cancel">{{ __('閉じる') }}</button>
      </form>
    </dialog>

    @if(!empty($activeGroup))
      @if(!empty($isDirect) && !empty($activePeer))
        <dialog class="msg-dialog msg-call-dialog" id="message-call-dialog">
          <section class="msg-dialog-card msg-call-card" aria-labelledby="message-call-title">
            <div class="msg-call-head">
              <div>
                <p class="msg-kicker">{{ __('LiveKit 試作') }}</p>
                <h3 id="message-call-title">{{ $activePeer['displayName'] }} {{ __('と通話') }}</h3>
              </div>
              <span class="msg-call-status" id="message-call-status" aria-live="polite">{{ __('接続前') }}</span>
            </div>
            <p class="msg-call-hint">{{ __('発信すると相手のメッセージ画面に着信が表示されます。Meet と同様、下の一覧でPC本体のカメラを選べます。') }}</p>
            <div class="msg-call-videos">
              <div class="msg-call-tile msg-call-local">
                <span>{{ __('自分') }}</span>
                <div id="message-call-local-video"></div>
              </div>
              <div class="msg-call-tile">
                <span id="message-call-remote-label">{{ $activePeer['displayName'] }}</span>
                <div id="message-call-remote-videos"></div>
              </div>
            </div>
            <label class="msg-call-device">
              <span>{{ __('カメラ') }}</span>
              <select id="message-call-camera-select" aria-label="{{ __('カメラを選択') }}"></select>
            </label>
            <div class="msg-call-controls">
              <button type="button" class="msg-dialog-cancel" id="message-call-mic">{{ __('マイクをオフ') }}</button>
              <button type="button" class="msg-dialog-cancel" id="message-call-camera">{{ __('カメラをオフ') }}</button>
              <button type="button" class="msg-call-end" id="message-call-end">{{ __('通話を終了') }}</button>
            </div>
          </section>
        </dialog>
      @endif

    @endif

    <dialog class="msg-dialog" id="message-emoji-dialog">
      <form method="dialog" class="msg-dialog-card">
        <h3>{{ __('絵文字') }}</h3>
        <div class="msg-emoji-grid" id="message-emoji-grid">
          @foreach($composeEmojis ?? [] as $em)
            <button type="button" class="msg-emoji-pick" data-emoji="{{ $em }}">{{ $em }}</button>
          @endforeach
        </div>
        <button type="submit" class="msg-dialog-cancel" value="cancel">{{ __('キャンセル') }}</button>
      </form>
    </dialog>

    <dialog class="msg-dialog" id="message-react-dialog">
      <form method="dialog" class="msg-dialog-card">
        <h3>{{ __('リアクション') }}</h3>
        <div class="msg-emoji-grid" id="message-react-grid">
          @foreach($reactionEmojis ?? [] as $emoji)
            <button type="button" class="msg-react-pick" data-sheet-react="{{ $emoji }}">{{ $emoji }}</button>
          @endforeach
        </div>
        <button type="submit" class="msg-dialog-cancel" value="cancel">{{ __('キャンセル') }}</button>
      </form>
    </dialog>

    <dialog class="msg-dialog" id="message-action-sheet">
      <form method="dialog" class="msg-dialog-card">
        <h3>{{ __('メッセージ操作') }}</h3>
        <div class="msg-emoji-grid" id="message-sheet-reacts">
          @foreach($reactionEmojis ?? [] as $emoji)
            <button type="button" class="msg-react-pick" data-sheet-react="{{ $emoji }}">{{ $emoji }}</button>
          @endforeach
        </div>
        <div class="msg-sheet-actions" id="message-sheet-actions"></div>
        <button type="submit" class="msg-dialog-cancel" value="cancel">{{ __('キャンセル') }}</button>
      </form>
    </dialog>

    <dialog class="msg-dialog" id="message-forward-dialog">
      <form method="dialog" class="msg-dialog-card">
        <h3>{{ __('転送先を選択') }}</h3>
        <div class="msg-forward-list" id="message-forward-list">
          @foreach($forwardTargets ?? [] as $target)
            <button
              type="button"
              class="msg-forward-item"
              data-group-id="{{ $target['groupId'] }}"
              data-peer-id="{{ $target['peerUserId'] ?? '' }}"
            >{{ $target['label'] }}</button>
          @endforeach
        </div>
        <button type="submit" class="msg-dialog-cancel" value="cancel">{{ __('キャンセル') }}</button>
      </form>
    </dialog>

    <dialog class="msg-dialog" id="message-prompt-dialog">
      <form method="dialog" class="msg-dialog-card" id="message-prompt-form">
        <h3 id="message-prompt-title">{{ __('メッセージを編集') }}</h3>
        <textarea id="message-prompt-input" rows="4" maxlength="5000"></textarea>
        <div class="msg-dialog-actions">
          <button type="button" class="msg-dialog-cancel" id="message-prompt-cancel">{{ __('キャンセル') }}</button>
          <button type="button" class="msg-send" id="message-prompt-ok">{{ __('保存') }}</button>
        </div>
      </form>
    </dialog>

    <dialog class="msg-dialog" id="message-confirm-dialog">
      <form method="dialog" class="msg-dialog-card">
        <p id="message-confirm-text"></p>
        <div class="msg-dialog-actions">
          <button type="button" class="msg-dialog-cancel" id="message-confirm-cancel">{{ __('キャンセル') }}</button>
          <button type="button" class="msg-send" id="message-confirm-ok">{{ __('OK') }}</button>
        </div>
      </form>
    </dialog>

    <dialog class="msg-sticker-pop" id="message-sticker-pop" aria-label="{{ __('スタンプ') }}">
      <span class="msg-sticker-pop-emoji" id="message-sticker-pop-emoji" aria-hidden="true"></span>
    </dialog>

    <div class="msg-toast" id="message-toast" hidden></div>

    @if(!empty($activeGroup))
    <script>
      window.__MSG_CFG__ = {
        meId: {{ (int) ($currentUser['id'] ?? 0) }},
        maxAttachmentBytes: {{ (int) ($maxAttachmentBytes ?? 20971520) }},
        maxUploadLabel: @json($maxUploadLabel ?? '20 MB'),
        isDirect: {{ !empty($isDirect) ? 'true' : 'false' }},
        wallpaper: @json($wallpaper ?? null),
        reactionEmojis: @json($reactionEmojis ?? []),
        i18n: {
          edited: @json(__('編集済み')),
          copyOk: @json(__('コピーしました')),
          copyFail: @json(__('コピーに失敗しました')),
          editPrompt: @json(__('メッセージを編集')),
          deleteConfirm: @json(__('このメッセージを削除しますか？')),
          sendFail: @json(__('送信に失敗しました。')),
          fileTooLarge: @json(__('添付ファイルは :size 以下にしてください。', ['size' => $maxUploadLabel ?? '20 MB'])),
          translateFail: @json(__('翻訳に失敗しました。')),
          voiceUnsupported: @json(__('このブラウザでは音声入力に対応していません。')),
          voiceHttps: @json(__('音声入力は HTTPS（または localhost）でのみ利用できます。')),
          voiceListening: @json(__('聞いています…')),
          sending: @json(__('送信中…')),
          reply: @json(__('返信')),
          edit: @json(__('編集')),
          delete: @json(__('削除')),
          copy: @json(__('コピー')),
          forward: @json(__('転送')),
          translate: @json(__('翻訳')),
          online: @json(__('オンライン')),
          offline: @json(__('オフライン')),
          groupMsg: @json(__('グループメッセージ')),
          dmMsg: @json(__('個別メッセージ')),
          react: @json(__('リアクション')),
          menu: @json(__('操作')),
          stickerHold: @json(__('長押しで拡大')),
          bgSaved: @json(__('背景を保存しました')),
          bgShared: @json(__('グループ背景を更新しました（全員に共有）')),
          bgTooLarge: @json(__('画像が大きすぎます。別の写真を選んでください。')),
          bgFail: @json(__('背景の読み込みに失敗しました。')),
          download: @json(__('ダウンロード')),
          saveToPhotos: @json(__('Photosに追加')),
          saveToPhotosOk: @json(__('Photosに追加しました。')),
          saveToPhotosFail: @json(__('Photosへの追加に失敗しました。')),
          backToList: @json(__('会話一覧へ')),
          unread: @json(__('未読')),
          unreadFromHere: @json(__('ここから未読')),
        }
      }
    </script>
    <script src="{{ asset('messages-workspace.js') }}?v={{ @filemtime(public_path('messages-workspace.js')) ?: time() }}" defer></script>
    @endif
  </body>
</html>
