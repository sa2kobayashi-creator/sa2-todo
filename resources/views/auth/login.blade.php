<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <title>ログイン - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="auth-body">
    <main class="auth-shell">
      <a href="/" class="auth-brand">Sa2 ToDo</a>
      <div class="auth-card panel">
        <h1>ログイン</h1>
        @if(session('notice'))<div class="banner notice">{{ session('notice') }}</div>@endif
        @if(session('error'))<div class="banner error">{{ session('error') }}</div>@endif
        <form method="post" action="/login" class="auth-form">
          @csrf
          <input type="hidden" name="returnTo" value="{{ $returnTo }}" />
          <label>
            メールアドレス
            <input type="email" name="email" value="{{ old('email', $email ?? '') }}" required autocomplete="username" />
          </label>
          <label>
            パスワード
            <input type="password" name="password" required autocomplete="current-password" />
          </label>
          <button type="submit" class="auth-submit">ログイン</button>
        </form>
        <div class="auth-links">
          <a href="/register">会員登録</a>
        </div>
      </div>
    </main>
  </body>
</html>
