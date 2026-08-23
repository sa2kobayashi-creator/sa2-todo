@component('emails.layout', ['title' => __('お問い合わせ')])
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ __('アプリから運営者へのお問い合わせです。') }}
  </p>
  <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('送信者') }}</p>
  <p style="margin:0 0 16px;font-size:15px;">{{ $senderName }} &lt;{{ $senderEmail }}&gt;</p>
  <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('件名') }}</p>
  <p style="margin:0 0 16px;font-size:15px;font-weight:600;">{{ $subjectLine }}</p>
  <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('内容') }}</p>
  <p style="margin:0;font-size:15px;line-height:1.7;white-space:pre-wrap;">{{ $bodyText }}</p>
@endcomponent
