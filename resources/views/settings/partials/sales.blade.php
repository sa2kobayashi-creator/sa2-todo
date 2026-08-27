{{-- 運営者専用。特商法の事業者情報と Stripe。テナント管理者には出さない --}}
@php
  $legal = $legalSettings ?? [];
  $stripe = $stripeSettings ?? [];
  $runtime = $serverRuntime ?? [];
  $prices = $stripe['prices'] ?? [];
@endphp

<div class="panel" id="sales-settings">
  <h2>{{ __('公開販売') }}</h2>
  <p class="hint">{{ __('有料プランを受け付けるための画面です。.env を直接編集しなくても、ここに入力すれば特商法表記と決済が動きます。') }}</p>

  <ol class="line-setup-steps sales-setup-steps">
    <li>{{ __('下の「事業者情報」に氏名・住所・電話・メールを入れて保存します。これが /tokushoho（特定商取引法に基づく表記）に出ます。') }}</li>
    <li>{{ __('Stripe ダッシュボードで商品と価格を作ります。通貨は日本円（JPY）。980 と入れると 980円です（9.80円にはなりません）。') }}</li>
    <li>{{ __('公開可能キー（pk_）とシークレットキー（sk_）をコピーして、この画面の Stripe 欄に貼ります。') }}</li>
    <li>{{ __('Developers → Webhooks でエンドポイントを追加します。URL は下に表示されているものをそのまま使います。') }}</li>
    <li>{{ __('購読するイベントは checkout.session.completed、customer.subscription.created / updated / deleted、invoice.paid、invoice.payment_failed です。') }}</li>
    <li>{{ __('作った Webhook の Signing secret（whsec_）と、各プランの Price ID（price_）を貼ります。') }}</li>
    <li>{{ __('接続テストが通ったら、最後に「オンライン申し込みを開始する」にチェックして保存します。オフの間は申し込みボタンは出ません。') }}</li>
  </ol>
</div>

<div class="panel storage-settings" id="legal-operator-settings">
  <h3>{{ __('事業者情報（特定商取引法）') }}</h3>
  <p class="hint">{{ __('個人事業主は屋号ではなく本名を「販売事業者」に書いてください。未設定の項目は特商法ページに「未設定」と赤字で出ます。') }}</p>
  @if(empty($legal['required_complete']))
    <div class="banner error">{{ __('必須項目（氏名・住所・電話・メール）が足りません。有料販売の前に埋めてください。') }}</div>
  @else
    <p class="hint storage-test-result is-ok">{{ __('必須項目は揃っています。') }} <a href="/tokushoho" target="_blank" rel="noopener">{{ __('特商法表記を開く') }}</a></p>
  @endif

  <form method="post" action="/settings/sales/legal" class="storage-provider-form">
    @csrf
    <label>
      {{ __('販売事業者（氏名）') }} <span class="req">*</span>
      <input type="text" name="operator_name" value="{{ $legal['operator_name'] ?? '' }}" maxlength="120" autocomplete="name" />
    </label>
    <label>
      {{ __('屋号（任意）') }}
      <input type="text" name="operator_trade_name" value="{{ $legal['operator_trade_name'] ?? '' }}" maxlength="120" />
    </label>
    <label>
      {{ __('運営統括責任者') }}
      <input type="text" name="operator_manager" value="{{ $legal['operator_manager'] ?? '' }}" maxlength="120" placeholder="{{ __('空なら販売事業者と同じ名前を出します') }}" />
    </label>
    <label>
      {{ __('所在地') }} <span class="req">*</span>
      <input type="text" name="address" value="{{ $legal['address'] ?? '' }}" maxlength="255" autocomplete="street-address" />
    </label>
    <label>
      {{ __('電話番号') }} <span class="req">*</span>
      <input type="text" name="phone" value="{{ $legal['phone'] ?? '' }}" maxlength="40" autocomplete="tel" />
    </label>
    <label>
      {{ __('電話の受付時間（任意）') }}
      <input type="text" name="phone_hours" value="{{ $legal['phone_hours'] ?? '' }}" maxlength="80" placeholder="{{ __('例: 平日 10:00–17:00') }}" />
    </label>
    <label>
      {{ __('問い合わせメール') }} <span class="req">*</span>
      <input type="email" name="contact_email" value="{{ $legal['contact_email'] ?? '' }}" maxlength="190" autocomplete="email" />
      <span class="hint">{{ __('未ログインの人がトップページから連絡できる唯一の窓口です。') }}</span>
    </label>
    <label>
      {{ __('個人情報の窓口メール（任意）') }}
      <input type="email" name="privacy_contact_email" value="{{ $legal['privacy_contact_email'] ?? '' }}" maxlength="190" />
      <span class="hint">{{ __('空なら問い合わせメールと同じ宛先をプライバシーポリシーに出します。') }}</span>
    </label>
    <label>
      {{ __('適格請求書発行事業者登録番号（任意）') }}
      <input type="text" name="invoice_registration_number" value="{{ $legal['invoice_registration_number'] ?? '' }}" maxlength="40" placeholder="T1234567890123" />
    </label>
    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('事業者情報を保存') }}</button>
    </div>
  </form>
