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
        @if(($tab === 'inbox' || $tab === '') && empty($compose))
          <details class="mail-mobile-tools" id="mail-mobile-tools">
            <summary class="mail-mobile-tools-toggle" aria-label="{{ __('絞り込み・操作') }}" title="{{ __('絞り込み・操作') }}">☰</summary>
            <div class="mail-mobile-tools-panel" id="mail-mobile-tools-panel"></div>
          </details>
        @endif
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
          @if(!empty($mailboxIncludedInPlan))
            <p class="hint">{{ __('スタンダード契約に :count アドレス含まれます。外部の Gmail 等の接続はこれまでどおり無料です。', ['count' => $mailboxLimit ?? 1]) }}</p>
            <p class="hint">{{ __('アドレスの作成とパスワード設定は運営者が手動で行います。パスワードは別途お知らせします。届いたらメール設定に入力してください。') }}</p>
          @else
          <div class="mail-price-grid" aria-label="{{ __('料金') }}">
            <div class="mail-price-card">
              <span class="mail-price-label">{{ __('月額') }}</span>
              <strong>¥{{ number_format((int) ($addonPriceMonthly ?? 300)) }}</strong>
              <p class="hint">{{ __('税別目安・1アドレス') }}</p>
            </div>
            <div class="mail-price-card">
              <span class="mail-price-label">{{ __('年額') }}</span>
              <strong>¥{{ number_format((int) ($addonPriceYearly ?? 3000)) }}</strong>
              <p class="hint">{{ __('税別目安・1アドレス') }}</p>
            </div>
          </div>
          <p class="hint">
            {{ __('ライトプランではメールボックスは個別契約です。外部の Gmail 等の接続は無料のままです。契約あたり :count 件まで。お支払い確認後、運営者がアドレスとパスワードを作成します。', ['count' => $mailboxLimit ?? 1]) }}
          </p>
          @if(empty($hasMailboxAddon))
            <p class="banner error">
              {{ __('まだ有料オプションが有効になっていません。お申し込み・お支払いのうえ、管理者による有効化をお待ちください。') }}
            </p>
          @endif
          @endif

          @if(!empty($domainRequests))
            <ul class="mail-domain-request-list">
              @foreach($domainRequests as $req)
                <li>
                  <div class="mail-domain-request-row">
                    <strong>{{ $req['email'] }}</strong>
                    <span class="mail-status mail-status-{{ $req['status'] }}">{{ match($req['status']) {
                      'pending' => __('申請中'),
                      'approved' => __('承認済み（作成待ち）'),
                      'rejected' => __('却下'),
                      'provisioned' => __('作成済み'),
                      'suspended' => __('停止'),
                      default => $req['status'],
                    } }}</span>
                    @if(!empty($req['cancelRequested']))
                      <span class="mail-status mail-status-suspended">{{ __('解約依頼中') }}</span>
                    @endif
                  </div>
                  @if(!empty($req['adminNote']))
                    <p class="hint">{{ $req['adminNote'] }}</p>
                  @endif
                  @if(in_array($req['status'], ['pending', 'approved'], true))
                    <form method="post" action="/mail/domain-requests/{{ $req['id'] }}/cancel" class="inline-form" onsubmit='return confirm(@json(__('この申請をキャンセルしますか？')))'>
                      @csrf
                      <button type="submit" class="secondary mini-btn">{{ __('申請をキャンセル') }}</button>
                    </form>
                  @elseif($req['status'] === 'provisioned' && empty($req['cancelRequested']))
                    <form method="post" action="/mail/domain-requests/{{ $req['id'] }}/cancel" class="inline-form" onsubmit='return confirm(@json(__('メールボックスの解約を依頼しますか？停止後は送受信できなくなります。')))'>
                      @csrf
                      <button type="submit" class="danger mini-btn">{{ __('解約を依頼') }}</button>
                    </form>
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
              <p class="hint">{{ __('アドレスの作成とパスワード設定は運営者が手動で行います。パスワードは別途お知らせします。届いたら、アカウント設定の「パスワード / アプリパスワード」に入力してください。このアプリにパスワード一覧はありません。') }}</p>
              <button type="submit">{{ __('申請する') }}</button>
            </form>
          @elseif(!empty($hasMailboxAddon))
            <p class="hint">{{ __('メールボックスの枠はすでに申請済みか取得済みです。') }}</p>
          @endif
        </section>
      @else
        <div id="mail-async-error" class="banner error" hidden></div>
        <div id="mail-async-notice" class="banner notice" hidden></div>
        @php
          $selectedIsSa2 = false;
          foreach (($accounts ?? []) as $accRow) {
            if ((int) ($selectedAccountId ?? 0) === (int) ($accRow['id'] ?? 0)) {
              $selectedIsSa2 = ! empty($accRow['isSa2PlusMailbox']);
              break;
            }
          }
        @endphp
        <section class="mail-shell mail-shell-v2" id="mail-shell"
          data-account-id="{{ $selectedAccountId ?? '' }}"
          data-folder="{{ $folder ?? 'INBOX' }}"
          data-uid="{{ (int) ($uid ?? 0) }}"
          data-page="{{ (int) ($page ?? 1) }}"
          data-async="{{ !empty($loadMailboxAsync) ? '1' : '0' }}"
          data-compose="{{ !empty($compose) ? '1' : '0' }}"
          data-is-sa2-plus="{{ $selectedIsSa2 ? '1' : '0' }}"
          data-labels='@json($labelsByAccount ?? [])'
          data-user-folders='@json($userFoldersByAccount ?? [])'
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
                      <a
                        href="/mail?account={{ $acc['id'] }}"
                        class="mail-account-tree-link {{ (int)$selectedAccountId === (int)$acc['id'] ? 'active' : '' }}"
                        data-account-select="{{ $acc['id'] }}"
                      >{{ $acc['label'] }}</a>
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
                        <a
                          href="/mail?account={{ $acc['id'] }}"
                          class="mail-account-tree-link {{ (int)$selectedAccountId === (int)$acc['id'] ? 'active' : '' }}"
                          data-account-select="{{ $acc['id'] }}"
                        >{{ $acc['label'] }}</a>
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
                <div class="mail-list-tools" id="mail-list-tools">
                  <div class="mail-filter-row" id="mail-filter-row">
                    <input type="search" id="mail-filter-from" class="mail-filter-input" placeholder="{{ __('差出人で絞り込み') }}" autocomplete="off" />
                    <input type="search" id="mail-filter-subject" class="mail-filter-input" placeholder="{{ __('件名で絞り込み') }}" autocomplete="off" />
                    <button type="button" class="mail-toolbar-btn" id="mail-filter-clear">{{ __('クリア') }}</button>
                  </div>
                  <div class="mail-list-toolbar-actions">
                    <button type="button" class="mail-toolbar-btn" id="mail-bg-btn" title="{{ __('背景') }}" aria-label="{{ __('背景') }}">{{ __('背景') }}</button>
                    <button type="button" class="mail-toolbar-btn" id="mail-refresh-btn">{{ __('再読み込み') }}</button>
                  </div>
                </div>
              </div>
              <div class="mail-list-head" id="mail-list-head" hidden>
                <label class="mail-select-all">
                  <input type="checkbox" id="mail-select-all" />
                  <span>{{ __('すべて選択') }}</span>
                </label>
                <div class="mail-bulk-bar" id="mail-bulk-bar" hidden>
                  <span class="mail-bulk-count" id="mail-bulk-count"></span>
                  <button type="button" class="mail-toolbar-btn" data-bulk="read">{{ __('既読') }}</button>
                  <button type="button" class="mail-toolbar-btn" data-bulk="delete">{{ __('削除') }}</button>
                  <button type="button" class="mail-toolbar-btn" data-bulk="move">{{ __('移動') }}</button>
                  <button type="button" class="mail-toolbar-btn" data-bulk="spam" id="mail-spam-btn">{{ __('迷惑メール') }}</button>
                  <button type="button" class="mail-toolbar-btn" data-bulk="not_spam" id="mail-not-spam-btn" hidden>{{ __('迷惑ではない') }}</button>
                </div>
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
                <button type="button" class="mail-toolbar-btn" id="mail-next-page-btn">{{ __('次のページ') }}</button>
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

    <dialog class="mail-dialog" id="mail-bg-dialog">
      <form method="dialog" class="mail-dialog-card">
        <h3>{{ __('メール背景') }}</h3>
        <p class="hint">{{ __('左のアカウント一覧・メール一覧・本文の背景は、この端末だけに保存されます（メッセージ機能と同様）。') }}</p>
        <div class="mail-bg-presets" id="mail-bg-presets">
          <button type="button" class="mail-bg-preset" data-bg="default" title="{{ __('標準') }}"><span>{{ __('標準') }}</span></button>
          <button type="button" class="mail-bg-preset is-mint" data-bg="mint" title="{{ __('ミント') }}"><span>{{ __('ミント') }}</span></button>
          <button type="button" class="mail-bg-preset is-sky" data-bg="sky" title="{{ __('スカイ') }}"><span>{{ __('スカイ') }}</span></button>
          <button type="button" class="mail-bg-preset is-ocean" data-bg="ocean" title="{{ __('オーシャン') }}"><span>{{ __('オーシャン') }}</span></button>
          <button type="button" class="mail-bg-preset is-aurora" data-bg="aurora" title="{{ __('オーロラ') }}"><span>{{ __('オーロラ') }}</span></button>
          <button type="button" class="mail-bg-preset is-forest" data-bg="forest" title="{{ __('フォレスト') }}"><span>{{ __('フォレスト') }}</span></button>
          <button type="button" class="mail-bg-preset is-sakura" data-bg="sakura" title="{{ __('サクラ') }}"><span>{{ __('サクラ') }}</span></button>
          <button type="button" class="mail-bg-preset is-paper" data-bg="paper" title="{{ __('ペーパー') }}"><span>{{ __('ペーパー') }}</span></button>
          <button type="button" class="mail-bg-preset is-sand" data-bg="sand" title="{{ __('サンド') }}"><span>{{ __('サンド') }}</span></button>
          <button type="button" class="mail-bg-preset is-dusk" data-bg="dusk" title="{{ __('ダスク') }}"><span>{{ __('ダスク') }}</span></button>
          <button type="button" class="mail-bg-preset is-midnight" data-bg="midnight" title="{{ __('ミッドナイト') }}"><span>{{ __('ミッドナイト') }}</span></button>
          <button type="button" class="mail-bg-preset is-plain" data-bg="plain" title="{{ __('シンプル') }}"><span>{{ __('シンプル') }}</span></button>
        </div>
        <div class="mail-bg-actions">
          <button type="button" class="mail-toolbar-btn" id="mail-bg-clear">{{ __('背景をクリア') }}</button>
          <button type="submit" class="mail-toolbar-btn is-solid" value="cancel">{{ __('閉じる') }}</button>
        </div>
      </form>
    </dialog>

    <dialog class="mail-dialog" id="mail-folder-dialog">
      <form method="dialog" class="mail-dialog-card" id="mail-folder-form">
        <h3>{{ __('フォルダ追加') }}</h3>
        <p class="hint">{{ __('独自ドメイン用の移動先フォルダを作成します。') }}</p>
        <label class="mail-folder-dialog-label">
          {{ __('フォルダ名') }}
          <input type="text" id="mail-folder-name-input" maxlength="120" placeholder="{{ __('例: 仕事') }}" autocomplete="off" />
        </label>
        <p class="hint error" id="mail-folder-dialog-error" hidden></p>
        <div class="mail-bg-actions">
          <button type="button" class="mail-toolbar-btn" id="mail-folder-dialog-cancel">{{ __('キャンセル') }}</button>
          <button type="button" class="mail-toolbar-btn is-solid" id="mail-folder-create-submit">{{ __('作成') }}</button>
        </div>
      </form>
    </dialog>

    <dialog class="mail-dialog" id="mail-move-dialog">
      <form method="dialog" class="mail-dialog-card" id="mail-move-form">
        <h3>{{ __('移動先を選択') }}</h3>
        <p class="hint">{{ __('選択したメールの移動先フォルダを選んでください。') }}</p>
        <label class="mail-folder-dialog-label">
          {{ __('移動先') }}
          <select id="mail-move-target" class="mail-move-select mail-move-select-dialog" aria-label="{{ __('移動先') }}"></select>
        </label>
        <p class="hint error" id="mail-move-dialog-error" hidden></p>
        <div class="mail-bg-actions">
          <button type="button" class="mail-toolbar-btn" id="mail-move-dialog-cancel">{{ __('キャンセル') }}</button>
          <button type="button" class="mail-toolbar-btn is-solid" id="mail-move-dialog-submit">{{ __('移動する') }}</button>
        </div>
      </form>
    </dialog>

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

        // Background presets (local, like Messages)
        (function initMailBackground() {
          const KEY = 'mail.bg.preset.v1';
          const dialog = document.getElementById('mail-bg-dialog');
          const openBtn = document.getElementById('mail-bg-btn');
          const clearBtn = document.getElementById('mail-bg-clear');
          const presets = document.getElementById('mail-bg-presets');
          const apply = (name) => {
            const value = name && name !== 'default' ? name : '';
            if (value) shell.setAttribute('data-mail-bg', value);
            else shell.removeAttribute('data-mail-bg');
            try { localStorage.setItem(KEY, value || 'default'); } catch (_) {}
            if (presets) {
              presets.querySelectorAll('.mail-bg-preset').forEach((btn) => {
                btn.classList.toggle('is-active', (btn.getAttribute('data-bg') || '') === (value || 'default'));
              });
            }
          };
          let saved = 'default';
          try { saved = localStorage.getItem(KEY) || 'default'; } catch (_) {}
          apply(saved);
          if (openBtn && dialog) {
            openBtn.addEventListener('click', () => {
              if (typeof dialog.showModal === 'function') dialog.showModal();
            });
          }
          if (presets) {
            presets.addEventListener('click', (e) => {
              const btn = e.target.closest('.mail-bg-preset');
              if (!btn) return;
              apply(btn.getAttribute('data-bg') || 'default');
            });
          }
          if (clearBtn) clearBtn.addEventListener('click', () => apply('default'));
        })();

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

        // 選択中アカウント名クリックは再読込せず折りたたみだけ行う
        ;(function bindAccountTreeToggle() {
          const selectedId = String(shell.getAttribute('data-account-id') || '');
          document.querySelectorAll('[data-account-select]').forEach((link) => {
            link.addEventListener('click', (e) => {
              const id = String(link.getAttribute('data-account-select') || '');
              if (!id || selectedId !== id) return;
              e.preventDefault();
              e.stopPropagation();
              const details = link.closest('details.mail-account-tree');
              if (details) details.open = !details.open;
            });
          });
        })();

        // スマホ: 絞り込み／再読み込みをタブ横ハンバーガーへ移す
        ;(function setupMailMobileTools() {
          const tools = document.getElementById('mail-list-tools');
          const toolbar = document.querySelector('.mail-list-toolbar');
          const mobilePanel = document.getElementById('mail-mobile-tools-panel');
          const mobileWrap = document.getElementById('mail-mobile-tools');
          if (!tools || !toolbar || !mobilePanel || !mobileWrap) return;
          const title = document.getElementById('mail-folder-title');
          const mq = window.matchMedia('(max-width: 768px)');
          const place = () => {
            if (mq.matches) {
              if (tools.parentElement !== mobilePanel) mobilePanel.appendChild(tools);
            } else if (tools.parentElement !== toolbar) {
              if (title && title.parentElement === toolbar) toolbar.insertBefore(tools, title.nextSibling);
              else toolbar.appendChild(tools);
              mobileWrap.open = false;
            }
          };
          place();
          if (typeof mq.addEventListener === 'function') mq.addEventListener('change', place);
          else if (typeof mq.addListener === 'function') mq.addListener(place);
          document.addEventListener('click', (e) => {
            if (!mobileWrap.open) return;
            if (mobileWrap.contains(e.target)) return;
            mobileWrap.open = false;
          });
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
        const listHead = document.getElementById('mail-list-head');
        const selectAll = document.getElementById('mail-select-all');
        const bulkBar = document.getElementById('mail-bulk-bar');
        const bulkCount = document.getElementById('mail-bulk-count');
        const moveTarget = document.getElementById('mail-move-target');
        const filterFrom = document.getElementById('mail-filter-from');
        const filterSubject = document.getElementById('mail-filter-subject');
        const filterClear = document.getElementById('mail-filter-clear');
        const spamBtn = document.getElementById('mail-spam-btn');
        const notSpamBtn = document.getElementById('mail-not-spam-btn');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const isSa2Plus = shell.getAttribute('data-is-sa2-plus') === '1';
        const labelsByAccount = (() => {
          try { return JSON.parse(shell.getAttribute('data-labels') || '{}'); } catch (_) { return {}; }
        })();
        const userFoldersByAccount = (() => {
          try { return JSON.parse(shell.getAttribute('data-user-folders') || '{}'); } catch (_) { return {}; }
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
          archive: @json(__('アーカイブ')),
          cold: @json(__('長期保存')),
          trash: @json(__('ゴミ箱')),
          labels: @json(__('ラベル')),
          folders: @json(__('フォルダ')),
          other: @json(__('その他')),
          selected: @json(__(':count 件選択')),
          actionFail: @json(__('操作に失敗しました。')),
          star: @json(__('重要')),
          folderCreate: @json(__('フォルダ追加')),
          folderName: @json(__('フォルダ名')),
        };

        let state = {
          folder: shell.getAttribute('data-folder') || 'INBOX',
          page: parseInt(shell.getAttribute('data-page') || '1', 10) || 1,
          uid: parseInt(shell.getAttribute('data-uid') || '0', 10) || 0,
          allMessages: [],
          selected: new Set(),
          filterFrom: '',
          filterSubject: '',
          labels: labelsByAccount[accountId] || [],
          userFolders: userFoldersByAccount[accountId] || [],
          archivePath: null,
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
          const probe = String((folder && ((folder.path || '') + ' ' + (folder.name || ''))) || '').toLowerCase();
          if (probe === 'inbox' || probe.includes('受信')) return 'inbox';
          if (probe.includes('sent') || probe.includes('送信')) return 'sent';
          if (probe.includes('draft') || probe.includes('下書き')) return 'drafts';
          if (probe.includes('sa2.b2') || probe.includes('長期保存')) return 'cold';
          if (probe.includes('archive') || probe.includes('アーカイブ')) return 'archive';
          if (probe.includes('junk') || probe.includes('spam') || probe.includes('迷惑')) return 'spam';
          if (probe.includes('trash') || probe.includes('bin') || probe.includes('deleted') || probe.includes('ごみ') || probe.includes('ゴミ箱') || probe.includes('削除')) return 'trash';
          if (probe.trim().startsWith('labels.') || probe.trim().startsWith('labels/')
            || probe.trim().startsWith('inbox.labels.') || probe.trim().startsWith('inbox.labels/')
            || probe.trim().startsWith('inbox/labels/')) return 'label';
          if (probe.trim().startsWith('folders.') || probe.trim().startsWith('folders/')
            || probe.trim().startsWith('inbox.folders.') || probe.trim().startsWith('inbox.folders/')
            || probe.trim().startsWith('inbox/folders/')) return 'folder';
          return 'other';
        }

        function currentFolderKind() {
          return kindOf({ path: state.folder, name: state.folder });
        }

        function updateBulkUi() {
          const count = state.selected.size;
          if (bulkBar) bulkBar.hidden = count < 1 || currentFolderKind() === 'cold';
          if (bulkCount) {
            bulkCount.textContent = count > 0
              ? i18n.selected.replace(':count', String(count))
              : '';
          }
          const inSpam = currentFolderKind() === 'spam';
          if (spamBtn) spamBtn.hidden = inSpam || !isSa2Plus;
          if (notSpamBtn) notSpamBtn.hidden = !inSpam || !isSa2Plus;
          if (listHead) listHead.hidden = state.allMessages.length < 1;
          if (selectAll) {
            const visible = filteredMessages();
            const allChecked = visible.length > 0 && visible.every((row) => state.selected.has(Number(row.uid)));
            selectAll.checked = allChecked;
            selectAll.indeterminate = !allChecked && visible.some((row) => state.selected.has(Number(row.uid)));
          }
        }

        function filteredMessages() {
          const fromQ = (state.filterFrom || '').trim().toLowerCase();
          const subQ = (state.filterSubject || '').trim().toLowerCase();
          return (state.allMessages || []).filter((row) => {
            if (fromQ && !(String(row.from || '').toLowerCase().includes(fromQ))) return false;
            if (subQ && !(String(row.subject || '').toLowerCase().includes(subQ))) return false;
            return true;
          });
        }

        function renderMoveTargets() {
          if (!moveTarget) return;
          moveTarget.innerHTML = '';
          const add = (path, label) => {
            const opt = document.createElement('option');
            opt.value = path;
            opt.textContent = label;
            moveTarget.appendChild(opt);
          };
          add('INBOX', i18n.inbox);
          (state.userFolders || []).forEach((f) => add(f.folderPath, f.name));
          (state.labels || []).forEach((lab) => add(lab.folderPath, lab.name));
          if (state.archivePath) add(state.archivePath, @json(__('アーカイブ')));
        }

        async function postJson(url, body) {
          const res = await fetch(url, {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
          });
          let data = null;
          try { data = await res.json(); } catch (_) {}
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.message) || i18n.actionFail);
          }
          return data;
        }

        async function runBulk(action, extra) {
          const uids = Array.from(state.selected);
          if (!uids.length) return;
          showBanner(errEl, '');
          try {
            await postJson('/mail/accounts/' + accountId + '/messages/bulk', {
              folder: state.folder,
              uids,
              action,
              ...(extra || {}),
            });
            state.selected.clear();
            state.uid = 0;
            loadMailbox({ refresh: true });
          } catch (err) {
            showBanner(errEl, (err && err.message) || i18n.actionFail);
            throw err;
          }
        }

        async function toggleStar(uid, flagged) {
          try {
            await postJson('/mail/accounts/' + accountId + '/messages/star', {
              folder: state.folder,
              uid,
              flagged,
            });
            state.allMessages = state.allMessages.map((row) => (
              Number(row.uid) === Number(uid) ? { ...row, flagged } : row
            ));
            renderMessages(filteredMessages());
          } catch (err) {
            showBanner(errEl, (err && err.message) || i18n.actionFail);
          }
        }

        function displayName(folder) {
          const kind = kindOf(folder);
          if (kind === 'inbox') return i18n.inbox;
          if (kind === 'sent') return i18n.sent;
          if (kind === 'drafts') return i18n.drafts;
          if (kind === 'archive') return i18n.archive;
          if (kind === 'cold') return i18n.cold || @json(__('長期保存'));
          if (kind === 'spam') return i18n.spam;
          if (kind === 'trash') return i18n.trash;
          const rawName = String(folder.name || '').trim();
          const path = String(folder.path || folder.name || '');
          const looksLikePath = /^(INBOX[./])?(Folders|Labels)[./]/i.test(rawName)
            || rawName.includes('INBOX.Folders')
            || rawName.includes('INBOX.Labels')
            || /&[A-Za-z0-9+,]+-/.test(rawName);
          if ((kind === 'label' || kind === 'folder') && rawName && !looksLikePath) {
            return rawName;
          }
          let leaf = path
            .replace(/^INBOX[./]Folders[./]/i, '')
            .replace(/^Folders[./]/i, '')
            .replace(/^INBOX[./]Labels[./]/i, '')
            .replace(/^Labels[./]/i, '');
          leaf = leaf.split(/[./]/).filter(Boolean).pop() || leaf;
          return decodeImapUtf7(leaf) || rawName || path;
        }

        function decodeImapUtf7(value) {
          const s = String(value || '');
          if (!s.includes('&')) return s;
          try {
            // modified UTF-7 → UTF-16BE text (簡易)
            return s.replace(/&([^-]*)-/g, (_, body) => {
              if (body === '') return '&';
              const b64 = body.replace(/,/g, '/');
              const pad = b64.length % 4 === 0 ? '' : '='.repeat(4 - (b64.length % 4));
              const bin = atob(b64 + pad);
              const bytes = new Uint8Array(bin.length);
              for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
              return new TextDecoder('utf-16be').decode(bytes);
            });
          } catch (_) {
            return s;
          }
        }

        function sameFolderPath(a, b) {
          const norm = (p) => String(p || '').replace(/\//g, '.').toLowerCase();
          return norm(a) === norm(b);
        }

        function pickByKind(folders, kind) {
          return (folders || []).find((f) => kindOf(f) === kind) || null;
        }

        function isNamespaceFolder(path) {
          const p = String(path || '').replace(/\//g, '.').toLowerCase();
          return ['folders', 'inbox.folders', 'sa2', 'inbox.sa2', 'labels', 'inbox.labels'].includes(p);
        }

        function buildStandardFolders(folders, labels, systemFolders, userFolders) {
          const order = ['inbox', 'sent', 'drafts', 'archive', 'spam', 'trash'];
          const fromApi = Array.isArray(systemFolders) ? systemFolders : [];
          const defaults = {
            inbox: { name: 'INBOX', path: 'INBOX', kind: 'inbox', messages: 0 },
            sent: { name: 'Sent', path: 'INBOX.Sa2.Sent', kind: 'sent', messages: 0 },
            drafts: { name: 'Drafts', path: 'INBOX.Sa2.Drafts', kind: 'drafts', messages: 0 },
            archive: { name: 'Archive', path: 'INBOX.Sa2.Archive', kind: 'archive', messages: 0 },
            spam: { name: 'Junk', path: 'INBOX.Sa2.Junk', kind: 'spam', messages: 0 },
            trash: { name: 'Trash', path: 'INBOX.Sa2.Trash', kind: 'trash', messages: 0 },
          };
          const items = order.map((kind) => {
            // アーカイブは独自ドメインのみ
            if (kind === 'archive' && !isSa2Plus) return null;
            const fromSystem = fromApi.find((f) => kindOf(f) === kind);
            if (fromSystem) return fromSystem;
            const found = pickByKind(folders, kind);
            if (found) return found;
            return defaults[kind];
          }).filter(Boolean);
          const cold = fromApi.find((f) => kindOf(f) === 'cold');
          if (cold && !items.some((f) => kindOf(f) === 'cold')) items.push(cold);
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
            if (labelFolders.some((x) => sameFolderPath(x.path, f.path))) return;
            labelFolders.push(f);
          });
          const customFolders = [];
          (userFolders || []).forEach((f) => {
            customFolders.push({
              name: f.name,
              path: f.folderPath,
              kind: 'folder',
              messages: 0,
            });
          });
          // IMAP 上のゴミ／エンコード名フォルダは DB 登録分だけ表示（作成時に登録される）
          const used = new Set(
            items.map((f) => f.path)
              .concat(labelFolders.map((f) => f.path))
              .concat(customFolders.map((f) => f.path))
          );
          const others = (folders || []).filter((f) => {
            const k = kindOf(f);
            if (!f.path || used.has(f.path)) return false;
            if (isNamespaceFolder(f.path)) return false;
            if (k === 'folder' || k === 'label' || k === 'archive') return false;
            if (k !== 'other') return false;
            const p = String(f.path).replace(/\//g, '.').toLowerCase();
            if (p === 'inbox.sa2') return false;
            if (p.startsWith('inbox.sa2.') && ['sent', 'drafts', 'junk', 'spam', 'trash', 'archive'].some((s) => p.endsWith('.' + s))) {
              return false;
            }
            return true;
          });
          return { system: items, labels: labelFolders, folders: customFolders, others };
        }

        function renderFolders(folders, labels, active, systemFolders, userFolders) {
          const nav = shell.querySelector('[data-account-folders="' + accountId + '"]');
          if (!nav) return;
          nav.innerHTML = '';
          const built = buildStandardFolders(
            folders,
            labels || state.labels || labelsByAccount[accountId] || [],
            systemFolders,
            userFolders || state.userFolders || userFoldersByAccount[accountId] || []
          );
          const appendLink = (folder, parent) => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = '#';
            a.className = sameFolderPath(folder.path, active) ? 'active' : '';
            a.dataset.folderPath = folder.path;
            if (folder.color) a.style.setProperty('--mail-label-color', folder.color);
            a.innerHTML = '<span class="mail-folder-dot"></span><span class="mail-folder-name"></span><span class="mail-folder-count"></span>';
            a.querySelector('.mail-folder-name').textContent = displayName(folder);
            a.querySelector('.mail-folder-count').textContent = folder.messages ? String(folder.messages) : '';
            a.addEventListener('click', (e) => {
              e.preventDefault();
              e.stopPropagation();
              state.folder = folder.path;
              state.page = 1;
              state.uid = 0;
              state.selected.clear();
              if (folderTitle) folderTitle.textContent = displayName(folder);
              nav.querySelectorAll('a.active').forEach((el) => el.classList.remove('active'));
              a.classList.add('active');
              updateBulkUi();
              loadMailbox();
            });
            li.appendChild(a);
            parent.appendChild(li);
          };
          built.system.forEach((f) => appendLink(f, nav));
          if (isSa2Plus && (built.folders || []).length) {
            const head = document.createElement('li');
            head.className = 'mail-folder-section';
            head.textContent = i18n.folders;
            nav.appendChild(head);
            built.folders.forEach((f) => appendLink(f, nav));
          }
          if (isSa2Plus) {
            const createLi = document.createElement('li');
            createLi.className = 'mail-folder-create';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mail-folder-create-btn';
            btn.textContent = i18n.folderCreate;
            btn.addEventListener('click', (e) => {
              e.preventDefault();
              e.stopPropagation();
              const dialog = document.getElementById('mail-folder-dialog');
              const input = document.getElementById('mail-folder-name-input');
              const err = document.getElementById('mail-folder-dialog-error');
              if (err) { err.hidden = true; err.textContent = ''; }
              if (input) input.value = '';
              if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
                setTimeout(() => input && input.focus(), 0);
              }
            });
            createLi.appendChild(btn);
            nav.appendChild(createLi);
          }
          if (built.labels.length) {
            const head = document.createElement('li');
            head.className = 'mail-folder-section';
            head.textContent = i18n.labels;
            nav.appendChild(head);
            built.labels.forEach((f) => appendLink(f, nav));
          }
          if (built.others && built.others.length) {
            const head = document.createElement('li');
            head.className = 'mail-folder-section';
            head.textContent = i18n.other;
            nav.appendChild(head);
            built.others.forEach((f) => appendLink(f, nav));
          }
          const all = [...built.system, ...built.folders, ...built.labels, ...(built.others || [])];
          const current = all.find((f) => f.path === active);
          if (folderTitle) folderTitle.textContent = displayName(current || built.system[0] || { path: active, name: active });
        }

        function renderMessages(messages) {
          if (!list) return;
          list.innerHTML = '';
          if (!(messages || []).length) {
            const li = document.createElement('li');
            li.className = 'hint';
            li.textContent = i18n.empty;
            list.appendChild(li);
            updateBulkUi();
            return;
          }
          messages.forEach((row) => {
            const li = document.createElement('li');
            li.className = 'mail-row';
            if (!row.seen) li.classList.add('is-unread');
            if (Number(row.uid) === Number(state.uid)) li.classList.add('is-active');
            if (row.flagged) li.classList.add('is-flagged');

            const check = document.createElement('input');
            check.type = 'checkbox';
            check.className = 'mail-row-check';
            check.checked = state.selected.has(Number(row.uid));
            check.addEventListener('click', (e) => e.stopPropagation());
            check.addEventListener('change', () => {
              const uid = Number(row.uid);
              if (check.checked) state.selected.add(uid);
              else state.selected.delete(uid);
              updateBulkUi();
            });

            const star = document.createElement('button');
            star.type = 'button';
            star.className = 'mail-star' + (row.flagged ? ' is-on' : '');
            star.title = i18n.star;
            star.setAttribute('aria-label', i18n.star);
            star.textContent = '★';
            star.addEventListener('click', (e) => {
              e.preventDefault();
              e.stopPropagation();
              toggleStar(row.uid, !row.flagged);
            });

            const body = document.createElement('a');
            body.href = '#';
            body.className = 'mail-row-body';
            body.innerHTML = '<span class="mail-row-from"></span><span class="mail-row-subject"></span><span class="mail-row-date"></span>';
            body.querySelector('.mail-row-from').textContent = row.from || '';
            body.querySelector('.mail-row-subject').textContent = row.subject || '';
            body.querySelector('.mail-row-date').textContent = formatDate(row.date);
            body.addEventListener('click', (e) => {
              e.preventDefault();
              state.uid = row.uid;
              list.querySelectorAll('li.is-active').forEach((el) => el.classList.remove('is-active'));
              li.classList.add('is-active');
              loadMessage();
            });

            li.appendChild(check);
            li.appendChild(star);
            li.appendChild(body);
            list.appendChild(li);
          });
          updateBulkUi();
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
                renderFolders([], [], state.folder, []);
                renderMessages([]);
                renderMessage(null);
                return;
              }
              state.folder = data.folder || state.folder;
              state.labels = data.labels || state.labels;
              state.userFolders = data.userFolders || state.userFolders;
              state.archivePath = data.archivePath || state.archivePath;
              state.allMessages = data.messages || [];
              state.selected.forEach((uid) => {
                if (!state.allMessages.some((row) => Number(row.uid) === Number(uid))) {
                  state.selected.delete(uid);
                }
              });
              renderMoveTargets();
              renderFolders(
                data.folders || [],
                data.labels || [],
                state.folder,
                data.systemFolders || [],
                data.userFolders || []
              );
              renderMessages(filteredMessages());
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
              renderFolders([], [], state.folder, []);
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

        if (selectAll) {
          selectAll.addEventListener('change', () => {
            const visible = filteredMessages();
            if (selectAll.checked) {
              visible.forEach((row) => state.selected.add(Number(row.uid)));
            } else {
              visible.forEach((row) => state.selected.delete(Number(row.uid)));
            }
            renderMessages(filteredMessages());
          });
        }

        if (filterFrom) {
          filterFrom.addEventListener('input', () => {
            state.filterFrom = filterFrom.value || '';
            renderMessages(filteredMessages());
          });
        }
        if (filterSubject) {
          filterSubject.addEventListener('input', () => {
            state.filterSubject = filterSubject.value || '';
            renderMessages(filteredMessages());
          });
        }
        if (filterClear) {
          filterClear.addEventListener('click', () => {
            state.filterFrom = '';
            state.filterSubject = '';
            if (filterFrom) filterFrom.value = '';
            if (filterSubject) filterSubject.value = '';
            renderMessages(filteredMessages());
          });
        }

        if (bulkBar) {
          bulkBar.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-bulk]');
            if (!btn) return;
            const action = btn.getAttribute('data-bulk');
            if (action === 'move') {
              openMoveDialog();
              return;
            }
            if (action === 'spam') {
              const register = window.confirm(@json(__('この送信者を今後迷惑メールとして扱いますか？')));
              runBulk('spam', { registerSender: register });
              return;
            }
            runBulk(action);
          });
        }

        function openMoveDialog() {
          const dialog = document.getElementById('mail-move-dialog');
          const err = document.getElementById('mail-move-dialog-error');
          if (err) { err.hidden = true; err.textContent = ''; }
          renderMoveTargets();
          if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
        }

        (function initMoveDialog() {
          const dialog = document.getElementById('mail-move-dialog');
          const cancel = document.getElementById('mail-move-dialog-cancel');
          const submit = document.getElementById('mail-move-dialog-submit');
          const err = document.getElementById('mail-move-dialog-error');
          if (!dialog || !submit) return;
          if (cancel) {
            cancel.addEventListener('click', (e) => {
              e.preventDefault();
              dialog.close();
            });
          }
          submit.addEventListener('click', async (e) => {
            e.preventDefault();
            const target = moveTarget ? moveTarget.value : '';
            if (!target) {
              if (err) {
                err.hidden = false;
                err.textContent = @json(__('移動先を選んでください。'));
              }
              return;
            }
            submit.disabled = true;
            try {
              await runBulk('move', { targetFolder: target });
              dialog.close();
            } catch (_) {
              if (err) {
                err.hidden = false;
                err.textContent = i18n.actionFail;
              }
            } finally {
              submit.disabled = false;
            }
          });
        })();

        (function initFolderDialog() {
          const dialog = document.getElementById('mail-folder-dialog');
          const input = document.getElementById('mail-folder-name-input');
          const submit = document.getElementById('mail-folder-create-submit');
          const cancel = document.getElementById('mail-folder-dialog-cancel');
          const err = document.getElementById('mail-folder-dialog-error');
          if (!dialog || !submit || !input) return;

          if (cancel) {
            cancel.addEventListener('click', (e) => {
              e.preventDefault();
              dialog.close();
            });
          }

          const createFolder = async () => {
            const name = (input.value || '').trim();
            if (!name) {
              if (err) {
                err.hidden = false;
                err.textContent = i18n.folderName;
              }
              input.focus();
              return;
            }
            submit.disabled = true;
            if (err) { err.hidden = true; err.textContent = ''; }
            try {
              const data = await postJson('/mail/accounts/' + accountId + '/folders', { name });
              if (data.folder) {
                state.userFolders = [...(state.userFolders || []), data.folder];
                input.value = '';
                dialog.close();
                loadMailbox({ refresh: true });
              }
            } catch (e) {
              if (err) {
                err.hidden = false;
                err.textContent = (e && e.message) || i18n.actionFail;
              }
            } finally {
              submit.disabled = false;
            }
          };

          submit.addEventListener('click', (e) => {
            e.preventDefault();
            createFolder();
          });
          input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              createFolder();
            }
          });
        })();

        renderMoveTargets();
        updateBulkUi();

        loadMailbox();
      })();
    </script>
    @include('partials.mobile-nav')
  </body>
</html>
