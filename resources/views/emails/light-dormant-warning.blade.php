@component('emails.layout', ['title' => __('お試しアカウントの確認')])
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ __(':nameさん、ライト（お試し）アカウントに約:days日間ログインがありません。', ['name' => $displayName, 'days' => $inactiveDays]) }}
  </p>
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ __('引き続き使う場合は、:date までにログインしてください。期限までにログインがない場合、アカウントとデータは削除されます。', ['date' => $deleteAfterDate]) }}
  </p>
  <p style="margin:0 0 16px;">
    <a href="{{ $loginUrl }}" style="display:inline-block;padding:12px 18px;background:#1a73e8;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">
      {{ __('ログインする') }}
    </a>
  </p>
  <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.6;">
    {{ __('本格利用にはスタンダードプランがあります。ログイン後、マイページからお申し込みできます。') }}
  </p>
@endcomponent
