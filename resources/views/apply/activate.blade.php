<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('パスワード設定') }} - {{ config('app.name') }}</title>
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
        <h1>{{ __('パスワードを設定') }}</h1>
        @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
        @include('partials.form-errors')

        <p class="hint">
          {{ __(':nameさん（:email）のアカウントを作成します。希望プラン: :plan', [
            'name' => $application->display_name,
            'email' => $application->email,
            'plan' => __($application->plan->label()),
          ]) }}
        </p>

        <form method="post" action="/apply/activate/{{ $token }}" class="auth-form">
          @csrf
          <label>{{ __('新しいパスワード') }}
            <input type="password" name="password" required minlength="8" autocomplete="new-password" />
          </label>
          <label>{{ __('新しいパスワード（確認）') }}
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" />
          </label>
          <button type="submit" class="auth-submit">{{ __('設定して利用開始') }}</button>
        </form>
      </div>
    </main>
  </body>
</html>
