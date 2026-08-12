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
        <ol class="legal-list" style="padding-left:1.25rem;line-height:1.7;">
          <li>{{ __('本サービスは招待制の個人向けアプリです。アカウントは本人のみが利用してください。') }}</li>
          <li>{{ __('写真・メモ・予定など登録データは、原則として各ユーザーの責任で管理します。違法・不当な内容の投稿を禁止します。') }}</li>
          <li>{{ __('運営が用意する外部API（翻訳・ストレージ等）には利用上限や停止があり得ます。試作機能（鮮明化・一部AI）は予告なく変更・停止することがあります。') }}</li>
          <li>{{ __('退会やデータ削除の詳細はプライバシーポリシーに従います。ご要望は管理者へ連絡してください。') }}</li>
          <li>{{ __('本規約は必要に応じて改定します。重要な変更がある場合はアプリ内またはメールでお知らせします。') }}</li>
        </ol>
        <div class="auth-links" style="margin-top:1.5rem;">
          <a href="/privacy">{{ __('プライバシーポリシー') }}</a>
          <a href="/register">{{ __('登録へ') }}</a>
          <a href="/login">{{ __('ログインへ') }}</a>
        </div>
      </div>
    </main>
  </body>
</html>
