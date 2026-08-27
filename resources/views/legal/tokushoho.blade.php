<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>{{ __('特定商取引法に基づく表記') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body class="auth-body">
    @include('partials.auth-lang-switcher')
    <main class="auth-shell">
      <a href="/" class="auth-brand">
        <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="40" height="40" />
        <span>{{ config('app.name') }}</span>
      </a>
      <div class="auth-card panel" style="max-width:720px;text-align:left;">
        <h1>{{ __('特定商取引法に基づく表記') }}</h1>

        @php
          $taxLabel = $pricesIncludeTax ? __('税込') : __('税別');
          $unset = '<span class="legal-unset">'.e(__('未設定')).'</span>';
          $orUnset = fn (string $value) => trim($value) !== '' ? e(trim($value)) : $unset;
        @endphp

        @if(trim($operatorName) === '' || trim($address) === '' || trim($contactEmail) === '' || trim($phone) === '')
          <div class="banner error">
            {{ __('事業者情報が未設定です。有料販売を始める前に、設定 → 公開販売 で氏名・住所・電話・メールを保存してください。') }}
          </div>
        @endif

        <section class="legal-section" style="line-height:1.7;">
          <dl class="legal-dl">
            <dt>{{ __('販売事業者') }}</dt>
            <dd>
              {!! $orUnset($operatorName) !!}
              @if(trim($operatorTradeName) !== '')
                <span class="hint">（{{ __('屋号') }}: {{ $operatorTradeName }}）</span>
              @endif
            </dd>

            <dt>{{ __('運営統括責任者') }}</dt>
            <dd>{!! $orUnset($operatorManager !== '' ? $operatorManager : $operatorName) !!}</dd>

            <dt>{{ __('所在地') }}</dt>
            <dd>{!! $orUnset($address) !!}</dd>

            <dt>{{ __('電話番号') }}</dt>
            <dd>
              {!! $orUnset($phone) !!}
              @if(trim($phoneHours) !== '')
                <span class="hint">（{{ $phoneHours }}）</span>
              @endif
            </dd>

            <dt>{{ __('メールアドレス') }}</dt>
            <dd>
              @if(trim($contactEmail) !== '')
                <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
              @else
                {!! $unset !!}
              @endif
            </dd>

            @if(trim($invoiceNumber) !== '')
              <dt>{{ __('適格請求書発行事業者登録番号') }}</dt>
              <dd>{{ $invoiceNumber }}</dd>
            @endif

            <dt>{{ __('販売価格') }}</dt>
            <dd>
              <ul class="legal-list">
                <li>{{ __('ライト: ¥0（招待制）') }}</li>
                <li>{{ __('スタンダード: ¥:monthly／月、¥:yearly／年（:tax）', ['monthly' => number_format($standardMonthlyYen), 'yearly' => number_format($standardYearlyYen), 'tax' => $taxLabel]) }}</li>
                <li>{{ __('テナント契約: ¥:monthly／月、¥:yearly／年（:tax）', ['monthly' => number_format($tenantMonthlyYen), 'yearly' => number_format($tenantYearlyYen), 'tax' => $taxLabel]) }}</li>
                <li>{{ __('@sa2-plus.com メールボックス: ¥:yen／月（:tax）', ['yen' => number_format($mailboxYenMonthly), 'tax' => $taxLabel]) }}</li>
                <li>{{ __('専用インスタンス: 初期 ¥:setup〜、保守 ¥:monthly／月〜（:tax・個別お見積）', ['setup' => number_format($setupFeeYen), 'monthly' => number_format($monthlyBaseYen), 'tax' => $taxLabel]) }}</li>
              </ul>
            </dd>

            <dt>{{ __('商品代金以外の必要料金') }}</dt>
            <dd>
              <ul class="legal-list">
                <li>{{ __('インターネット接続料金・通信料金はお客様のご負担となります。') }}</li>
                <li>{{ __('ストレージ超過分: ¥:yen／100GB／月（:tax）。超過課金を利用しない設定の場合は、超過時点で新規アップロードを停止します。', ['yen' => number_format($storageOverageYen), 'tax' => $taxLabel]) }}</li>
              </ul>
            </dd>

            <dt>{{ __('お支払い方法') }}</dt>
            <dd>{{ __('クレジットカード（Stripe による決済）。専用インスタンスは銀行振込によるご請求にも対応します。') }}</dd>

            <dt>{{ __('お支払い時期') }}</dt>
            <dd>{{ __('お申し込み時に初回分をお支払いいただき、以後は各更新日に自動で決済します。無料お試し期間がある場合は、期間終了日に初回決済を行います。') }}</dd>

            <dt>{{ __('サービスの提供時期') }}</dt>
            <dd>{{ __('決済完了の確認後、ただちにご利用いただけます（通常は数分以内）。専用インスタンスは個別にご案内する構築完了日からとなります。') }}</dd>

            <dt>{{ __('無料お試し') }}</dt>
            <dd>{{ __('スタンダードは:standard日間、テナント契約は:tenant日間の無料期間があります。期間内に解約すればご請求は発生しません。', ['standard' => $standardTrialDays, 'tenant' => $tenantTrialDays]) }}</dd>

            <dt>{{ __('解約・返品・返金について') }}</dt>
            <dd>
              <ul class="legal-list">
                <li>{{ __('解約はいつでもお申し込みいただけます。解約後も、お支払い済みの期間の末日まではご利用いただけます。') }}</li>
                <li>{{ __('デジタルサービスの性質上、お支払い済みの期間について日割りでの返金は行いません。') }}</li>
                <li>{{ __('通信販売にはクーリング・オフ制度の適用はありません。') }}</li>
                <li>{{ __('当方に責めのある事由でサービスをご提供できなかった場合は、個別に対応いたします。') }}</li>
              </ul>
            </dd>

            <dt>{{ __('動作環境') }}</dt>
            <dd>{{ __('最新版の Google Chrome / Microsoft Edge / Safari / Firefox。JavaScript と Cookie を有効にしてください。スマートフォンは iOS・Android の最新版に対応しています。') }}</dd>
          </dl>
        </section>

        <div class="auth-links" style="margin-top:1.5rem;">
          <a href="/terms">{{ __('利用規約') }}</a>
          <a href="/privacy">{{ __('プライバシーポリシー') }}</a>
          <a href="/">{{ __('トップへ') }}</a>
        </div>
      </div>
    </main>
  </body>
</html>
