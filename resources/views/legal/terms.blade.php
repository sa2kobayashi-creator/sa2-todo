<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>{{ __('利用規約') }} - {{ config('app.name') }}</title>
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
        <h1>{{ __('利用規約') }}</h1>
        <p class="hint">{{ __('最終更新: :date', ['date' => '2026-08-12']) }}</p>

        @if(($htmlLang ?? app()->getLocale()) === 'en')
          <section class="legal-section" style="line-height:1.7;">
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">1. About this service</h2>
            <p>This service ({{ config('app.name') }}) is an invitation-based personal productivity app. You may use it only with an account issued to you. Do not share login credentials.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">2. Accounts and eligibility</h2>
            <p>Registration may require an invite code and agreement to these Terms and the Privacy Policy. You must provide accurate information. Minors should use the service only with guardian consent where required by law.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">3. Acceptable use</h2>
            <p>You are responsible for content you store (todos, notes, photos, finance data, etc.). Illegal content, infringement of others’ rights, abuse of APIs, or attempts to access other users’ data are prohibited.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">4. External services and prototypes</h2>
            <p>Features may rely on third-party APIs (storage, translation, AI, maps, messaging). Usage limits, outages, or billing constraints may apply. Prototype features (including AI photo enhancement and some AI helpers) are available only to designated roles and may change or stop without notice.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">5. Data export and account deletion</h2>
            <p>You may download an export of your account data from My Page (metadata; binary media files may be omitted). You may delete your account from My Page after password confirmation. Deletion removes associated app data as far as reasonably practicable; backups may persist for a limited operational period.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">6. Disclaimer</h2>
            <p>The service is provided “as is.” To the extent permitted by law, the operator is not liable for indirect damages, data loss from misuse, or third-party service failures. Mandatory consumer protections under applicable law are not excluded.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">7. Governing law</h2>
            <p>These Terms are governed by the laws of Japan. Disputes shall be submitted to the courts with jurisdiction over the operator’s principal place of business, unless mandatory law provides otherwise.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">8. Changes</h2>
            <p>We may update these Terms. Material changes will be announced in-app or by email when practicable. Continued use after the effective date constitutes acceptance.</p>
          </section>
        @else
          <section class="legal-section" style="line-height:1.7;">
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">1. 本サービスについて</h2>
            <p>本サービス（{{ config('app.name') }}）は、招待制を基本とする個人向けの生活・生産性アプリです。発行されたアカウントは本人のみが利用し、ログイン情報を第三者に共有しないでください。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">2. アカウント・登録</h2>
            <p>登録には招待コードおよび本規約・プライバシーポリシーへの同意が必要な場合があります。正確な情報を登録してください。法令上必要な場合、未成年者は保護者の同意のもとで利用してください。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">3. 禁止事項・利用者の責任</h2>
            <p>Todo・メモ・写真・入出金など登録データの管理責任は原則として利用者にあります。法令違反、第三者の権利侵害、APIの濫用、他利用者データへの不正アクセスを禁止します。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">4. 外部連携と試作機能</h2>
            <p>ストレージ・翻訳・AI・地図・メッセージ等の外部APIを利用します。利用上限・障害・課金制約により機能が制限されることがあります。鮮明化や一部のAI支援は指定ロール向けの試作であり、予告なく変更・停止することがあります。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">5. データエクスポートと退会</h2>
            <p>マイページからアカウントデータのエクスポート（主にメタデータ。写真・音楽のバイナリは含まれない場合があります）および、パスワード確認後の退会が可能です。退会時は関連データの削除に努めますが、バックアップ等に合理的期間残存する場合があります。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">6. 免責</h2>
            <p>本サービスは現状有姿で提供されます。法令で認められる範囲で、間接損害、誤用によるデータ損失、外部サービス障害について運営者は責任を負いません。強行法規に基づく消費者保護は排除しません。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">7. 準拠法・管轄</h2>
            <p>本規約は日本法に準拠します。紛争については、運営者の主たる事業所所在地を管轄する裁判所を第一審の専属的合意管轄とします（強行法規がある場合を除く）。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">8. 改定</h2>
            <p>本規約は必要に応じて改定します。重要な変更は、可能な範囲でアプリ内またはメールでお知らせします。改定後の利用をもって同意したものとみなします。</p>
          </section>
        @endif

        <div class="auth-links" style="margin-top:1.5rem;">
          <a href="/privacy">{{ __('プライバシーポリシー') }}</a>
          <a href="/register">{{ __('登録へ') }}</a>
          <a href="/login">{{ __('ログインへ') }}</a>
        </div>
      </div>
    </main>
    @include('legal.partials.mark-read', ['legalReadKey' => 'sa2_legal_terms_read'])
  </body>
</html>
