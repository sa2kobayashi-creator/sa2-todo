<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>{{ __('会員登録') }} - Sa2 ToDo</title>
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
      <a href="/" class="auth-brand">Sa2 ToDo</a>
      <div class="auth-card panel">
        <h1>{{ __('会員登録') }}</h1>
        @if(session('error'))<div class="banner error">{{ session('error') }}</div>@endif
        <form method="post" action="/register" class="auth-form">
          @csrf
          <label>{{ __('メールアドレス') }}<input type="email" name="email" value="{{ old('email') }}" required /></label>
          <label>{{ __('表示名') }}<input type="text" name="displayName" value="{{ old('displayName') }}" /></label>
          <label>{{ __('パスワード') }}<input type="password" name="password" required minlength="8" /></label>
          <label>{{ __('パスワード（確認）') }}<input type="password" name="password_confirmation" required minlength="8" /></label>
          <button type="submit" class="auth-submit">{{ __('登録') }}</button>
        </form>
        <div class="auth-links"><a href="/login">{{ __('ログインへ') }}</a></div>
      </div>
    </main>
  </body>
</html>
