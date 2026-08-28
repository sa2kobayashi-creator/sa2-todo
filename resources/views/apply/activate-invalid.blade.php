<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>{{ __('登録リンクが無効です') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body class="auth-body">
    @include('partials.auth-lang-switcher')
    <main class="auth-shell">
      <a href="/" class="auth-brand">
        <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="40" height="40" />
        <span>{{ config('app.name') }}</span>
      </a>
      <div class="auth-card panel">
        <h1>{{ __('登録リンクが無効です') }}</h1>
        <p class="hint">{{ __('この登録リンクは無効か、期限が切れています。もう一度利用申請するか、運営へお問い合わせください。') }}</p>
        <div class="auth-links">
          <a href="/apply">{{ __('利用申請へ') }}</a>
          <a href="/">{{ __('トップへ') }}</a>
        </div>
      </div>
    </main>
  </body>
</html>
