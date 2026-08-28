@component('emails.layout', ['title' => __('新しい利用申請')])
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ __('TOP から新しい利用申請がありました。') }}
  </p>
  <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('希望プラン') }}</p>
  <p style="margin:0 0 12px;font-size:15px;font-weight:600;">{{ $planLabel }}</p>
  <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('表示名') }}</p>
  <p style="margin:0 0 12px;font-size:15px;">{{ $displayName }}</p>
  <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('メールアドレス') }}</p>
  <p style="margin:0 0 12px;font-size:15px;">{{ $email }}</p>
  @if($organizationName !== '')
    <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('組織・家族名') }}</p>
    <p style="margin:0 0 12px;font-size:15px;">{{ $organizationName }}</p>
  @endif
  @if($phone !== '')
    <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('電話番号') }}</p>
    <p style="margin:0 0 12px;font-size:15px;">{{ $phone }}</p>
  @endif
  @if($message !== '')
    <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('メッセージ') }}</p>
    <p style="margin:0 0 16px;font-size:15px;white-space:pre-wrap;">{{ $message }}</p>
  @endif
  <p style="margin:0;font-size:14px;">
    <a href="{{ $adminUrl }}" style="color:#1a73e8;">{{ __('申請一覧を開く') }}</a>
  </p>
@endcomponent
