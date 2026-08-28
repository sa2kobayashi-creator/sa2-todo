<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>{{ __('利用規約') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body class="auth-body">
    @include('partials.auth-lang-switcher')
    <main class="auth-shell auth-shell-wide">
      <a href="/" class="auth-brand">
        <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="40" height="40" />
        <span>{{ config('app.name') }}</span>
      </a>
      <div class="auth-card panel">
        <h1>{{ __('利用規約') }}</h1>
        <p class="hint">{{ __('最終更新: :date', ['date' => '2026-08-28']) }}</p>

        @if(($htmlLang ?? app()->getLocale()) === 'en')
          <section class="legal-section" style="line-height:1.7;">
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">1. About this service</h2>
            <p>This service ({{ config('app.name') }}) is a personal life and productivity app. Full use is through Standard or a tenant contract. Light is a short trial only. Use the account issued to you and do not share login credentials.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">2. Accounts and eligibility</h2>
            <p>You may apply from the top page (“Apply”). Standard and tenant applications are reviewed by the operator before a signup email is sent. Light (trial) applications are screened automatically; temporary email addresses, blank or unclear purpose, and applications beyond the weekly Light intake limit are declined. The invite-code path, when available, is subject to the same Light trial rules (purpose, no temporary email, weekly cap). You must agree to these Terms and the Privacy Policy, and provide accurate information. Minors should use the service only with guardian consent where required by law.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">3. Acceptable use</h2>
            <p>You are responsible for content you store (todos, notes, photos, finance data, etc.). Illegal content, infringement of others’ rights, abuse of APIs, or attempts to access other users’ data are prohibited.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">4. External services and prototypes</h2>
            <p>Features may rely on third-party APIs (storage, translation, AI, maps, messaging). Usage limits, outages, or billing constraints may apply. Prototype features (including AI photo enhancement and some AI helpers) are available only to designated roles and may change or stop without notice.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">5. Plans and formation of contract</h2>
            <p>The service offers Light (trial, about 20GB of photo storage, weekly intake limit), paid Standard (about 200GB), tenant contracts, dedicated instances, and options (mailbox, storage overage). A paid contract is formed when you complete the order and the payment processor approves the transaction. Where a free trial on a paid plan applies, the contract is formed at the time of order and the first charge occurs on the last day of the trial.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">6. Fees, payment and automatic renewal</h2>
            <p>Prices, tax treatment and any additional costs are set out in the <a href="/tokushoho">Notation based on the Specified Commercial Transactions Act</a>. Payment is by credit card through a payment processor; card details are collected by the processor and are not stored by the operator.</p>
            <p>Paid plans <strong>renew automatically</strong> on the same terms at the end of each period, and the next period is charged on the renewal date. If payment fails, the operator will retry and notify you; if payment remains outstanding after a reasonable period, paid features may be suspended or the contract terminated.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">7. Cancellation and refunds</h2>
            <p>You may cancel at any time. Cancellation takes effect on the <strong>last day of the period already paid for</strong>, and you may keep using the service until then. Because this is a digital service, no pro-rata or other refund is provided for a period already paid for. Cooling-off does not apply to mail-order sales in Japan.</p>
            <p>This does not apply where the operator is at fault for failing to provide the service, or where a refund is required by law.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">8. Exclusion of anti-social forces</h2>
            <p>You represent and warrant that you are not, and are not associated with, an organised crime group or equivalent. If this proves untrue, the operator may terminate the contract without notice.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">9. Data export and account deletion</h2>
            <p>You may download an export of your account data from My Page (metadata; binary media files may be omitted). You may delete your account from My Page after password confirmation. Deletion removes associated app data as far as reasonably practicable; backups may persist for a limited operational period. The refund rules above also apply if you delete your account during a paid term.</p>
            <p>For Light (trial) accounts with no login for about 90 days, the operator sends a warning email. If there is still no login within about 14 days after that warning, the operator may delete the account and its data without further notice. Logging in clears the warning.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">10. Disclaimer and limitation of liability</h2>
            <p>The operator does not warrant that the service fits your particular purpose, is available without interruption, or that data will never be lost. Third-party outages or specification changes may limit features.</p>
            <p>Except in cases of the operator’s <strong>wilful misconduct or gross negligence</strong>, the operator’s liability is limited to ordinary damages and capped at the total fees you paid in the 12 months preceding the event. For features provided free of charge, the operator is not liable except in cases of wilful misconduct or gross negligence. This clause does not apply to the extent it is void under the Consumer Contract Act or other mandatory law.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">11. Governing law and jurisdiction</h2>
            <p>These Terms are governed by the laws of Japan. The district court having jurisdiction over the operator’s principal place of business shall be the agreed court of first instance, provided that a consumer may also bring proceedings in the court for their own address.</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">12. Changes to these Terms</h2>
            <p>Treating these Terms as standard terms under the Civil Code, the operator may amend them where the change benefits users generally, or where the change is not contrary to the purpose of the contract and is reasonable in light of its necessity and content.</p>
            <p>The operator will announce the amended content and its <strong>effective date</strong> in advance, in-app or by email. For price increases and other changes with a material impact, notice will be given at least 30 days before the effective date, and you may cancel before that date so as not to be bound by the amended Terms.</p>
          </section>
        @else
          <section class="legal-section" style="line-height:1.7;">
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">1. 本サービスについて</h2>
            <p>本サービス（{{ config('app.name') }}）は、個人向けの生活・生産性アプリです。本利用はスタンダードまたはテナント契約を中心とし、ライトは短期間のお試しです。発行されたアカウントは本人のみが利用し、ログイン情報を第三者に共有しないでください。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">2. アカウント・登録</h2>
            <p>トップページの「利用申請」から申し込めます。スタンダードおよびテナント契約は運営が内容を確認したうえで登録用メールを送ります。ライト（お試し）は自動審査とし、一時的なメールアドレス、空欄または不明瞭な利用目的、および週あたりのライト受付上限を超える申請はお断りします。運営が発行する招待コードによる登録でも、ライトのお試し条件（利用目的・一時メール拒否・週上限）は同じです。本規約およびプライバシーポリシーへの同意が必要です。正確な情報を登録してください。法令上必要な場合、未成年者は保護者の同意のもとで利用してください。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">3. 禁止事項・利用者の責任</h2>
            <p>Todo・メモ・写真・入出金など登録データの管理責任は原則として利用者にあります。法令違反、第三者の権利侵害、APIの濫用、他利用者データへの不正アクセスを禁止します。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">4. 外部連携と試作機能</h2>
            <p>ストレージ・翻訳・AI・地図・メッセージ等の外部APIを利用します。利用上限・障害・課金制約により機能が制限されることがあります。一部のAI支援は予告なく変更・停止することがあります。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">5. プランと契約の成立</h2>
            <p>本サービスにはライト（お試し・写真保管の目安約20GB・週の受付上限あり）、有料のスタンダード（目安約200GB）、テナント契約、専用インスタンス、およびオプション（メールボックス、ストレージ超過）があります。有料プランの契約は、利用者が申込内容を確認して申込操作を行い、決済事業者による決済の承認が完了した時点で成立します。有料プランに無料お試し期間がある場合は、申込操作の時点で契約が成立し、お試し期間の終了日に最初の請求が発生します。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">6. 料金・支払方法・自動更新</h2>
            <p>各プランの金額、消費税の取扱い、対価以外に必要となる費用は<a href="/tokushoho">特定商取引法に基づく表記</a>に定めるとおりです。支払方法はクレジットカード（決済事業者を通じた決済）とし、カード情報は決済事業者が直接取得するため運営者は保持しません。</p>
            <p>有料プランは<strong>契約期間の満了日に同一条件で自動更新</strong>され、更新日に次期分の料金が決済されます。決済が失敗した場合、運営者は再試行および利用者への通知を行い、相当期間経過後もお支払いが確認できないときは、有料機能の利用を停止し、または契約を解除することがあります。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">7. 解約・返金</h2>
            <p>利用者はいつでも解約を申し込むことができます。解約の効力は<strong>お支払い済みの期間の末日</strong>に発生し、当該末日まではご利用いただけます。デジタルサービスの性質上、お支払い済みの期間について日割りその他の返金は行いません。通信販売のため、クーリング・オフ制度の適用はありません。</p>
            <p>ただし、運営者の責めに帰すべき事由によりサービスを提供できなかった場合、および法令上返金が必要な場合は、この限りではありません。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">8. 反社会的勢力の排除</h2>
            <p>利用者は、自らが暴力団、暴力団員、その他これらに準ずる者（以下「反社会的勢力」）に該当しないこと、および反社会的勢力に関与していないことを表明し、保証します。これに反することが判明した場合、運営者は催告なく契約を解除できます。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">9. データエクスポートと退会</h2>
            <p>マイページからアカウントデータのエクスポート（主にメタデータ。写真・音楽のバイナリは含まれない場合があります）および、パスワード確認後の退会が可能です。退会時は関連データの削除に努めますが、バックアップ等に合理的期間残存する場合があります。有料プランの契約中に退会した場合も、前条の返金の定めが適用されます。</p>
            <p>ライト（お試し）アカウントについて、約90日間ログインがない場合、運営は警告メールを送ります。警告後さらに約14日間ログインがない場合、運営は催告なくアカウントおよび関連データを削除できます。ログインすれば警告は解除されます。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">10. 免責・責任の制限</h2>
            <p>運営者は、本サービスが利用者の特定の目的に適合すること、常に中断なく利用できること、およびデータが滅失しないことを保証するものではありません。外部サービスの障害・仕様変更により機能が制限されることがあります。</p>
            <p>運営者が利用者に対して負う損害賠償の範囲は、運営者の<strong>故意または重大な過失による場合を除き</strong>、通常生ずべき損害に限られ、その総額は、損害が発生した時点から遡って12か月間に当該利用者が運営者に支払った利用料金の総額を上限とします。無償で提供する機能については、故意または重大な過失がある場合を除き責任を負いません。本条は、消費者契約法その他の強行法規により無効となる範囲では適用されません。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">11. 準拠法・裁判管轄</h2>
            <p>本規約は日本法に準拠します。本サービスに関して紛争が生じた場合、運営者の主たる事業所所在地を管轄する地方裁判所を第一審の合意管轄裁判所とします。ただし、利用者が消費者である場合、利用者の住所地を管轄する裁判所への提訴を妨げるものではありません。</p>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">12. 本規約の変更</h2>
            <p>運営者は、本規約が民法上の定型約款に該当することを前提に、変更が利用者の一般の利益に適合する場合、または変更が契約の目的に反せず、変更の必要性・内容の相当性に照らして合理的である場合に、本規約を変更することができます。</p>
            <p>変更にあたっては、変更後の内容と<strong>効力発生日をあらかじめアプリ内またはメールで周知</strong>します。料金の値上げその他利用者に重大な影響を及ぼす変更については、効力発生日の30日前までに周知し、利用者は効力発生日までに解約することで変更後の規約に拘束されないことを選択できます。</p>
          </section>
        @endif

        <div class="auth-links" style="margin-top:1.5rem;">
          <a href="/privacy">{{ __('プライバシーポリシー') }}</a>
          <a href="/tokushoho">{{ __('特定商取引法に基づく表記') }}</a>
          <a href="/apply">{{ __('利用申請へ') }}</a>
          <a href="/login">{{ __('ログインへ') }}</a>
        </div>
      </div>
    </main>
    @include('legal.partials.mark-read', ['legalReadKey' => 'sa2_legal_terms_read'])
  </body>
</html>
