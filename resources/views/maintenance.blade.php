<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="robots" content="noindex" />
    <title>{{ __('メンテナンス中です') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body class="auth-body">
    @include('partials.auth-lang-switcher')
    <main class="auth-shell">
      <a href="/" class="auth-brand">
        <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="40" height="40" />
        <span>{{ config('app.name') }}</span>
      </a>
      <div class="auth-card panel maintenance-card">
        <h1>{{ __('メンテナンス中です') }}</h1>
        <p>{{ $message }}</p>
        <p class="hint">{{ __('運営者のみログイン・操作できます。') }}</p>
        <div class="storage-form-actions">
          <a class="button-link" href="/login">{{ __('ログイン（運営者）') }}</a>
        </div>
      </div>
    </main>
  </body>
</html>
