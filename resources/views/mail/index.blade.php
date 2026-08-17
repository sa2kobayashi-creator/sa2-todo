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
            <br>
            {{ __('次の本対策: Gmail API + OAuth を導入し、共有サーバの IMAP 制限を回避して受信・本文表示を安定させる（OAuth 取得後に実装予定）。') }}
          </p>
          <p class="hint">
            {{ __('注意: 共有サーバでは Gmail の受信（IMAP）だけが遅くなったり失敗したりし、送信（SMTP）は成功することがあります。受信は @:domain の利用を推奨します。', ['domain' => $mailDomain]) }}
          </p>

          @if(!empty($accounts))
            <ul class="mail-account-list">
              @foreach($accounts as $acc)
                @php $isEditTarget = (int) ($editAccountId ?? 0) === (int) $acc['id']; @endphp
                <li class="mail-account-card{{ $isEditTarget ? ' is-editing' : '' }}" id="mail-account-{{ $acc['id'] }}">
                  <div class="mail-account-card-head">
                    <div>
                      <strong>{{ $acc['label'] }}</strong>
                      <span>{{ $acc['email'] }}（{{ $acc['providerLabel'] ?? $acc['provider'] }}）</span>
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
                          <option value="lolipop" @selected($acc['provider'] === 'lolipop')>{{ __('@:domain', ['domain' => $mailDomain]) }}</option>
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
                      @if(($acc['provider'] ?? '') === 'lolipop')
                        <p class="hint">{{ __('サーバ設定は @:domain 用に自動適用されます。', ['domain' => $mailDomain]) }}</p>
                        <input type="hidden" name="imap_host" value="{{ $acc['imapHost'] }}" />
                        <input type="hidden" name="imap_port" value="{{ $acc['imapPort'] }}" />
                        <input type="hidden" name="imap_encryption" value="{{ $acc['imapEncryption'] }}" />
                        <input type="hidden" name="smtp_host" value="{{ $acc['smtpHost'] }}" />
                        <input type="hidden" name="smtp_port" value="{{ $acc['smtpPort'] }}" />
                        <input type="hidden" name="smtp_encryption" value="{{ $acc['smtpEncryption'] }}" />
                      @else
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
                      @endif
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

                  @if(!empty($acc['isSa2PlusMailbox']))
                    @php $accLabels = $labelsByAccount[$acc['id']] ?? []; @endphp
                    <div class="mail-label-panel">
                      <h4>{{ __('ラベル・自動振り分け') }}</h4>
                      <p class="hint">{{ __('@:domain アカウント専用です。同期時にルールへ一致した受信メールをラベルフォルダへ移します。', ['domain' => $mailDomain]) }}</p>
                      <form method="post" action="/mail/accounts/{{ $acc['id'] }}/labels" class="mail-label-create">
                        @csrf
                        <label>{{ __('ラベル名') }}<input type="text" name="name" required maxlength="80" placeholder="{{ __('例: 請求') }}" /></label>
                        <label>{{ __('色') }}<input type="color" name="color" value="#0f766e" /></label>
                        <button type="submit">{{ __('ラベルを作成') }}</button>
                      </form>
                      @if($accLabels !== [])
                        <ul class="mail-label-manage-list">
                          @foreach($accLabels as $lab)
                            <li>
                              <div class="mail-label-manage-head">
                                <span class="mail-label-swatch" style="--mail-label-color: {{ $lab['color'] }}"></span>
                                <strong>{{ $lab['name'] }}</strong>
                                <code>{{ $lab['folderPath'] }}</code>
                                <form method="post" action="/mail/accounts/{{ $acc['id'] }}/labels/{{ $lab['id'] }}/delete" onsubmit="return confirm(@json(__('このラベルを削除しますか？')))">@csrf<button type="submit" class="danger">{{ __('削除') }}</button></form>
                              </div>
                              <ul class="mail-rule-list">
                                @foreach(($lab['rules'] ?? []) as $rule)
                                  <li>
                                    <span>{{ $rule['matchField'] === 'from' ? __('差出人') : __('件名') }}
                                      {{ $rule['matchOperator'] === 'equals' ? __('が一致') : __('を含む') }}:
                                      {{ $rule['matchValue'] }}</span>
                                    <form method="post" action="/mail/accounts/{{ $acc['id'] }}/label-rules/{{ $rule['id'] }}/delete">@csrf<button type="submit" class="secondary">{{ __('ルール削除') }}</button></form>
                                  </li>
                                @endforeach
                              </ul>
                              <form method="post" action="/mail/accounts/{{ $acc['id'] }}/labels/{{ $lab['id'] }}/rules" class="mail-rule-form">
                                @csrf
                                <select name="match_field">
                                  <option value="from">{{ __('差出人') }}</option>
                                  <option value="subject">{{ __('件名') }}</option>
                                </select>
                                <select name="match_operator">
                                  <option value="contains">{{ __('を含む') }}</option>
                                  <option value="equals">{{ __('が一致') }}</option>
                                </select>
                                <input type="text" name="match_value" required maxlength="255" placeholder="{{ __('例: billing@') }}" />
                                <button type="submit" class="secondary">{{ __('ルール追加') }}</button>
                              </form>
                            </li>
                          @endforeach
                        </ul>
                      @endif
                    </div>
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
                <option value="lolipop" selected>{{ __('@:domain', ['domain' => $mailDomain]) }}</option>
                <option value="gmail">Gmail</option>
                <option value="custom">{{ __('カスタム') }}</option>
              </select>
            </label>
            <label>{{ __('表示名') }}<input type="text" name="label" required placeholder="{{ $mailDomain }}" /></label>
            <label>{{ __('メールアドレス') }}<input type="email" name="email" required id="mail-email" placeholder="info@{{ $mailDomain }}" /></label>
            <label>{{ __('ユーザー名') }}<input type="text" name="username" required id="mail-username" placeholder="info@{{ $mailDomain }}" /></label>
            <label>{{ __('パスワード / アプリパスワード') }}<input type="password" name="password" required autocomplete="new-password" /></label>
            <p class="hint" id="mail-domain-server-hint">{{ __('サーバ設定は @:domain 用に自動適用されます。', ['domain' => $mailDomain]) }}</p>
            <div class="mail-server-grid" id="mail-server-fields" hidden>
              <label>{{ __('IMAPホスト') }}<input type="text" name="imap_host" id="mail-imap-host" value="imap.lolipop.jp" required /></label>
              <label>{{ __('IMAPポート') }}<input type="number" name="imap_port" id="mail-imap-port" value="993" required /></label>
              <label>{{ __('IMAP暗号化') }}
                <select name="imap_encryption" id="mail-imap-enc">
                  <option value="ssl" selected>SSL</option>
                  <option value="tls">TLS</option>
                  <option value="none">{{ __('なし') }}</option>
                </select>
              </label>
              <label>{{ __('SMTPホスト') }}<input type="text" name="smtp_host" id="mail-smtp-host" value="smtp.lolipop.jp" required /></label>
              <label>{{ __('SMTPポート') }}<input type="number" name="smtp_port" id="mail-smtp-port" value="465" required /></label>
              <label>{{ __('SMTP暗号化') }}
                <select name="smtp_encryption" id="mail-smtp-enc">
                  <option value="ssl" selected>SSL</option>
                  <option value="tls">TLS</option>
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
            {{ __('@:domain メールボックスの自動作成は近日公開予定です。公開までは管理者が手動で作成します。', ['domain' => $mailDomain]) }}
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
        <section class="mail-shell mail-shell-v2" id="mail-shell"
          data-account-id="{{ $selectedAccountId ?? '' }}"
          data-folder="{{ $folder ?? 'INBOX' }}"
          data-uid="{{ (int) ($uid ?? 0) }}"
          data-page="{{ (int) ($page ?? 1) }}"
          data-async="{{ !empty($loadMailboxAsync) ? '1' : '0' }}"
          data-compose="{{ !empty($compose) ? '1' : '0' }}"
          data-labels='@json($labelsByAccount ?? [])'
        >
          <aside class="mail-sidebar" id="mail-sidebar">
            <div class="mail-sidebar-head">
              <strong>{{ __('メール') }}</strong>
              @if(!empty($selectedAccountId))
                <a class="mail-compose-btn" href="/mail?account={{ $selectedAccountId }}&compose=1">{{ __('新規作成') }}</a>
              @endif
            </div>
            @php
              $primaryAccounts = $primaryAccounts ?? [];
              $otherAccounts = $otherAccounts ?? [];
              $otherSelected = collect($otherAccounts)->contains(fn ($a) => (int) $a['id'] === (int) ($selectedAccountId ?? 0));
              $allNavAccounts = array_merge($primaryAccounts, $otherAccounts);
            @endphp
            <div class="mail-account-trees" id="mail-account-trees">
              @if($primaryAccounts !== [])
                <p class="mail-account-group-title">&#64;{{ $mailDomain }}</p>
                @foreach($primaryAccounts as $acc)
                  <details class="mail-account-tree" data-account-id="{{ $acc['id'] }}" @if((int)$selectedAccountId === (int)$acc['id']) open @endif>
                    <summary>
                      <a href="/mail?account={{ $acc['id'] }}" class="mail-account-tree-link {{ (int)$selectedAccountId === (int)$acc['id'] ? 'active' : '' }}">{{ $acc['label'] }}</a>
                    </summary>
                    <ul class="mail-folder-nav mail-folder-nav-nested" data-account-folders="{{ $acc['id'] }}">
                      @if((int)$selectedAccountId === (int)$acc['id'])
                        <li class="hint" data-folder-placeholder>{{ __('読み込み中…') }}</li>
                      @endif
                    </ul>
                  </details>
                @endforeach
              @endif
              @if($otherAccounts !== [])
                <details class="mail-other-accounts" @if($otherSelected || $primaryAccounts === []) open @endif>
                  <summary>{{ __('その他のアカウント') }}</summary>
                  @foreach($otherAccounts as $acc)
                    <details class="mail-account-tree" data-account-id="{{ $acc['id'] }}" @if((int)$selectedAccountId === (int)$acc['id']) open @endif>
                      <summary>
                        <a href="/mail?account={{ $acc['id'] }}" class="mail-account-tree-link {{ (int)$selectedAccountId === (int)$acc['id'] ? 'active' : '' }}">{{ $acc['label'] }}</a>
                      </summary>
                      <ul class="mail-folder-nav mail-folder-nav-nested" data-account-folders="{{ $acc['id'] }}">
                        @if((int)$selectedAccountId === (int)$acc['id'])
                          <li class="hint" data-folder-placeholder>{{ __('読み込み中…') }}</li>
                        @endif
                      </ul>
                    </details>
                  @endforeach
                </details>
              @endif
              @if($allNavAccounts === [])
                <p class="hint">{{ __('アカウントを追加してください。') }}</p>
              @endif
            </div>
          </aside>

          <div class="mail-split" data-resize="sidebar" role="separator" aria-orientation="vertical" aria-label="{{ __('サイドバー幅') }}"></div>

          <div class="mail-list-pane" id="mail-list-pane">
            @if(!empty($compose) && !empty($selectedAccountId))
              <form method="post" action="/mail/accounts/{{ $selectedAccountId }}/send" class="mail-compose mail-compose-v2">
                @csrf
                <header class="mail-compose-head">
                  <div>
                    <p class="mail-compose-kicker">{{ __('新規メール') }}</p>
                    <h2>{{ __('メッセージを作成') }}</h2>
                  </div>
                  <a class="button-link secondary" href="/mail?account={{ $selectedAccountId }}">{{ __('キャンセル') }}</a>
                </header>
                <div class="mail-compose-fields">
                  <label class="mail-compose-row"><span>{{ __('宛先') }}</span><input type="email" name="to" required placeholder="name@example.com" /></label>
                  <label class="mail-compose-row"><span>{{ __('件名') }}</span><input type="text" name="subject" required /></label>
                  <label class="mail-compose-body"><span>{{ __('本文') }}</span><textarea name="body" rows="14" required></textarea></label>
                </div>
                <div class="mail-compose-actions">
                  <button type="submit" class="mail-compose-send">{{ __('送信') }}</button>
                </div>
              </form>
            @else
              <div class="mail-list-toolbar">
                <strong id="mail-folder-title">{{ __('受信トレイ') }}</strong>
                <button type="button" class="button-link secondary" id="mail-refresh-btn">{{ __('再読み込み') }}</button>
              </div>
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

          <div class="mail-split" data-resize="list" role="separator" aria-orientation="vertical" aria-label="{{ __('一覧幅') }}"></div>

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
            const fields = document.getElementById('mail-server-fields');
            const hint = document.getElementById('mail-domain-server-hint');
            const hideServers = provider.value === 'lolipop';
            if (fields) fields.hidden = hideServers;
            if (hint) hint.hidden = !hideServers;
          };
          provider.addEventListener('change', apply);
          apply();
          if (email && username) {
            email.addEventListener('change', () => {
              if (!username.value) username.value = email.value;
            });
          }
        }

        const shell = document.getElementById('mail-shell');
        if (!shell) return;

        // Resizable panes
        (function initResize() {
          const KEY = 'mail.pane.widths.v1';
          let saved = null;
          try { saved = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (_) {}
          const sidebarW = Math.max(180, Math.min(420, Number(saved && saved.sidebar) || 248));
          const listW = Math.max(240, Math.min(560, Number(saved && saved.list) || 360));
          shell.style.setProperty('--mail-sidebar-w', sidebarW + 'px');
          shell.style.setProperty('--mail-list-w', listW + 'px');

          function bind(handle, which) {
            if (!handle) return;
            handle.addEventListener('pointerdown', (e) => {
              e.preventDefault();
              handle.setPointerCapture(e.pointerId);
              const startX = e.clientX;
              const startSidebar = parseFloat(getComputedStyle(shell).getPropertyValue('--mail-sidebar-w')) || sidebarW;
              const startList = parseFloat(getComputedStyle(shell).getPropertyValue('--mail-list-w')) || listW;
              const onMove = (ev) => {
                const dx = ev.clientX - startX;
                if (which === 'sidebar') {
                  const w = Math.max(180, Math.min(420, startSidebar + dx));
                  shell.style.setProperty('--mail-sidebar-w', w + 'px');
                } else {
                  const w = Math.max(240, Math.min(560, startList + dx));
                  shell.style.setProperty('--mail-list-w', w + 'px');
                }
              };
              const onUp = (ev) => {
                handle.releasePointerCapture(ev.pointerId);
                handle.removeEventListener('pointermove', onMove);
                handle.removeEventListener('pointerup', onUp);
                try {
                  localStorage.setItem(KEY, JSON.stringify({
                    sidebar: parseFloat(getComputedStyle(shell).getPropertyValue('--mail-sidebar-w')) || 248,
                    list: parseFloat(getComputedStyle(shell).getPropertyValue('--mail-list-w')) || 360,
                  }));
                } catch (_) {}
              };
              handle.addEventListener('pointermove', onMove);
              handle.addEventListener('pointerup', onUp);
            });
          }
          bind(shell.querySelector('[data-resize="sidebar"]'), 'sidebar');
          bind(shell.querySelector('[data-resize="list"]'), 'list');
        })();

        if (shell.getAttribute('data-async') !== '1' || shell.getAttribute('data-compose') === '1') return;

        const accountId = shell.getAttribute('data-account-id');
        if (!accountId) return;

        const loading = document.getElementById('mail-loading');
        const list = document.getElementById('mail-message-list');
        const errEl = document.getElementById('mail-async-error');
        const noticeEl = document.getElementById('mail-async-notice');
        const nextWrap = document.getElementById('mail-next-page');
        const nextBtn = document.getElementById('mail-next-page-btn');
        const readEmpty = document.getElementById('mail-read-empty');
        const readContent = document.getElementById('mail-read-content');
        const folderTitle = document.getElementById('mail-folder-title');
        const labelsByAccount = (() => {
          try { return JSON.parse(shell.getAttribute('data-labels') || '{}'); } catch (_) { return {}; }
        })();
        const i18n = {
          from: @json(__('差出人')),
          to: @json(__('宛先')),
          date: @json(__('日時')),
          empty: @json(__('メールがありません。')),
          fail: @json(__('メールの読み込みに失敗しました。')),
          timeout: @json(__('メールの読み込みがタイムアウトしました。しばらくして再読み込みしてください。')),
          bodyFail: @json(__('本文の読み込みに失敗しました。')),
          loadingBody: @json(__('本文を読み込んでいます…')),
          inbox: @json(__('受信トレイ')),
          sent: @json(__('送信済み')),
          drafts: @json(__('下書き')),
          spam: @json(__('迷惑メール')),
          trash: @json(__('ゴミ箱')),
          labels: @json(__('ラベル')),
          other: @json(__('その他')),
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

        function kindOf(folder) {
          if (folder && folder.kind) return folder.kind;
          const probe = String((folder && (folder.path || folder.name)) || '').toLowerCase();
          if (probe === 'inbox') return 'inbox';
          if (probe.includes('sent')) return 'sent';
          if (probe.includes('draft')) return 'drafts';
          if (probe.includes('junk') || probe.includes('spam')) return 'spam';
          if (probe.includes('trash') || probe.includes('bin') || probe.includes('deleted')) return 'trash';
          if (probe.startsWith('labels.') || probe.startsWith('labels/')) return 'label';
          return 'other';
        }

        function displayName(folder) {
          const kind = kindOf(folder);
          if (kind === 'inbox') return i18n.inbox;
          if (kind === 'sent') return i18n.sent;
          if (kind === 'drafts') return i18n.drafts;
          if (kind === 'spam') return i18n.spam;
          if (kind === 'trash') return i18n.trash;
          if (kind === 'label') {
            const path = String(folder.path || folder.name || '');
            return path.replace(/^Labels[./]/i, '') || folder.name || path;
          }
          return folder.name || folder.path;
        }

        function pickByKind(folders, kind) {
          return (folders || []).find((f) => kindOf(f) === kind) || null;
        }

        function buildStandardFolders(folders, labels) {
          const items = [];
          const order = ['inbox', 'sent', 'drafts', 'spam', 'trash'];
          const defaults = {
            inbox: { name: 'INBOX', path: 'INBOX', kind: 'inbox', messages: 0 },
            sent: { name: 'Sent', path: 'Sent', kind: 'sent', messages: 0 },
            drafts: { name: 'Drafts', path: 'Drafts', kind: 'drafts', messages: 0 },
            spam: { name: 'Junk', path: 'Junk', kind: 'spam', messages: 0 },
            trash: { name: 'Trash', path: 'Trash', kind: 'trash', messages: 0 },
          };
          order.forEach((kind) => {
            items.push(pickByKind(folders, kind) || defaults[kind]);
          });
          const labelFolders = [];
          (labels || []).forEach((lab) => {
            labelFolders.push({
              name: lab.name,
              path: lab.folderPath,
              kind: 'label',
              messages: 0,
              color: lab.color,
            });
          });
          (folders || []).forEach((f) => {
            if (kindOf(f) !== 'label') return;
            if (labelFolders.some((x) => x.path === f.path)) return;
            labelFolders.push(f);
          });
          return { system: items, labels: labelFolders };
        }

        function renderFolders(folders, labels, active) {
          const nav = shell.querySelector('[data-account-folders="' + accountId + '"]');
          if (!nav) return;
          nav.innerHTML = '';
          const built = buildStandardFolders(folders, labels || labelsByAccount[accountId] || []);
          const appendLink = (folder, parent) => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = '#';
            a.className = folder.path === active ? 'active' : '';
            a.dataset.folderPath = folder.path;
            if (folder.color) a.style.setProperty('--mail-label-color', folder.color);
            a.innerHTML = '<span class="mail-folder-dot"></span><span class="mail-folder-name"></span><span class="mail-folder-count"></span>';
            a.querySelector('.mail-folder-name').textContent = displayName(folder);
            a.querySelector('.mail-folder-count').textContent = folder.messages ? String(folder.messages) : '';
            a.addEventListener('click', (e) => {
              e.preventDefault();
              state.folder = folder.path;
              state.page = 1;
              state.uid = 0;
              if (folderTitle) folderTitle.textContent = displayName(folder);
              loadMailbox();
            });
            li.appendChild(a);
            parent.appendChild(li);
          };
          built.system.forEach((f) => appendLink(f, nav));
          if (built.labels.length) {
            const head = document.createElement('li');
            head.className = 'mail-folder-section';
            head.textContent = i18n.labels;
            nav.appendChild(head);
            built.labels.forEach((f) => appendLink(f, nav));
          }
          const current = [...built.system, ...built.labels].find((f) => f.path === active);
          if (folderTitle) folderTitle.textContent = displayName(current || built.system[0]);
        }

        function renderMessages(messages) {
          if (!list) return;
          list.innerHTML = '';
          if (!(messages || []).length) {
            const li = document.createElement('li');
            li.className = 'hint';
            li.textContent = i18n.empty;
            list.appendChild(li);
            return;
          }
          messages.forEach((row) => {
            const li = document.createElement('li');
            if (!row.seen) li.className = 'is-unread';
            if (Number(row.uid) === Number(state.uid)) li.classList.add('is-active');
            const a = document.createElement('a');
            a.href = '#';
            a.innerHTML = '<span class="mail-from"></span><span class="mail-subject"></span><span class="mail-date"></span>';
            a.querySelector('.mail-from').textContent = row.from || '';
            a.querySelector('.mail-subject').textContent = row.subject || '';
            a.querySelector('.mail-date').textContent = formatDate(row.date);
            a.addEventListener('click', (e) => {
              e.preventDefault();
              state.uid = row.uid;
              list.querySelectorAll('li.is-active').forEach((el) => el.classList.remove('is-active'));
              li.classList.add('is-active');
              loadMessage();
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
            readEmpty.textContent = state._bodyHint || @json(__('メールを選択すると内容が表示されます。'));
            return;
          }
          state._bodyHint = '';
          readEmpty.hidden = true;
          readContent.hidden = false;
          document.getElementById('mail-read-subject').textContent = message.subject || '';
          document.getElementById('mail-read-from').textContent = i18n.from + ': ' + (message.from || '');
          document.getElementById('mail-read-to').textContent = i18n.to + ': ' + (message.to || '');
          document.getElementById('mail-read-date').textContent = message.date
            ? (i18n.date + ': ' + formatDate(message.date))
            : '';
          document.getElementById('mail-read-body').innerHTML = message.bodyHtml || ('<p class="hint">' + @json(__('(本文なし)')) + '</p>');
        }

        function loadMessage(options) {
          if (!state.uid) {
            renderMessage(null);
            return;
          }
          const forceRefresh = !!(options && options.refresh);
          state._bodyHint = i18n.loadingBody;
          renderMessage(null);
          showBanner(errEl, '');
          const params = new URLSearchParams({
            folder: state.folder || 'INBOX',
            uid: String(state.uid || 0),
          });
          if (forceRefresh) params.set('refresh', '1');
          const controller = new AbortController();
          const timer = setTimeout(() => controller.abort(), 45000);
          fetch('/mail/accounts/' + accountId + '/message?' + params.toString(), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
          })
            .then(async (res) => {
              let data = null;
              try { data = await res.json(); } catch (_) { throw new Error(i18n.bodyFail); }
              return { res, data };
            })
            .then(({ data }) => {
              if (!data || data.ok === false || !data.message) {
                state._bodyHint = (data && data.message) || i18n.bodyFail;
                showBanner(errEl, state._bodyHint);
                renderMessage(null);
                return;
              }
              renderMessage(data.message);
              const url = new URL(window.location.href);
              url.searchParams.set('account', accountId);
              url.searchParams.set('folder', state.folder);
              url.searchParams.set('uid', String(state.uid));
              url.searchParams.delete('tab');
              window.history.replaceState({}, '', url.pathname + '?' + url.searchParams.toString());
            })
            .catch((err) => {
              const isAbort = err && (err.name === 'AbortError' || String(err).includes('AbortError'));
              state._bodyHint = isAbort ? i18n.timeout : ((err && err.message) || i18n.bodyFail);
              showBanner(errEl, state._bodyHint);
              renderMessage(null);
            })
            .finally(() => clearTimeout(timer));
        }

        function loadMailbox(options) {
          const forceRefresh = !!(options && options.refresh);
          setLoading(true);
          showBanner(errEl, '');
          showBanner(noticeEl, '');
          const params = new URLSearchParams({
            folder: state.folder || 'INBOX',
            page: String(state.page || 1),
            uid: '0',
          });
          if (forceRefresh) params.set('refresh', '1');
          const controller = new AbortController();
          const timer = setTimeout(() => controller.abort(), 45000);
          fetch('/mail/accounts/' + accountId + '/mailbox?' + params.toString(), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
          })
            .then(async (res) => {
              let data = null;
              try { data = await res.json(); } catch (_) { throw new Error(i18n.fail); }
              return { res, data };
            })
            .then(({ data }) => {
              if (!data || data.ok === false) {
                showBanner(errEl, (data && data.message) || i18n.fail);
                renderFolders([], [], state.folder);
                renderMessages([]);
                renderMessage(null);
                return;
              }
              state.folder = data.folder || state.folder;
              renderFolders(data.folders || [], data.labels || [], state.folder);
              renderMessages(data.messages || []);
              if (nextWrap) nextWrap.hidden = !(data.messages && data.messages.length >= 30);
              if (data.notice) showBanner(noticeEl, data.notice);
              const url = new URL(window.location.href);
              url.searchParams.set('account', accountId);
              url.searchParams.set('folder', state.folder);
              url.searchParams.delete('tab');
              if (state.page > 1) url.searchParams.set('page', String(state.page));
              else url.searchParams.delete('page');
              if (state.uid) {
                url.searchParams.set('uid', String(state.uid));
                loadMessage({ refresh: forceRefresh });
              } else {
                url.searchParams.delete('uid');
                renderMessage(null);
              }
              window.history.replaceState({}, '', url.pathname + '?' + url.searchParams.toString());
            })
            .catch((err) => {
              const isAbort = err && (err.name === 'AbortError' || String(err).includes('AbortError'));
              showBanner(errEl, isAbort ? i18n.timeout : ((err && err.message) || i18n.fail));
              renderFolders([], [], state.folder);
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

        const refreshBtn = document.getElementById('mail-refresh-btn');
        if (refreshBtn) {
          refreshBtn.addEventListener('click', () => loadMailbox({ refresh: true }));
        }

        loadMailbox();
      })();
    </script>
    @include('partials.mobile-nav')
  </body>
</html>
