<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('パスワードの再設定') }} - {{ config('app.name') }}</title>
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
        <h1>{{ __('パスワードの再設定') }}</h1>
        @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
        @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
        @include('partials.form-errors')
        <p class="hint">{{ __('メールに届いた6桁の確認コードと、新しいパスワードを入力してください。コードの有効期限は:minutes分です。', ['minutes' => $ttlMinutes]) }}</p>
        <form method="post" action="/password/reset" class="auth-form">
          @csrf
          <label>
            {{ __('メールアドレス') }}
            <input type="email" name="email" value="{{ old('email', $email ?? '') }}" required autocomplete="username" />
          </label>
          <label>
            {{ __('確認コード（6桁）') }}
            <input
              type="text"
              name="code"
              value="{{ old('code') }}"
              required
              inputmode="numeric"
              pattern="[0-9]*"
              maxlength="6"
              autocomplete="one-time-code"
              class="auth-code-input"
              placeholder="123456"
            />
          </label>
          <label>
            {{ __('新しいパスワード') }}
            <input type="password" name="password" required minlength="8" autocomplete="new-password" />
          </label>
          <label>
            {{ __('新しいパスワード（確認）') }}
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" />
          </label>
          <button type="submit" class="auth-submit">{{ __('パスワードを変更') }}</button>
        </form>
        <div class="auth-links">
          <a href="/password/forgot?email={{ urlencode(old('email', $email ?? '')) }}">{{ __('コードを再送信') }}</a>
          <a href="/login">{{ __('ログインへ') }}</a>
        </div>
      </div>
    </main>
    @include('partials.csrf-keepalive')
  </body>
</html>
