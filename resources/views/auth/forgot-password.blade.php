<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('パスワードをお忘れですか？') }} - {{ config('app.name') }}</title>
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
        <p class="hint">{{ __('登録済みのメールアドレスを入力してください。6桁の確認コードをお送りします。') }}</p>
        <form method="post" action="/password/forgot" class="auth-form">
          @csrf
          <label>
            {{ __('メールアドレス') }}
            <input type="email" name="email" value="{{ old('email', $email ?? '') }}" required autocomplete="username" />
          </label>
          <button type="submit" class="auth-submit">{{ __('確認コードを送信') }}</button>
        </form>
        <div class="auth-links">
          <a href="/login">{{ __('ログインへ') }}</a>
          <a href="/password/reset">{{ __('コードを持っている') }}</a>
        </div>
      </div>
    </main>
    @include('partials.csrf-keepalive')
  </body>
</html>
