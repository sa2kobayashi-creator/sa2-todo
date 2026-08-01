@component('emails.layout', ['title' => __('メールアドレスが変更されました')])
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ __(':nameさんのアカウントのメールアドレスが変更されました。', ['name' => $displayName]) }}
  </p>
  <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('変更前') }}</p>
  <p style="margin:0 0 16px;font-size:15px;">{{ $previousEmail }}</p>
  <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('変更後') }}</p>
  <p style="margin:0 0 16px;font-size:15px;font-weight:600;">{{ $newEmail }}</p>
  <p style="margin:0;font-size:14px;line-height:1.7;">
    {{ __('心当たりがない場合は、すぐに管理者にご連絡ください。') }}
  </p>
@endcomponent
