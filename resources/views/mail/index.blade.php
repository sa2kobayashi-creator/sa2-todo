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
        <a href="/mail?tab=accounts" class="{{ $tab === 'accounts' ? 'active' : '' }}">{{ __('アカウント設定') }}</a>
        <a href="/mail?tab=domain" class="{{ $tab === 'domain' ? 'active' : '' }}">{{ __('@:domain 申請', ['domain' => $mailDomain]) }}</a>
      </nav>

      @if($tab === 'accounts')
        <section class="panel">
          <h2>{{ __('メールアカウント') }}</h2>
          <p class="hint">
            {{ __('Gmail・独自ドメイン・その他のIMAP/SMTPアカウントを登録できます。パスワードは暗号化して保存し、画面には再表示しません。') }}
            @if(!empty($gmailHelpUrl))
              <a href="{{ $gmailHelpUrl }}" target="_blank" rel="noopener noreferrer">{{ __('Gmailアプリパスワードの作り方') }}</a>
            @endif
          </p>

          @if(!empty($accounts))
            <ul class="mail-account-list">
              @foreach($accounts as $acc)
                <li>
                  <strong>{{ $acc['label'] }}</strong>
                  <span>{{ $acc['email'] }}（{{ $acc['provider'] }}）</span>
                  <div class="mail-account-actions">
                    <a class="button-link secondary" href="/mail?account={{ $acc['id'] }}">{{ __('開く') }}</a>
                    <form method="post" action="/mail/accounts/{{ $acc['id'] }}/test">@csrf<button type="submit" class="secondary">{{ __('接続テスト') }}</button></form>
                    <form method="post" action="/mail/accounts/{{ $acc['id'] }}/delete" onsubmit="return confirm(@json(__('このアカウントを削除しますか？')))">@csrf<button type="submit" class="danger">{{ __('削除') }}</button></form>
                  </div>
                  @if(!empty($acc['lastTestMessage']))
                    <p class="hint {{ ($acc['lastTestStatus'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">{{ $acc['lastTestMessage'] }}</p>
                  @endif
                </li>
              @endforeach
            </ul>
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
        <section class="mail-shell">
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
            @if(!empty($folders))
              <strong class="mail-folder-title">{{ __('フォルダ') }}</strong>
              <ul class="mail-folder-nav">
                @foreach($folders as $f)
                  <li>
                    <a
                      href="/mail?account={{ $selectedAccountId }}&folder={{ urlencode($f['path']) }}"
                      class="{{ $folder === $f['path'] ? 'active' : '' }}"
                    >{{ $f['name'] }} <span>{{ $f['messages'] }}</span></a>
                  </li>
                @endforeach
              </ul>
            @endif
            <a class="mail-settings-link" href="/mail?tab=accounts">{{ __('アカウント設定') }}</a>
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
              <ul class="mail-message-list">
                @forelse($messages as $row)
                  <li class="{{ empty($row['seen']) ? 'is-unread' : '' }}">
                    <a href="/mail?account={{ $selectedAccountId }}&folder={{ urlencode($folder) }}&uid={{ $row['uid'] }}">
                      <span class="mail-from">{{ $row['from'] }}</span>
                      <span class="mail-subject">{{ $row['subject'] }}</span>
                      <span class="mail-date">{{ $row['date'] ? \Illuminate\Support\Carbon::parse($row['date'])->timezone(config('app.timezone'))->format('m/d H:i') : '' }}</span>
                    </a>
                  </li>
                @empty
                  <li class="hint">{{ $selectedAccountId ? __('メールがありません。') : __('左からアカウントを選ぶか、アカウントを追加してください。') }}</li>
                @endforelse
              </ul>
              @if(!empty($selectedAccountId) && count($messages) >= 30)
                <a class="button-link secondary" href="/mail?account={{ $selectedAccountId }}&folder={{ urlencode($folder) }}&page={{ $page + 1 }}">{{ __('次のページ') }}</a>
              @endif
            @endif
          </div>

          <div class="mail-read-pane">
            @if(!empty($message))
              <header class="mail-read-header">
                <h2>{{ $message['subject'] }}</h2>
                <p>{{ __('差出人') }}: {{ $message['from'] }}</p>
                <p>{{ __('宛先') }}: {{ $message['to'] }}</p>
                @if(!empty($message['date']))
                  <p>{{ __('日時') }}: {{ \Illuminate\Support\Carbon::parse($message['date'])->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</p>
                @endif
              </header>
              <div class="mail-read-body">{!! $message['bodyHtml'] !!}</div>
            @else
              <p class="hint mail-read-empty">{{ __('メールを選択すると内容が表示されます。') }}</p>
            @endif
          </div>
        </section>
      @endif
    </main>

    <script>
      (function () {
        const presets = {
          gmail: { imap_host: 'imap.gmail.com', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'smtp.gmail.com', smtp_port: 465, smtp_encryption: 'ssl' },
          lolipop: { imap_host: 'imap.lolipop.jp', imap_port: 993, imap_encryption: 'ssl', smtp_host: 'smtp.lolipop.jp', smtp_port: 465, smtp_encryption: 'ssl' },
          custom: { imap_host: '', imap_port: 993, imap_encryption: 'ssl', smtp_host: '', smtp_port: 465, smtp_encryption: 'ssl' }
        };
        const provider = document.getElementById('mail-provider');
        const email = document.getElementById('mail-email');
        const username = document.getElementById('mail-username');
        if (!provider) return;
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
      })();
    </script>
    @include('partials.mobile-nav')
  </body>
</html>
