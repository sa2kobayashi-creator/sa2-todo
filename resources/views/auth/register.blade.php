<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>会員登録 - Sa2 ToDo</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="auth-body">
    <main class="auth-shell">
      <a href="/" class="auth-brand">Sa2 ToDo</a>
      <div class="auth-card panel">
        <h1>会員登録</h1>
        @if(session('error'))<div class="banner error">{{ session('error') }}</div>@endif
        <form method="post" action="/register" class="auth-form">
          @csrf
          <label>メールアドレス<input type="email" name="email" value="{{ old('email') }}" required /></label>
          <label>表示名<input type="text" name="displayName" value="{{ old('displayName') }}" /></label>
          <label>パスワード<input type="password" name="password" required minlength="8" /></label>
          <label>パスワード（確認）<input type="password" name="password_confirmation" required minlength="8" /></label>
          <button type="submit" class="auth-submit">登録</button>
        </form>
        <div class="auth-links"><a href="/login">ログインへ</a></div>
      </div>
    </main>
  </body>
</html>