</div>

<div class="panel storage-settings" id="stripe-billing-settings">
  <h3>{{ __('Stripe（クレジットカード決済）') }}</h3>
  <p class="hint">{{ __('カード番号は Stripe の画面が受け取ります。このアプリのサーバーには残りません。テスト用キー（pk_test_ / sk_test_）と本番用キーを混ぜないでください。') }}</p>

  @if(!empty($stripe['last_test_message']))
    <p class="hint storage-test-result {{ ($stripe['last_test_status'] ?? '') === 'ok' ? 'is-ok' : 'is-fail' }}">
      {{ $stripe['last_test_message'] }}
      @if(!empty($stripe['last_tested_at']))
        <span class="inline-hint">({{ $stripe['last_tested_at'] }})</span>
      @endif
    </p>
  @endif

  <form method="post" action="/settings/sales/stripe" class="storage-provider-form" id="stripe-billing-form">
    @csrf
    <label class="storage-enable">
      <input type="checkbox" name="enabled" value="1" @checked(!empty($stripe['enabled'])) />
      {{ __('オンライン申し込みを開始する') }}
    </label>
    <p class="hint">{{ __('オフの間はマイページのプラン画面に申し込みボタンが出ません。準備が終わるまでオフのままにしてください。') }}</p>

    <label>
      {{ __('公開可能キー') }}
      <input type="password" name="stripe_key" value="{{ $stripe['stripe_key_masked'] ?? '' }}" placeholder="pk_live_... または pk_test_..." autocomplete="off" />
      <span class="hint">{{ __('Stripe ダッシュボード → 開発者 → APIキー の「公開可能キー」。画面に出してもよい値です。') }}</span>
    </label>
    <label>
      {{ __('シークレットキー') }}
      <input type="password" name="stripe_secret" value="{{ $stripe['stripe_secret_masked'] ?? '' }}" placeholder="sk_live_... または sk_test_..." autocomplete="off" />
      <span class="hint">{{ __('同じ画面の「シークレットキー」。絶対に利用者へ見せないでください。保存後は •••• とだけ表示します。') }}</span>
    </label>
    <label>
      {{ __('Webhook 署名シークレット') }}
      <input type="password" name="webhook_secret" value="{{ $stripe['webhook_secret_masked'] ?? '' }}" placeholder="whsec_..." autocomplete="off" />
      <span class="hint">{{ __('Webhook を作ったあと「Signing secret」として出ます。これがないと決済完了を受け取れません。') }}</span>
    </label>
    <label>
      {{ __('Webhook URL（コピーして Stripe に貼る）') }}
      <input type="text" readonly value="{{ $stripe['webhook_url'] ?? url('/webhooks/stripe') }}" onclick="this.select()" />
    </label>
    <p class="hint">{{ __('Stripe ダッシュボード → Developers → Webhooks → Add endpoint。Listen to は次のイベントです。') }}</p>
    <ul class="legal-list">
      @foreach(($stripe['webhook_events'] ?? []) as $eventName)
        <li><code>{{ $eventName }}</code></li>
      @endforeach
    </ul>

    <h4 class="ai-settings-subtitle">{{ __('Price ID（Stripe が発行する price_ で始まる値）') }}</h4>
    <p class="hint">{{ __('商品を作ったあと「価格」の ID を貼ります。空のプランは申し込み候補に出ません。テナント契約は運営が作るので、ここにあっても利用者の画面には出ません。') }}</p>
    <label>
      {{ __('スタンダード（月額）') }}
      <input type="text" name="price_standard_monthly" value="{{ $prices['price_standard_monthly'] ?? '' }}" placeholder="price_..." autocomplete="off" />
    </label>
    <label>
      {{ __('スタンダード（年額）') }}
      <input type="text" name="price_standard_yearly" value="{{ $prices['price_standard_yearly'] ?? '' }}" placeholder="price_..." autocomplete="off" />
    </label>
    <label>
      {{ __('テナント契約（月額）') }}
      <input type="text" name="price_tenant_monthly" value="{{ $prices['price_tenant_monthly'] ?? '' }}" placeholder="price_..." autocomplete="off" />
    </label>
    <label>
      {{ __('テナント契約（年額）') }}
      <input type="text" name="price_tenant_yearly" value="{{ $prices['price_tenant_yearly'] ?? '' }}" placeholder="price_..." autocomplete="off" />
    </label>
    <label>
      {{ __('@sa2-plus.com メールボックス（月額）') }}
      <input type="text" name="price_mailbox_monthly" value="{{ $prices['price_mailbox_monthly'] ?? '' }}" placeholder="price_..." autocomplete="off" />
    </label>
    <label>
      {{ __('ストレージ超過（100GB 単位）') }}
      <input type="text" name="price_storage_overage" value="{{ $prices['price_storage_overage'] ?? '' }}" placeholder="price_..." autocomplete="off" />
    </label>

    <div class="storage-form-actions">
      <button type="submit" class="button-link">{{ __('Stripe 設定を保存') }}</button>
      <button type="button" class="secondary" id="stripe-billing-test-btn">{{ __('接続テスト') }}</button>
      <span class="storage-test-live hint" id="stripe-billing-test-live"></span>
    </div>
  </form>
