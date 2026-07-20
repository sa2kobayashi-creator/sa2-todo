<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <title>{{ __('ログイン') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="auth-body">
    <div class="lang-switcher auth-lang-switcher" role="group" aria-label="{{ __('言語') }}">
      <form method="post" action="{{ route('locale.update') }}" class="lang-switch-form">
        @csrf
        <input type="hidden" name="locale" value="ja" />
        <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}" />
        <button type="submit" class="lang-switch-btn{{ ($appLocale ?? app()->getLocale()) === 'ja' ? ' is-active' : '' }}">日本語</button>
      </form>
      <form method="post" action="{{ route('locale.update') }}" class="lang-switch-form">
        @csrf
        <input type="hidden" name="locale" value="en" />
        <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}" />
        <button type="submit" class="lang-switch-btn{{ ($appLocale ?? app()->getLocale()) === 'en' ? ' is-active' : '' }}">English</button>
      </form>
    </div>
    <main class="auth-shell">
      <a href="/" class="auth-brand">
        <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="40" height="40" />
        <span>{{ config('app.name') }}</span>
      </a>
      <div class="auth-card panel">
        <h1>{{ __('ログイン') }}</h1>
        @if(session('notice'))<div class="banner notice">{{ session('notice') }}</div>@endif
        @if(session('error'))<div class="banner error">{{ session('error') }}</div>@endif
        <form method="post" action="/login" class="auth-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <label>
            {{ __('メールアドレス') }}
            <input type="email" name="email" value="{{ old('email', $email ?? '') }}" required autocomplete="username" />
          </label>
          <label>
            {{ __('パスワード') }}
            <input type="password" name="password" required autocomplete="current-password" />
          </label>
          <button type="submit" class="auth-submit">{{ __('ログイン') }}</button>
        </form>
        <div class="auth-links">
          <a href="/register">{{ __('会員登録') }}</a>
        </div>
      </div>
    </main>
  </body>
</html>
