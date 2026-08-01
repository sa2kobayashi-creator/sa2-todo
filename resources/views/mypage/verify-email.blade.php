<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('メールアドレスの確認') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'mypage'])
    <main class="page-main page-main-narrow">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
      @include('partials.form-errors')

      <div class="panel">
        <h2>{{ __('メールアドレスの確認') }}</h2>
        <p class="hint">
          {{ __(':emailに6桁の確認コードを送りました。コードを入力すると、このアドレスがログインIDになります。', ['email' => $pendingEmail]) }}
        </p>
        <p class="hint">
          {{ __('コードの有効期限は:minutes分です。確認が終わるまで、ログインには現在のアドレス:emailを使ってください。', ['minutes' => $ttlMinutes, 'email' => $currentEmail]) }}
        </p>

        <form method="post" action="/mypage/email/verify" class="stack-form">
          @csrf
          <label>{{ __('確認コード（6桁）') }}
            <input
              type="text"
              name="code"
              required
              inputmode="numeric"
              pattern="[0-9]*"
              maxlength="6"
              autocomplete="one-time-code"
              class="auth-code-input"
              placeholder="123456"
              autofocus
            />
          </label>
          @error('code')<p class="field-error">{{ $message }}</p>@enderror
          <button type="submit">{{ __('メールアドレスを変更') }}</button>
        </form>
      </div>

      <div class="panel">
        <h2>{{ __('コードが届かないとき') }}</h2>
        <p class="hint">{{ __('迷惑メールフォルダもご確認ください。届かない場合は再送信できます。') }}</p>
        <div class="mypage-email-actions">
          <form method="post" action="/mypage/email/resend">
            @csrf
            <button type="submit" class="secondary">{{ __('確認コードを再送信') }}</button>
          </form>
          <form method="post" action="/mypage/email/cancel">
            @csrf
            <button type="submit" class="secondary">{{ __('変更を取り消す') }}</button>
          </form>
        </div>
      </div>
    </main>
  </body>
</html>
