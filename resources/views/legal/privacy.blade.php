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
        <ol class="legal-list" style="padding-left:1.25rem;line-height:1.7;">
          <li>{{ __('取得する情報: メールアドレス、表示名、アプリ内で作成したコンテンツ（Todo・メモ・写真等）、連携サービス（Googleカレンダー等）のトークン。') }}</li>
          <li>{{ __('利用目的: ログイン認証、機能提供、通知、障害対応、不正利用の防止。') }}</li>
          <li>{{ __('保管: データはユーザー単位で分離して保管します。外部クラウド（オブジェクトストレージ等）を利用する場合があります。') }}</li>
          <li>{{ __('第三者提供: 法令に基づく場合を除き、同意なく個人データを販売しません。処理に必要な外部サービス（翻訳・AI・ストレージ等）へ必要な範囲で送信することがあります。') }}</li>
          <li>{{ __('保持・削除: アカウント削除や管理者による削除時は、関連データの削除に努めます。バックアップ残存期間がある場合は合理的な期間後に消去します。') }}</li>
          <li>{{ __('お問い合わせ: 個人情報に関する開示・訂正・削除のご要望はアプリ管理者へご連絡ください。') }}</li>
        </ol>
        <div class="auth-links" style="margin-top:1.5rem;">
          <a href="/terms">{{ __('利用規約') }}</a>
          <a href="/register">{{ __('登録へ') }}</a>
          <a href="/login">{{ __('ログインへ') }}</a>
        </div>
      </div>
    </main>
  </body>
</html>