</div>

<div class="panel" id="server-runtime-settings">
  <h3>{{ __('サーバー起動時の設定（この画面からは変えられません）') }}</h3>
  <p class="hint">{{ __('次の2つはアプリが起動する瞬間に読むため、データベースより先に決まります。変えるときは本番サーバーの .env を編集してから php artisan optimize:clear します。') }}</p>

  <dl class="legal-dl">
    <dt>TRUSTED_PROXIES</dt>
    <dd>
      <code>{{ $runtime['trusted_proxies'] ?? '*' }}</code>
      @if(!empty($runtime['trusted_proxies_ok']))
        <span class="hint storage-test-result is-ok">{{ __('制限されています（推奨）') }}</span>
      @else
        <span class="hint storage-test-result is-fail">{{ __('いまは「全部信頼」（*）です。ログイン回数制限を IP 偽装で迂回される余地があります。') }}</span>
      @endif
      <p class="hint">{{ __('ロリポップにブラウザが直接つながる構成なら、値を空にしてください。Cloudflare などの CDN を前に置くときだけ、その IP レンジをカンマ区切りで書きます。') }}</p>
    </dd>
    <dt>LOG_STACK / LOG_LEVEL</dt>
    <dd>
      <code>{{ ($runtime['log_channel'] ?? 'stack') }} / {{ ($runtime['log_stack'] ?? 'single') }} / {{ ($runtime['log_level'] ?? 'debug') }}</code>
      @if(!empty($runtime['log_ok']))
        <span class="hint storage-test-result is-ok">{{ __('日次ローテーションです（推奨）') }}</span>
      @else
        <span class="hint storage-test-result is-fail">{{ __('単一ファイルのままです。エラーが溜まるとディスクを圧迫します。') }}</span>
      @endif
      <p class="hint">{{ __('本番では LOG_STACK=daily と LOG_LEVEL=error を推奨します。daily は日付ごとにファイルを分け、古いものを自動で捨てます。') }}</p>
    </dd>
  </dl>
</div>

<script>
  (function () {
    const btn = document.getElementById('stripe-billing-test-btn')
    const live = document.getElementById('stripe-billing-test-live')
    if (!btn || !live) return
    btn.addEventListener('click', async () => {
      live.textContent = @json(__('確認中…'))
      live.className = 'storage-test-live hint'
      try {
        const res = await fetch('/settings/sales/stripe/test', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
          },
          credentials: 'same-origin',
        })
        const data = await res.json().catch(() => ({}))
        live.textContent = data.message || String(res.status)
        live.className = 'storage-test-live hint storage-test-result ' + (data.ok ? 'is-ok' : 'is-fail')
      } catch (err) {
        live.textContent = err?.message || @json(__('接続に失敗しました。'))
        live.className = 'storage-test-live hint storage-test-result is-fail'
      }
    })
  })()
</script>
