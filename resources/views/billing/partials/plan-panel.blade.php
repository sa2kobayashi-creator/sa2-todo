{{-- プラン・お支払い本体（/mypage/plan とマイページで共有） --}}
<dl class="legal-dl" style="margin-bottom:1rem;">
  <dt>{{ __('ご契約の状態') }}</dt>
  <dd>
    {{ $statusLabel }}
    @if($trialEndsAt)
      <span class="hint">（{{ __('お試し期限: :date', ['date' => $trialEndsAt]) }}）</span>
    @endif
  </dd>
  <dt>{{ __('オプション') }}</dt>
  <dd>
    @if($mailboxAddonActive || $storageOverageActive)
      <ul class="legal-list">
        @if($mailboxAddonActive)<li>{{ __('@sa2-plus.com メールボックス') }}</li>@endif
        @if($storageOverageActive)<li>{{ __('ストレージ超過分のお支払い') }}</li>@endif
      </ul>
    @else
      <span class="hint">{{ __('なし') }}</span>
    @endif
  </dd>
</dl>

@if(!$billingEnabled)
  <p class="hint">{{ __('オンラインでのカード申し込みは準備中です。料金は次のとおりです。') }}</p>
  <ul class="legal-list" style="margin-bottom:1rem;">
    <li>{{ __('スタンダード（月額）') }}: ¥{{ number_format((int) ($standardMonthlyYen ?? 980)) }}（{{ $pricesIncludeTax ? __('税込') : __('税別') }}）</li>
    <li>{{ __('スタンダード（年額）') }}: ¥{{ number_format((int) ($standardYearlyYen ?? 9800)) }}（{{ $pricesIncludeTax ? __('税込') : __('税別') }}）</li>
    <li>{{ __('お試し') }}: {{ __('申し込み後 :days日間は無料', ['days' => (int) ($standardTrialDays ?? 14)]) }}</li>
  </ul>
  <p class="hint">{{ __('ご希望の方はお問い合わせからご連絡ください。運営が契約を用意します。') }}</p>
  <p><a href="/contact" class="button-link">{{ __('お問い合わせ') }}</a></p>
@elseif($hasActiveSubscription)
  <p class="hint">{{ __('プランの変更・お支払い方法の更新・解約は、決済画面（Stripe）で行えます。') }}</p>
  <form method="post" action="/mypage/plan/portal">
    @csrf
    <button type="submit">{{ __('契約内容を変更') }}</button>
  </form>
@else
  <p class="hint">{{ __('お支払いは Stripe の決済画面で行います。カード番号を当サービスが受け取ることはありません。') }}</p>
  <div class="billing-plan-list">
    @foreach($plans as $key => $plan)
      <form method="post" action="/mypage/plan/checkout" class="billing-plan-card">
        @csrf
        <input type="hidden" name="plan" value="{{ $key }}" />
        <h2>{{ __($plan['label']) }}</h2>
        <p class="billing-plan-price">
          {{ __('¥:yen（:tax）', ['yen' => number_format((int) $plan['yen']), 'tax' => $pricesIncludeTax ? __('税込') : __('税別')]) }}
        </p>
        @if((int) ($plan['trial_days'] ?? 0) > 0)
          <p class="hint">{{ __('最初の:days日間は無料', ['days' => (int) $plan['trial_days']]) }}</p>
        @endif
        <button type="submit">{{ __('このプランを申し込む') }}</button>
      </form>
    @endforeach
  </div>
  @if($hasStripeCustomer)
    <form method="post" action="/mypage/plan/portal" style="margin-top:1rem;">
      @csrf
      <button type="submit" class="secondary">{{ __('過去の請求を見る') }}</button>
    </form>
  @endif
@endif

<p class="hint" style="margin-top:1.25rem;">
  {!! __('料金と解約の条件は :tokushoho と :terms をご確認ください。', [
    'tokushoho' => '<a href="/tokushoho">'.e(__('特定商取引法に基づく表記')).'</a>',
    'terms' => '<a href="/terms">'.e(__('利用規約')).'</a>',
  ]) !!}
</p>
