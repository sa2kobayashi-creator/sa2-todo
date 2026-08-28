@component('emails.layout', ['title' => __('利用申請の結果')])
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ __(':nameさん、ご申請いただいた :plan について、今回はご利用をお受けできませんでした。', ['name' => $displayName, 'plan' => $planLabel]) }}
  </p>
  @if(trim($adminNote) !== '')
    <p style="margin:0 0 4px;font-size:14px;color:#6b7280;">{{ __('運営からのメッセージ') }}</p>
    <p style="margin:0 0 16px;font-size:15px;white-space:pre-wrap;">{{ $adminNote }}</p>
  @endif
  <p style="margin:0;font-size:14px;line-height:1.7;">
    {{ __('ご不明点があれば、サイトのお問い合わせからご連絡ください。') }}
  </p>
@endcomponent
