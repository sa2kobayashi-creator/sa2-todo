<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('会員登録') }} - {{ config('app.name') }}</title>
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
        <h1>{{ __('会員登録') }}</h1>
        @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
        @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
        @if(session('error'))<div class="banner error">{{ session('error') }}</div>@endif
        @include('partials.form-errors')
        @if(empty($registrationOpen))
          <p class="hint">{{ __('現在、新規登録は受け付けていません。管理者に依頼してください。') }}</p>
        @else
          <p class="hint">{{ __('新規登録は招待制です。管理者から招待コードを受け取ってください。登録するとライト権限で開始し、初期パスワードをメールでお送りします。') }}</p>
          <form method="post" action="/register" class="auth-form">
            @csrf
            <label>{{ __('メールアドレス') }}<input type="email" name="email" value="{{ old('email') }}" required autocomplete="username" /></label>
            <label>{{ __('表示名') }}<input type="text" name="displayName" value="{{ old('displayName') }}" maxlength="100" /></label>
            <label>{{ __('招待コード') }}<input type="text" name="inviteCode" value="{{ old('inviteCode') }}" required maxlength="120" autocomplete="off" /></label>
            <label class="checkbox-inline" style="display:flex;align-items:flex-start;gap:8px;margin:12px 0;">
              <input type="checkbox" name="agreeTerms" value="1" @checked(old('agreeTerms')) required style="margin-top:4px;" />
              <span>
                {!! __(':terms と :privacy に同意します。', [
                  'terms' => '<a href="/terms" target="_blank" rel="noopener">'.e(__('利用規約')).'</a>',
                  'privacy' => '<a href="/privacy" target="_blank" rel="noopener">'.e(__('プライバシーポリシー')).'</a>',
                ]) !!}
              </span>
            </label>
            <button type="submit" class="auth-submit">{{ __('登録') }}</button>
          </form>
        @endif
        <div class="auth-links"><a href="/login">{{ __('ログインへ') }}</a></div>
      </div>
    </main>
    @include('partials.csrf-keepalive')
  </body>
</html>
