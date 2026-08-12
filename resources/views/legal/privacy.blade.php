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
    <main class="auth-shell">
      <a href="/" class="auth-brand">
        <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="40" height="40" />
        <span>{{ config('app.name') }}</span>
      </a>
      <div class="auth-card panel" style="max-width:720px;text-align:left;">
        <h1>{{ __('プライバシーポリシー') }}</h1>
        <p class="hint">{{ __('最終更新: :date', ['date' => '2026-08-12']) }}</p>

        @if(($htmlLang ?? app()->getLocale()) === 'en')
          <section class="legal-section" style="line-height:1.7;">
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">1. Controller</h2>
            <p>Personal data for {{ config('app.name') }} is handled by the service operator. Contact the app administrator for privacy requests.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">2. Data we collect</h2>
            <p>Email address, display name, password hash, app content (todos, notes, photos/metadata, finance records, music metadata), messaging/calendar link tokens, usage counters for rate limits, and technical logs (IP, user agent) as needed for security.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">3. Purposes</h2>
            <p>Authentication, feature delivery, reminders/notifications, abuse prevention, incident response, and improving reliability. We do not sell personal data.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">4. Storage and processors</h2>
            <p>Data is separated per user in the application database. Object storage and third-party APIs (translation, AI, maps, messaging, cloud media) may receive the minimum data required to provide those features. API keys held by the operator are stored with encryption where implemented.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">5. Cookies and sessions</h2>
            <p>We use session cookies required to keep you signed in and for CSRF protection. Locale preference may be stored. We do not use third-party advertising cookies.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">6. Retention and deletion</h2>
            <p>Data is kept while your account is active. After account deletion (My Page or by an administrator), we delete associated records and stored objects as far as reasonably practicable. Operational backups may remain for up to about 30 days and are then overwritten or discarded according to hosting practice.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">7. Your rights</h2>
            <p>You may access or correct profile data in My Page, export a copy of your data, and delete your account. Additional rights under applicable law (including Japan’s Act on the Protection of Personal Information) may be exercised by contacting the administrator.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">8. International transfers</h2>
            <p>If you use features backed by overseas cloud providers, data may be processed outside Japan under those providers’ terms and safeguards.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">9. Changes</h2>
            <p>We may update this Policy. Material changes will be announced in-app or by email when practicable.</p>
          </section>
        @else
          <section class="legal-section" style="line-height:1.7;">
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">1. 事業者</h2>
            <p>{{ config('app.name') }}における個人情報の取扱いは、本サービスの運営者が行います。開示・訂正・削除等のご要望はアプリ管理者へご連絡ください。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">2. 取得する情報</h2>
            <p>メールアドレス、表示名、パスワードのハッシュ、アプリ内コンテンツ（Todo・メモ・写真／メタデータ・入出金・音楽メタデータ等）、カレンダー／メッセージ連携トークン、利用上限管理のための利用量、セキュリティに必要な技術ログ（IP・User-Agent等）。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">3. 利用目的</h2>
            <p>ログイン認証、機能提供、通知、不正利用の防止、障害対応、安定運用のため。個人データを販売しません。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">4. 保管・委託</h2>
            <p>アプリ上のデータはユーザー単位で分離して保管します。オブジェクトストレージや翻訳・AI・地図・メッセージ等の外部サービスへ、機能提供に必要な範囲で送信することがあります。運営が保持するAPIキーは、実装済みの範囲で暗号化して保管します。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">5. Cookie・セッション</h2>
            <p>ログイン維持およびCSRF対策のためのセッションCookieを使用します。言語設定を保存する場合があります。広告目的のサードパーティCookieは使用しません。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">6. 保持・削除</h2>
            <p>アカウント有効期間中は機能提供に必要な期間保持します。マイページまたは管理者によるアカウント削除後は、関連レコードおよびオブジェクトの削除に努めます。運用バックアップには最大おおよそ30日程度残存しうるものとし、その後はホスティング慣行に従い上書き・破棄します。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">7. 利用者の権利</h2>
            <p>マイページでのプロフィール確認・変更、データエクスポート、退会が可能です。個人情報の保護に関する法律その他の法令に基づく開示等の請求は管理者へご連絡ください。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">8. 国外への移転</h2>
            <p>海外クラウド／APIを利用する機能では、当該事業者の条件のもと日本国外で処理される場合があります。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">9. 改定</h2>
            <p>本ポリシーは必要に応じて改定します。重要な変更は、可能な範囲でアプリ内またはメールでお知らせします。</p>
          </section>
        @endif

        <div class="auth-links" style="margin-top:1.5rem;">
          <a href="/terms">{{ __('利用規約') }}</a>
          <a href="/register">{{ __('登録へ') }}</a>
          <a href="/login">{{ __('ログインへ') }}</a>
        </div>
      </div>
    </main>
    @include('legal.partials.mark-read', ['legalReadKey' => 'sa2_legal_privacy_read'])
  </body>
</html>
