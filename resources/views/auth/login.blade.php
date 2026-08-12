<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('ログイン') }} - {{ config('app.name') }}</title>
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
        <h1>{{ __('ログイン') }}</h1>
        @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
        @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
        @if(session('notice'))<div class="banner notice">{{ session('notice') }}</div>@endif
        @if(session('error'))<div class="banner error">{{ session('error') }}</div>@endif
        @include('partials.form-errors')
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
          @if(!empty($registrationOpen))
            <a href="/register">{{ __('会員登録') }}</a>
          @endif
          <a href="/password/forgot">{{ __('パスワードをお忘れですか？') }}</a>
        </div>
      </div>
    </main>
    @include('partials.csrf-keepalive')
  </body>
</html>
