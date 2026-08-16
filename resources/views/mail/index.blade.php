<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('メール') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'mail'])
    <main class="page-main mail-page">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
      @if(!empty($mailError))<div class="banner error">{{ $mailError }}</div>@endif

      @php $tab = $tab ?? 'inbox'; @endphp
      <nav class="mail-tabs" aria-label="{{ __('メールメニュー') }}">
        <a href="/mail" class="{{ $tab === 'inbox' || $tab === '' ? 'active' : '' }}">{{ __('受信箱') }}</a>
        <a href="/mail?tab=accounts{{ !empty($selectedAccountId) ? '&account='.$selectedAccountId : '' }}" class="{{ $tab === 'accounts' ? 'active' : '' }}">{{ __('アカウント設定') }}</a>
        <a href="/mail?tab=domain" class="{{ $tab === 'domain' ? 'active' : '' }}">{{ __('@:domain 申請', ['domain' => $mailDomain]) }}</a>
      </nav>

      @if($tab === 'accounts')
        <section class="panel">
          <h2>{{ __('メールアカウント') }}</h2>
          <p class="hint">
            {{ __('Gmail・独自ドメイン・その他のIMAP/SMTPアカウントを登録・編集できます。パスワードは暗号化して保存し、画面には再表示しません。') }}
          </p>
          <p class="banner notice">
            {{ __('Gmailは通常のパスワードでは接続できません。「Googleアカウント → セキュリティ → 2段階認証 → アプリパスワード」で発行した16桁を入力してください。') }}
            @if(!empty($gmailHelpUrl))
              <a href="{{ $gmailHelpUrl }}" target="_blank" rel="noopener noreferrer">{{ __('Gmailアプリパスワードの作り方') }}</a>
            @endif
          </p>

          @if(!empty($accounts))
            <ul class="mail-account-list">
              @foreach($accounts as $acc)
                @php $isEditTarget = (int) ($editAccountId ?? 0) === (int) $acc['id']; @endphp
                <li class="mail-account-card{{ $isEditTarget ? ' is-editing' : '' }}" id="mail-account-{{ $acc['id'] }}">
                  <div class="mail-account-card-head">
                    <div>
                      <strong>{{ $acc['label'] }}</strong>
                      <span>{{ $acc['email'] }}（{{ $acc['provider'] }}）</span>
                    </div>
                    <div class="mail-account-actions">
                      <a class="button-link secondary" href="/mail?account={{ $acc['id'] }}">{{ __('開く') }}</a>
                      <a class="button-link secondary" href="/mail?tab=accounts&account={{ $acc['id'] }}#mail-account-{{ $acc['id'] }}">{{ __('編集') }}</a>
                      <form method="post" action="/mail/accounts/{{ $acc['id'] }}/test">@csrf<button type="submit" class="secondary">{{ __('接続テスト') }}</button></form>
                      <form method="post" action="/mail/accounts/{{ $acc['id'] }}/delete" onsubmit="return confirm(@json(__('このアカウントを削除しますか？')))">@csrf<button type="submit" class="danger">{{ __('削除') }}</button></form>
                    </div>
                  </div>

                  <details class="mail-account-edit" @if($isEditTarget) open @endif>
                    <summary>{{ __('アカウント設定を編集') }}</summary>
                    <form method="post" action="/mail/accounts/{{ $acc['id'] }}/update" class="mail-account-form">
                      @csrf
                      <label>{{ __('表示名') }}
                        <input type="text" name="label" value="{{ $acc['label'] }}" required />
                      </label>
                      <label>{{ __('メールアドレス') }}
                        <input type="email" name="email" value="{{ $acc['email'] }}" required />
                      </label>
                      <label>{{ __('プリセット') }}
                        <select name="provider">
                          <option value="gmail" @selected($acc['provider'] === 'gmail')>Gmail</option>
                          <option value="lolipop" @selected($acc['provider'] === 'lolipop')>{{ __('ロリポップ / @:domain', ['domain' => $mailDomain]) }}</option>
                          <option value="custom" @selected($acc['provider'] === 'custom')>{{ __('カスタム') }}</option>
                        </select>
                      </label>
                      <label>{{ __('ユーザー名') }}
                        <input type="text" name="username" value="{{ $acc['username'] }}" required />
                      </label>
                      <label>{{ __('パスワード / アプリパスワード') }}
                        <input type="password" name="password" autocomplete="new-password" placeholder="{{ __('変更する場合のみ入力') }}" />
                      </label>
                      <p class="hint">{{ __('パスワードを空のまま保存すると、現在のパスワードを維持します。') }}</p>
                      <div class="mail-server-grid">
                        <label>{{ __('IMAPホスト') }}<input type="text" name="imap_host" value="{{ $acc['imapHost'] }}" required /></label>
                        <label>{{ __('IMAPポート') }}<input type="number" name="imap_port" value="{{ $acc['imapPort'] }}" required /></label>
                        <label>{{ __('IMAP暗号化') }}
                          <select name="imap_encryption">
                            <option value="ssl" @selected($acc['imapEncryption'] === 'ssl')>SSL</option>
                            <option value="tls" @selected($acc['imapEncryption'] === 'tls')>TLS</option>
                            <option value="none" @selected($acc['imapEncryption'] === 'none')>{{ __('なし') }}</option>
                          </select>
                        </label>
                        <label>{{ __('SMTPホスト') }}<input type="text" name="smtp_host" value="{{ $acc['smtpHost'] }}" required /></label>
                        <label>{{ __('SMTPポート') }}<input type="number" name="smtp_port" value="{{ $acc['smtpPort'] }}" required /></label>
                        <label>{{ __('SMTP暗号化') }}
                          <select name="smtp_encryption">
                            <option value="ssl" @selected($acc['smtpEncryption'] === 'ssl')>SSL</option>
                            <option value="tls" @selected($acc['smtpEncryption'] === 'tls')>TLS</option>
                            <option value="none" @selected($acc['smtpEncryption'] === 'none')>{{ __('なし') }}</option>
                          </select>
                        </label>
                      </div>
                      <label class="mail-inline-check">
                        <input type="checkbox" name="test_after_save" value="1" checked />
                        {{ __('保存後に接続テストする') }}
                      </label>
                      <button type="submit">{{ __('このアカウントを保存') }}</button>
                    </form>
                  </details>

                  @if(!empty($acc['lastTestMessage']))
                    <p class="hint {{ ($acc['lastTestStatus'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">{{ $acc['lastTestMessage'] }}</p>
                  @endif
                </li>
              @endforeach
            </ul>
          @else
            <p class="hint">{{ __('まだアカウントがありません。下のフォームから追加してください。') }}</p>
          @endif

          <h3>{{ __('アカウントを追加') }}</h3>
          <form method="post" action="/mail/accounts" class="mail-account-form" id="mail-account-form">
            @csrf
            <label>{{ __('プリセット') }}
              <select name="provider" id="mail-provider">
                <option value="gmail">Gmail</option>
                <option value="lolipop">{{ __('ロリポップ / @:domain', ['domain' => $mailDomain]) }}</option>
                <option value="custom">{{ __('カスタム') }}</option>
              </select>
            </label>
            <label>{{ __('表示名') }}<input type="text" name="label" required placeholder="Gmail" /></label>
            <label>{{ __('メールアドレス') }}<input type="email" name="email" required id="mail-email" /></label>
            <label>{{ __('ユーザー名') }}<input type="text" name="username" required id="mail-username" /></label>
            <label>{{ __('パスワード / アプリパスワード') }}<input type="password" name="password" required autocomplete="new-password" /></label>
            <div class="mail-server-grid" id="mail-server-fields">
              <label>{{ __('IMAPホスト') }}<input type="text" name="imap_host" id="mail-imap-host" value="imap.gmail.com" required /></label>
              <label>{{ __('IMAPポート') }}<input type="number" name="imap_port" id="mail-imap-port" value="993" required /></label>
              <label>{{ __('IMAP暗号化') }}
                <select name="imap_encryption" id="mail-imap-enc">
                  <option value="ssl" selected>SSL</option>
                  <option value="tls">TLS</option>
                  <option value="none">{{ __('なし') }}</option>
                </select>
              </label>
              <label>{{ __('SMTPホスト') }}<input type="text" name="smtp_host" id="mail-smtp-host" value="smtp.gmail.com" required /></label>
              <label>{{ __('SMTPポート') }}<input type="number" name="smtp_port" id="mail-smtp-port" value="587" required /></label>
              <label>{{ __('SMTP暗号化') }}
                <select name="smtp_encryption" id="mail-smtp-enc">
                  <option value="ssl">SSL</option>
                  <option value="tls" selected>TLS</option>
                  <option value="none">{{ __('なし') }}</option>
                </select>
              </label>
            </div>
            <button type="submit">{{ __('追加') }}</button>
          </form>
        </section>
      @elseif($tab === 'domain')
        <section class="panel">
          <h2>{{ __('@:domain メール申請', ['domain' => $mailDomain]) }}</h2>
          <p class="banner notice">
            {{ __('ロリポップのメールアカウント自動作成 API は近日公開予定です。公開までは管理者が管理画面で手動作成します。') }}
          </p>
          <p class="hint">
            {{ __('契約あたり無料枠は :count 件です。承認後に作成され、Outlook やスマホの標準メーラー（IMAP/SMTP）でも利用できます。主UIは本アプリのメーラーです。', ['count' => $freeQuota]) }}
          </p>

          @if(!empty($domainRequests))
            <ul class="mail-domain-request-list">
              @foreach($domainRequests as $req)
                <li>
                  <strong>{{ $req['email'] }}</strong>
                  <span class="mail-status mail-status-{{ $req['status'] }}">{{ match($req['status']) {
                    'pending' => __('申請中'),
                    'approved' => __('承認済み（作成待ち）'),
                    'rejected' => __('却下'),
                    'provisioned' => __('作成済み'),
                    'suspended' => __('停止'),
                    default => $req['status'],
                  } }}</span>
                  @if(!empty($req['adminNote']))
                    <p class="hint">{{ $req['adminNote'] }}</p>
                  @endif
                </li>
              @endforeach
            </ul>
          @endif

          @if(!empty($canRequestDomainMail))
            <form method="post" action="/mail/domain-requests" class="mail-domain-form">
              @csrf
              <label>{{ __('希望アドレス') }}
                <span class="mail-local-part">
                  <input type="text" name="local_part" required pattern="[a-z0-9][a-z0-9._-]*[a-z0-9]|[a-z0-9]{2,}" placeholder="yourname" />
                  <span>&#64;{{ $mailDomain }}</span>
                </span>
              </label>
              <label>{{ __('メモ（任意）') }}<textarea name="user_note" rows="2" maxlength="1000"></textarea></label>
              <p class="hint">{{ __('パスワードはメールボックス作成後、ご自身でメーラー設定に入力してください。管理者は平文パスワード一覧を持ちません。') }}</p>
              <button type="submit">{{ __('申請する') }}</button>
            </form>
          @else
            <p class="hint">{{ __('無料枠はすでに申請済みか取得済みです。') }}</p>
          @endif
        </section>
      @else
        <div id="mail-async-error" class="banner error" hidden></div>
        <div id="mail-async-notice" class="banner notice" hidden></div>
        <section class="mail-shell" id="mail-shell"
          data-account-id="{{ $selectedAccountId ?? '' }}"
          data-folder="{{ $folder ?? 'INBOX' }}"
          data-uid="{{ (int) ($uid ?? 0) }}"
          data-page="{{ (int) ($page ?? 1) }}"
          data-async="{{ !empty($loadMailboxAsync) ? '1' : '0' }}"
          data-compose="{{ !empty($compose) ? '1' : '0' }}"
        >
          <aside class="mail-sidebar">
            <div class="mail-sidebar-head">
              <strong>{{ __('アカウント') }}</strong>
              @if(!empty($selectedAccountId))
                <a href="/mail?account={{ $selectedAccountId }}&compose=1">{{ __('新規作成') }}</a>
              @endif
            </div>
            <ul class="mail-account-nav">
              @forelse($accounts as $acc)
                <li>
                  <a href="/mail?account={{ $acc['id'] }}" class="{{ (int)$selectedAccountId === (int)$acc['id'] ? 'active' : '' }}">{{ $acc['label'] }}</a>
                </li>
              @empty
                <li class="hint">{{ __('アカウントを追加してください。') }}</li>
              @endforelse
            </ul>
            <strong class="mail-folder-title">{{ __('フォルダ') }}</strong>
            <ul class="mail-folder-nav" id="mail-folder-nav">
              <li class="hint" id="mail-folder-placeholder">{{ !empty($selectedAccountId) ? __('読み込み中…') : '' }}</li>
            </ul>
            <a class="mail-settings-link" href="/mail?tab=accounts{{ !empty($selectedAccountId) ? '&account='.$selectedAccountId : '' }}">{{ __('アカウント設定') }}</a>
          </aside>

          <div class="mail-list-pane">
            @if(!empty($compose) && !empty($selectedAccountId))
              <form method="post" action="/mail/accounts/{{ $selectedAccountId }}/send" class="mail-compose">
                @csrf
                <h2>{{ __('新規メール') }}</h2>
                <label>{{ __('宛先') }}<input type="email" name="to" required /></label>
                <label>{{ __('件名') }}<input type="text" name="subject" required /></label>
                <label>{{ __('本文') }}<textarea name="body" rows="12" required></textarea></label>
                <div class="mail-compose-actions">
                  <button type="submit">{{ __('送信') }}</button>
                  <a class="button-link secondary" href="/mail?account={{ $selectedAccountId }}">{{ __('キャンセル') }}</a>
                </div>
              </form>
            @else
              <div class="mail-loading" id="mail-loading" @if(empty($loadMailboxAsync)) hidden @endif>
                <span class="mail-loading-spinner" aria-hidden="true"></span>
                <span>{{ __('メールを読み込んでいます…') }}</span>
              </div>
              <ul class="mail-message-list" id="mail-message-list">
                @if(empty($selectedAccountId))
                  <li class="hint">{{ __('左からアカウントを選ぶか、アカウントを追加してください。') }}</li>
                @endif
              </ul>
              <div id="mail-next-page" hidden>
                <button type="button" class="button-link secondary" id="mail-next-page-btn">{{ __('次のページ') }}</button>
              </div>
            @endif
          </div>

          <div class="mail-read-pane" id="mail-read-pane">
            <p class="hint mail-read-empty" id="mail-read-empty">{{ __('メールを選択すると内容が表示されます。') }}</p>
            <div id="mail-read-content" hidden>
              <header class="mail-read-header">
                <h2 id="mail-read-subject"></h2>
                <p id="mail-read-from"></p>
                <p id="mail-read-to"></p>
                <p id="mail-read-date"></p>
              </header>
              <div class="mail-read-body" id="mail-read-body"></div>
            </div>
          </div>
        </section>
      @endif
    </main>

    <script>
      (function () {
        const presets = {
          gmail: { imap_host: 'imap.gmail.com', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'smtp.gmail.com', smtp_port: 587, smtp_encryption: 'tls' },
          lolipop: { imap_host: 'imap.lolipop.jp', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'smtp.lolipop.jp', smtp_port: 465, smtp_encryption: 'ssl' },
          custom: { imap_host: '', imap_port: 993, imap_encryption: 'ssl', smtp_host: '', smtp_port: 465, smtp_encryption: 'ssl' }
        };
        const provider = document.getElementById('mail-provider');
        const email = document.getElementById('mail-email');
        const username = document.getElementById('mail-username');
        if (provider) {
          const apply = () => {
            const p = presets[provider.value] || presets.custom;
            document.getElementById('mail-imap-host').value = p.imap_host;
            document.getElementById('mail-imap-port').value = p.imap_port;
            document.getElementById('mail-imap-enc').value = p.imap_encryption;
            document.getElementById('mail-smtp-host').value = p.smtp_host;
            document.getElementById('mail-smtp-port').value = p.smtp_port;
            document.getElementById('mail-smtp-enc').value = p.smtp_encryption;
          };
          provider.addEventListener('change', apply);
          if (email && username) {
            email.addEventListener('change', () => {
              if (!username.value) username.value = email.value;
            });
          }
        }

        const shell = document.getElementById('mail-shell');
        if (!shell || shell.getAttribute('data-async') !== '1' || shell.getAttribute('data-compose') === '1') return;

        const accountId = shell.getAttribute('data-account-id');
        if (!accountId) return;

        const loading = document.getElementById('mail-loading');
        const folderNav = document.getElementById('mail-folder-nav');
        const list = document.getElementById('mail-message-list');
        const errEl = document.getElementById('mail-async-error');
        const noticeEl = document.getElementById('mail-async-notice');
        const nextWrap = document.getElementById('mail-next-page');
        const nextBtn = document.getElementById('mail-next-page-btn');
        const readEmpty = document.getElementById('mail-read-empty');
        const readContent = document.getElementById('mail-read-content');
        const labels = {
          from: @json(__('差出人')),
          to: @json(__('宛先')),
          date: @json(__('日時')),
          empty: @json(__('メールがありません。')),
          fail: @json(__('メールの読み込みに失敗しました。')),
        };

        let state = {
          folder: shell.getAttribute('data-folder') || 'INBOX',
          page: parseInt(shell.getAttribute('data-page') || '1', 10) || 1,
          uid: parseInt(shell.getAttribute('data-uid') || '0', 10) || 0,
        };

        function setLoading(on) {
          if (loading) loading.hidden = !on;
        }

        function showBanner(el, text) {
          if (!el) return;
          if (!text) {
            el.hidden = true;
            el.textContent = '';
            return;
          }
          el.hidden = false;
          el.textContent = text;
        }

        function formatDate(iso) {
          if (!iso) return '';
          try {
            const d = new Date(iso);
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            const h = String(d.getHours()).padStart(2, '0');
            const min = String(d.getMinutes()).padStart(2, '0');
            return m + '/' + day + ' ' + h + ':' + min;
          } catch (_) {
            return '';
          }
        }

        function renderFolders(folders, active) {
          if (!folderNav) return;
          folderNav.innerHTML = '';
          (folders || []).forEach((f) => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = '#';
            a.className = f.path === active ? 'active' : '';
            a.innerHTML = '<span></span> <span></span>';
            a.children[0].textContent = f.name || f.path;
            a.children[1].textContent = String(f.messages ?? '');
            a.addEventListener('click', (e) => {
              e.preventDefault();
              state.folder = f.path;
              state.page = 1;
              state.uid = 0;
              loadMailbox();
            });
            li.appendChild(a);
            folderNav.appendChild(li);
          });
          if (!(folders || []).length) {
            const li = document.createElement('li');
            li.className = 'hint';
            li.textContent = 'INBOX';
            folderNav.appendChild(li);
          }
        }

        function renderMessages(messages) {
          if (!list) return;
          list.innerHTML = '';
          if (!(messages || []).length) {
            const li = document.createElement('li');
            li.className = 'hint';
            li.textContent = labels.empty;
            list.appendChild(li);
            return;
          }
          messages.forEach((row) => {
            const li = document.createElement('li');
            if (!row.seen) li.className = 'is-unread';
            const a = document.createElement('a');
            a.href = '#';
            a.innerHTML = '<span class="mail-from"></span><span class="mail-subject"></span><span class="mail-date"></span>';
            a.querySelector('.mail-from').textContent = row.from || '';
            a.querySelector('.mail-subject').textContent = row.subject || '';
            a.querySelector('.mail-date').textContent = formatDate(row.date);
            a.addEventListener('click', (e) => {
              e.preventDefault();
              state.uid = row.uid;
              loadMailbox();
            });
            li.appendChild(a);
            list.appendChild(li);
          });
        }

        function renderMessage(message) {
          if (!readEmpty || !readContent) return;
          if (!message) {
            readEmpty.hidden = false;
            readContent.hidden = true;
            return;
          }
          readEmpty.hidden = true;
          readContent.hidden = false;
          document.getElementById('mail-read-subject').textContent = message.subject || '';
          document.getElementById('mail-read-from').textContent = labels.from + ': ' + (message.from || '');
          document.getElementById('mail-read-to').textContent = labels.to + ': ' + (message.to || '');
          document.getElementById('mail-read-date').textContent = message.date
            ? (labels.date + ': ' + formatDate(message.date))
            : '';
          document.getElementById('mail-read-body').innerHTML = message.bodyHtml || '';
        }

        function loadMailbox() {
          setLoading(true);
          showBanner(errEl, '');
          showBanner(noticeEl, '');
          const params = new URLSearchParams({
            folder: state.folder || 'INBOX',
            page: String(state.page || 1),
            uid: String(state.uid || 0),
          });
          const controller = new AbortController();
          const timer = setTimeout(() => controller.abort(), 25000);
          fetch('/mail/accounts/' + accountId + '/mailbox?' + params.toString(), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
          })
            .then((res) => res.json().then((data) => ({ res, data })))
            .then(({ res, data }) => {
              if (!data || data.ok === false) {
                showBanner(errEl, (data && data.message) || labels.fail);
                renderFolders([], state.folder);
                renderMessages([]);
                renderMessage(null);
                return;
              }
              state.folder = data.folder || state.folder;
              renderFolders(data.folders || [], state.folder);
              renderMessages(data.messages || []);
              renderMessage(data.message || null);
              if (nextWrap) nextWrap.hidden = !(data.messages && data.messages.length >= 30);
              if (data.notice) showBanner(noticeEl, data.notice);
              const url = new URL(window.location.href);
              url.searchParams.set('account', accountId);
              url.searchParams.set('folder', state.folder);
              url.searchParams.delete('tab');
              if (state.uid) url.searchParams.set('uid', String(state.uid));
              else url.searchParams.delete('uid');
              if (state.page > 1) url.searchParams.set('page', String(state.page));
              else url.searchParams.delete('page');
              window.history.replaceState({}, '', url.pathname + '?' + url.searchParams.toString());
            })
            .catch(() => {
              showBanner(errEl, labels.fail);
              renderFolders([], state.folder);
              renderMessages([]);
              renderMessage(null);
            })
            .finally(() => {
              clearTimeout(timer);
              setLoading(false);
            });
        }

        if (nextBtn) {
          nextBtn.addEventListener('click', () => {
            state.page += 1;
            state.uid = 0;
            loadMailbox();
          });
        }

        loadMailbox();
      })();
    </script>
    @include('partials.mobile-nav')
  </body>
</html>
