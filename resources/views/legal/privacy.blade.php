<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>{{ __('プライバシーポリシー') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body class="auth-body">
    @include('partials.auth-lang-switcher')
    <main class="auth-shell auth-shell-wide">
      <a href="/" class="auth-brand">
        <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="40" height="40" />
        <span>{{ config('app.name') }}</span>
      </a>
      <div class="auth-card panel">
        <h1>{{ __('プライバシーポリシー') }}</h1>
        <p class="hint">{{ __('最終更新: :date', ['date' => '2026-08-28']) }}</p>

        @if(($htmlLang ?? app()->getLocale()) === 'en')
          <section class="legal-section" style="line-height:1.7;">
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">1. Controller</h2>
            <p>Personal data for {{ config('app.name') }} is handled by the service operator. The operator’s name and address are shown in the <a href="/tokushoho">Notation based on the Specified Commercial Transactions Act</a>.</p>
            @if(trim((string) $privacyContactEmail) !== '')
              <p>{{ __('個人情報に関するお問い合わせ窓口') }}: <a href="mailto:{{ $privacyContactEmail }}">{{ $privacyContactEmail }}</a></p>
            @endif
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">2. Data we collect</h2>
            <p>Email address, display name, password hash, app content (todos, notes, photos/metadata, finance records, music metadata), messaging/calendar link tokens, Web Push subscription tokens, places entered for maps and transit search, usage counters for rate limits, and technical logs (IP, user agent) as needed for security.</p>
            <p>If you subscribe to a paid plan we also hold your contract status, billing history and the customer ID issued by the payment processor. <strong>Card numbers are collected directly by the payment processor and are not stored by the operator.</strong></p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">3. Purposes</h2>
            <p>Authentication, feature delivery, reminders/notifications, billing and payment management, responding to enquiries, abuse prevention, incident response, and improving reliability. We do not sell personal data.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">4. Disclosure to third parties and processors</h2>
            <p>We do not disclose personal data to third parties without your consent, except as required by law. We do entrust processing to the providers below to the extent needed to deliver the features you use, and we supervise them accordingly.</p>
            <table class="legal-table">
              <thead>
                <tr>
                  <th>{{ __('委託先') }}</th>
                  <th>{{ __('所在国') }}</th>
                  <th>{{ __('委託する内容') }}</th>
                </tr>
              </thead>
              <tbody>
                @foreach($processors as $processor)
                  <tr>
                    <td>{{ $processor['name'] ?? '' }}</td>
                    <td>{{ $processor['country'] ?? '' }}</td>
                    <td>{{ $processor['purpose'] ?? '' }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
            <p class="hint">No data is sent to a provider for a feature you do not use.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">5. Transfers outside Japan</h2>
            <p>Providers above that are located outside Japan receive personal data abroad. Information about the data protection regime in those countries and the measures each provider takes is published by the providers, and we will also supply it on request to the contact above. We rely on our agreements with these providers and their data processing terms to maintain appropriate safeguards.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">6. Security measures</h2>
            <p>Traffic is encrypted with TLS and passwords are stored hashed. Mail credentials, third-party access tokens and API keys are encrypted in the database. Data is separated per user and access is restricted by role. Only the operator can change external service connection settings.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">7. Cookies and sessions</h2>
            <p>We use session cookies required to keep you signed in and for CSRF protection. Locale and display preferences may be stored in your browser. A Service Worker is registered so the app can run as a PWA, and if you allow notifications we store your Web Push subscription. We do not use third-party advertising cookies or third-party analytics.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">8. Retention and deletion</h2>
            <p>Data is kept while your account is active. After account deletion (My Page or by an administrator), we delete associated records and stored objects as far as reasonably practicable. Operational backups may remain for up to about 30 days and are then overwritten or discarded according to hosting practice. Contract and billing records are retained for the period required by law (generally seven years for accounting records).</p>
            <p>For Light (trial) accounts, if there is no login for about 90 days we send a warning email; if there is still no login within about 14 days after that warning, we may delete the account and its data.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">9. Your rights</h2>
            <p>You may request notification of the purpose of use, disclosure, correction, addition, deletion, suspension of use or erasure, suspension of third-party provision, and disclosure of third-party provision records. From My Page you can review and change your profile, export your data, and delete your account at any time.</p>
            <p>For other requests, email the contact above. We may ask you to write from your registered email address so we can verify your identity. We respond within 30 days as a rule, at no charge.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">10. Minors</h2>
            <p>Minors should use the service with the consent of a guardian. Guardian consent is required to subscribe to a paid plan.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">11. Changes</h2>
            <p>We may update this Policy. Material changes will be announced in-app or by email when practicable.</p>
          </section>
        @else
          <section class="legal-section" style="line-height:1.7;">
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">1. 事業者</h2>
            <p>{{ config('app.name') }}における個人情報の取扱いは、本サービスの運営者が行います。事業者の氏名・所在地は<a href="/tokushoho">特定商取引法に基づく表記</a>に記載しています。</p>
            @if(trim((string) $privacyContactEmail) !== '')
              <p>{{ __('個人情報に関するお問い合わせ窓口') }}: <a href="mailto:{{ $privacyContactEmail }}">{{ $privacyContactEmail }}</a></p>
            @endif
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">2. 取得する情報</h2>
            <p>メールアドレス、表示名、パスワードのハッシュ、アプリ内コンテンツ（Todo・メモ・写真／メタデータ・入出金・音楽メタデータ等）、カレンダー／メッセージ連携トークン、Web Push の送信先トークン、地図・経路検索の入力地点、利用上限管理のための利用量、セキュリティに必要な技術ログ（IP・User-Agent等）。</p>
            <p>有料プランをご契約の場合、契約状態・請求履歴・決済事業者が発行する顧客IDを取得します。<strong>クレジットカード番号は決済事業者が直接取得し、運営者は保持しません。</strong></p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">3. 利用目的</h2>
            <p>ログイン認証、機能提供、通知の送信、料金の請求と支払管理、お問い合わせ対応、不正利用の防止、障害対応、安定運用のため。個人データを販売しません。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">4. 第三者提供と委託</h2>
            <p>法令に基づく場合を除き、あらかじめご本人の同意を得ることなく個人データを第三者に提供しません。一方、機能提供に必要な範囲で、以下の事業者に取扱いを委託します（委託先には必要かつ適切な監督を行います）。</p>
            <table class="legal-table">
              <thead>
                <tr>
                  <th>{{ __('委託先') }}</th>
                  <th>{{ __('所在国') }}</th>
                  <th>{{ __('委託する内容') }}</th>
                </tr>
              </thead>
              <tbody>
                @foreach($processors as $processor)
                  <tr>
                    <td>{{ $processor['name'] ?? '' }}</td>
                    <td>{{ $processor['country'] ?? '' }}</td>
                    <td>{{ $processor['purpose'] ?? '' }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
            <p class="hint">利用していない機能については、対応する委託先へのデータ送信は発生しません。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">5. 外国にある第三者への提供</h2>
            <p>上記の委託先のうち日本国外に所在する事業者へは、個人データが国外へ移転されます。移転先の国における個人情報保護制度、および当該事業者が講じる措置については、各事業者が公表する情報をご確認いただけるほか、前記の窓口にご請求いただければご案内します。運営者は、これらの事業者との契約および各事業者のデータ処理条項により、継続的に適切な保護措置が講じられるよう努めます。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">6. 安全管理措置</h2>
            <p>通信は TLS で暗号化し、パスワードはハッシュ化して保存します。メール接続情報・外部サービスのアクセストークン・APIキーはデータベース上で暗号化します。データはユーザー単位で分離し、権限に応じてアクセスを制限します。運営者は取扱いを行う者を限定し、外部サービスの接続設定は運営者のみが変更できます。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">7. Cookie・セッション</h2>
            <p>ログイン維持およびCSRF対策のためのセッションCookieを使用します。言語設定・画面設定をブラウザに保存する場合があります。PWA として動作させるため Service Worker を登録し、通知を許可された場合は Web Push の送信先を保存します。広告目的のサードパーティCookieおよび第三者提供型のアクセス解析は使用しません。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">8. 保持・削除</h2>
            <p>アカウント有効期間中は機能提供に必要な期間保持します。マイページまたは管理者によるアカウント削除後は、関連レコードおよびオブジェクトの削除に努めます。運用バックアップには最大おおよそ30日程度残存しうるものとし、その後はホスティング慣行に従い上書き・破棄します。ただし、契約・請求に関する記録は、法令が定める期間（帳簿書類は原則7年間）保存します。</p>
            <p>ライト（お試し）アカウントについて、約90日間ログインがない場合は警告メールを送り、警告後さらに約14日間ログインがない場合は、アカウントおよび関連データを削除することがあります。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">9. 開示等の請求</h2>
            <p>ご本人は、保有個人データの利用目的の通知、開示、訂正・追加・削除、利用停止・消去、第三者提供の停止、および第三者提供記録の開示を請求できます。マイページからはプロフィールの確認・変更、データのエクスポート、退会が随時行えます。</p>
            <p>それ以外の請求は前記の窓口宛にメールでお申し出ください。ご本人であることを確認するため、ご登録のメールアドレスからのご連絡をお願いする場合があります。原則として受付から30日以内に回答します。手数料はいただきません。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">10. 未成年者の利用</h2>
            <p>未成年の方は、保護者の同意を得たうえでご利用ください。有料プランの申込みには保護者の同意が必要です。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">11. 改定</h2>
            <p>本ポリシーは必要に応じて改定します。重要な変更は、可能な範囲でアプリ内またはメールでお知らせします。</p>
          </section>
        @endif

        <div class="auth-links" style="margin-top:1.5rem;">
          <a href="/terms">{{ __('利用規約') }}</a>
          <a href="/tokushoho">{{ __('特定商取引法に基づく表記') }}</a>
          <a href="/apply">{{ __('利用申請へ') }}</a>
          <a href="/login">{{ __('ログインへ') }}</a>
        </div>
      </div>
    </main>
    @include('legal.partials.mark-read', ['legalReadKey' => 'sa2_legal_privacy_read'])
  </body>
</html>
