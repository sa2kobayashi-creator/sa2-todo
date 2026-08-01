@component('emails.layout', ['title' => __('メールアドレス変更の確認コード')])
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ __(':nameさん、メールアドレスを:emailに変更するための確認コードをお送りします。', ['name' => $displayName, 'email' => $newEmail]) }}
  </p>
  <p style="margin:0 0 8px;font-size:14px;color:#6b7280;">{{ __('確認コード') }}</p>
  <p style="margin:0 0 16px;font-size:32px;font-weight:700;letter-spacing:8px;font-family:monospace;">{{ $code }}</p>
  <p style="margin:0 0 8px;font-size:14px;line-height:1.7;">
    {{ __('このコードは:minutes分間だけ有効です。マイページの確認画面に入力すると、新しいメールアドレスが有効になります。', ['minutes' => $expiresInMinutes]) }}
  </p>
@endcomponent
