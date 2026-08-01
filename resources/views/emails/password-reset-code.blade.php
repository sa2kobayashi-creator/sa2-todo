@component('emails.layout', ['title' => __('パスワード再設定の確認コード')])
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ __(':nameさん、パスワード再設定の確認コードをお送りします。', ['name' => $displayName]) }}
  </p>
  <p style="margin:0 0 8px;font-size:14px;color:#6b7280;">{{ __('確認コード') }}</p>
  <p style="margin:0 0 16px;font-size:32px;font-weight:700;letter-spacing:8px;font-family:monospace;">{{ $code }}</p>
  <p style="margin:0 0 8px;font-size:14px;line-height:1.7;">
    {{ __('このコードは:minutes分間だけ有効です。再設定画面に入力して、新しいパスワードを設定してください。', ['minutes' => $expiresInMinutes]) }}
  </p>
@endcomponent
