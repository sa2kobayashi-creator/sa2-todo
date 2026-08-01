<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('パスワードの設定') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="auth-body">
    @include('partials.auth-lang-switcher')
    <main class="auth-shell">
      <a href="/" class="auth-brand">
        <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="40" height="40" />
        <span>{{ config('app.name') }}</span>
      </a>
      <div class="auth-card panel">
        <h1>{{ __('パスワードの設定') }}</h1>
        @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
        @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
        @include('partials.form-errors')
        <p class="hint">{{ __('初回ログインありがとうございます。:emailのパスワードを設定してください。', ['email' => $user->email]) }}</p>
        <form method="post" action="/password/setup" class="auth-form">
          @csrf
          <label>
            {{ __('新しいパスワード') }}
            <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus />
          </label>
          <label>
            {{ __('新しいパスワード（確認）') }}
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" />
          </label>
          <button type="submit" class="auth-submit">{{ __('この内容で設定する') }}</button>
        </form>
        <div class="auth-links">
          <form method="post" action="/logout">
            @csrf
            <button type="submit" class="auth-link-button">{{ __('ログアウト') }}</button>
          </form>
        </div>
      </div>
    </main>
    @include('partials.csrf-keepalive')
  </body>
</html>
