@component('emails.layout', ['title' => __('会員登録が完了しました（初期パスワードのお知らせ）')])
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ __(':nameさん、会員登録ありがとうございます。下記の初期パスワードでログインしてください。', ['name' => $displayName]) }}
  </p>
  <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('メールアドレス') }}</p>
  <p style="margin:0 0 16px;font-size:15px;font-weight:600;">{{ $email }}</p>
  <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('初期パスワード') }}</p>
  <p style="margin:0 0 16px;font-size:22px;font-weight:700;letter-spacing:2px;font-family:monospace;">{{ $initialPassword }}</p>
  <p style="margin:0 0 16px;font-size:14px;line-height:1.7;">
    {{ __('初回ログイン後に、ご自身のパスワードを設定していただきます。') }}
  </p>
  <p style="margin:0;font-size:14px;">
    <a href="{{ $loginUrl }}" style="color:#1a73e8;">{{ __('ログイン画面を開く') }}</a>
  </p>
@endcomponent
