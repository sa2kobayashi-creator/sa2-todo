@component('emails.layout', ['title' => __('利用申請が承認されました')])
  <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
    {{ __(':nameさん、利用申請（:plan）を承認しました。下のリンクからパスワードを設定すると、すぐにご利用いただけます。', ['name' => $displayName, 'plan' => $planLabel]) }}
  </p>
  @if($isStandard)
    <p style="margin:0 0 16px;font-size:14px;line-height:1.7;">
      {{ __('スタンダードは、パスワード設定のあとカード登録へ進みます。最初の14日間は無料です。') }}
    </p>
  @endif
  @if($isTenant)
    <p style="margin:0 0 16px;font-size:14px;line-height:1.7;">
      {{ __('テナント契約は、パスワード設定後にライトとしてログインできます。環境の準備は運営が進め、別途ご案内します。') }}
    </p>
  @endif
  <p style="margin:0 0 16px;">
    <a href="{{ $activateUrl }}" style="display:inline-block;padding:12px 18px;background:#1a73e8;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">
      {{ __('パスワードを設定して利用開始') }}
    </a>
  </p>
  <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.6;">
    {{ __('リンクの有効期限: :date', ['date' => $expiresAt]) }}
  </p>
@endcomponent
