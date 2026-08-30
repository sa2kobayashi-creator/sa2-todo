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
          <p class="hint">{{ __('招待コードでの登録も、ライト（お試し・約:gbGB・週:cap人まで）です。利用目的の記入が必須で、一時メールは登録できません。', ['gb' => (int) ($lightQuotaGb ?? 20), 'cap' => (int) ($lightWeeklyCap ?? 50)]) }}</p>
          <form method="post" action="/register" class="auth-form" id="register-form">
            @csrf
            <label>{{ __('メールアドレス') }}<input type="email" name="email" value="{{ old('email') }}" required autocomplete="username" /></label>
            <label>{{ __('表示名') }}<input type="text" name="displayName" value="{{ old('displayName') }}" maxlength="100" /></label>
            <label>{{ __('招待コード') }}<input type="text" name="inviteCode" value="{{ old('inviteCode') }}" required maxlength="120" autocomplete="off" /></label>
            <label>{{ __('利用目的') }} <span class="req">*</span>
              <textarea name="message" rows="3" maxlength="2000" required minlength="{{ (int) ($purposeMinLength ?? 20) }}" placeholder="{{ __('例: Todoと写真を試したい。家族の予定共有を検討中、など（:min文字以上）', ['min' => (int) ($purposeMinLength ?? 20)]) }}">{{ old('message') }}</textarea>
              <span class="hint">{{ __('空欄や極端に短い内容、一時メールは自動でお断りします。') }}</span>
            </label>
            @include('partials.agree-terms', [
              'submitButtonId' => 'register-submit',
              'submitLabel' => __('登録'),
              'readyHint' => __('内容を確認済みです。同意する場合はチェックを入れて登録してください。'),
            ])
          </form>
        @endif
        <div class="auth-links"><a href="/login">{{ __('ログインへ') }}</a></div>
      </div>
    </main>
    @include('partials.csrf-keepalive')
  </body>
</html>
