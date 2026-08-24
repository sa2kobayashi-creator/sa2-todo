<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#2563eb" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="description" content="{{ __('個人の招待利用と、組織・管理者向けの専用インスタンス。Todo、メモ、Photos、メッセージ、メール、マップをまとめます。') }}" />
    <title>{{ $appName }} — {{ __('予定・メモ・写真・メッセージを、ひとつの場所に。') }}</title>
    @include('partials.app-css')
  </head>
  <body class="lp-body">
    <header class="lp-header">
      <div class="lp-header-inner">
        <a href="/" class="lp-logo">
          <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="36" height="36" />
          <span>{{ $appName }}</span>
        </a>
        <nav class="lp-nav" aria-label="{{ __('ページ内メニュー') }}">
          <a href="#features">{{ __('機能一覧') }}</a>
          <a href="#pricing">{{ __('料金プラン') }}</a>
          <a href="#flow">{{ __('ご利用の流れ') }}</a>
          <a href="#faq">{{ __('よくある質問') }}</a>
          <a href="#contact">{{ __('お問い合わせ') }}</a>
        </nav>
        <div class="lp-header-actions">
          @include('partials.auth-lang-switcher')
          <a href="/login" class="lp-btn lp-btn-ghost">{{ __('ログイン') }}</a>
          @if(!empty($registrationOpen))
            <a href="/register" class="lp-btn lp-btn-primary">{{ __('招待コードで登録') }}</a>
          @endif
        </div>
      </div>
    </header>

    <main>
      <section class="lp-hero">
        <div class="lp-hero-inner">
          <div class="lp-hero-copy">
            <p class="lp-hero-tag">{{ __('予定・メモ・写真・メッセージを、ひとつの場所に。') }}</p>
            <h1>{{ __('あなたの日常をもっとシンプルに、もっと快適に。') }}</h1>
            <p class="lp-hero-lead">{{ __('まずは招待で無料利用できます。気に入ったら有料へ。家族・小組織で分けて使いたいときは、専用環境（管理者契約）に進めます。') }}</p>
            <p class="lp-cta">
              <a href="/login" class="lp-btn lp-btn-primary lp-btn-lg">{{ __('ログイン') }}</a>
              @if(!empty($registrationOpen))
                <a href="/register" class="lp-btn lp-btn-ghost lp-btn-lg">{{ __('招待コードで登録') }}</a>
              @endif
            </p>
            <p class="lp-hero-note">
              <span class="lp-info-dot" aria-hidden="true">i</span>
              {{ __('このサービスは招待制です。招待コードがあると誰でも登録できます。') }}
            </p>
          </div>

          <div class="lp-devices" aria-hidden="true">
            <div class="lp-badge">{{ __('すべてがつながるオールインワンサービス') }}</div>
            <div class="lp-laptop">
              <div class="lp-laptop-bar"><span></span><span></span><span></span></div>
              <div class="lp-laptop-screen">
                <div class="lp-mini-head">{{ $appName }}</div>
                <div class="lp-mini-grid">
                  <div class="lp-mini-card">
                    <b>{{ __('今日の予定') }}</b>
                    <span>{{ __('Todo') }} · {{ __('カレンダー') }}</span>
                  </div>
                  <div class="lp-mini-card">
                    <b>{{ __('メッセージ') }}</b>
                    <span>{{ __('グループ') }} · DM</span>
                  </div>
                  <div class="lp-mini-card">
                    <b>{{ __('メモ') }}</b>
                    <span>{{ __('ピン留め') }}</span>
                  </div>
                  <div class="lp-mini-card">
                    <b>{{ __('Photos') }}</b>
                    <span>{{ __('原本保管') }}</span>
                  </div>
                </div>
              </div>
            </div>
            <div class="lp-phone">
              <div class="lp-phone-notch"></div>
              <div class="lp-phone-screen">
                <b>{{ __('マップ') }} · {{ __('路線') }}</b>
                <span class="lp-phone-route"></span>
                <span class="lp-phone-route is-short"></span>
                <span class="lp-phone-pin"></span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="lp-section" id="features" aria-labelledby="landing-features-title">
        <h2 id="landing-features-title">{{ __('できること') }}</h2>
        <ul class="lp-features">
          <li>
            <span class="lp-feature-icon is-todo" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#2563eb" stroke-width="2"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg>
            </span>
            <strong>{{ __('Todo') }}</strong>
            <span>{{ __('一覧とカレンダー。リマインダと自分の休日。') }}</span>
          </li>
          <li>
            <span class="lp-feature-icon is-note" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#16a34a" stroke-width="2"><path d="M7 3h8l5 5v13H7z"/><path d="M15 3v5h5M9 13h6M9 17h4"/></svg>
            </span>
            <strong>{{ __('メモ') }}</strong>
            <span>{{ __('ピン留め・アーカイブ。日本語と英語の翻訳。') }}</span>
          </li>
          <li>
            <span class="lp-feature-icon is-photo" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#ea580c" stroke-width="2"><rect x="3" y="6" width="18" height="14" rx="2"/><circle cx="8.5" cy="11" r="1.5"/><path d="m21 16-5-5-7 7"/></svg>
            </span>
            <strong>{{ __('Photos') }}</strong>
            <span>{{ __('原本をまとめて保管。ライトは約 50GB、スタンダードは約 200GB。') }}</span>
          </li>
          <li>
            <span class="lp-feature-icon is-msg" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M4 5h16v10H8l-4 4z"/></svg>
            </span>
            <strong>{{ __('メッセージ') }}</strong>
            <span>{{ __('グループとダイレクトメッセージ。') }}</span>
          </li>
          <li>
            <span class="lp-feature-icon is-mail" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#e11d48" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 7 9-7"/></svg>
            </span>
            <strong>{{ __('メール') }}</strong>
            <span>{{ __('スタンダードは @sa2-plus.com。Gmail の接続もできます。') }}</span>
          </li>
          <li>
            <span class="lp-feature-icon is-map" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#0d9488" stroke-width="2"><path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.2"/></svg>
            </span>
            <strong>{{ __('マップ') }} · {{ __('路線') }}</strong>
            <span>{{ __('地図と乗り換え案内。') }}</span>
          </li>
        </ul>
      </section>

      <section class="lp-section" id="pricing" aria-labelledby="landing-plans-title">
        <h2 id="landing-plans-title">{{ __('ご利用料金（税別）') }}</h2>
        <p class="lp-section-lead">{{ __('金額は初期案です。正式な契約で確定します。') }}</p>
        <div class="lp-plans">
          <article class="lp-plan is-dedicated">
            <header class="lp-plan-head">
              <p>{{ __('組織・管理者向け') }}</p>
              <h3>{{ __('専用インスタンス') }}</h3>
            </header>
            <div class="lp-plan-body">
              <p>{{ __('家族・小組織向けに、お客様専用のアプリ環境を構築します。データは他のお客様と同居しません。') }}</p>
              <ul class="lp-price-rows">
                <li><span>{{ __('初期構築') }}</span><strong>{{ __('¥50,000〜') }}</strong></li>
                <li><span>{{ __('月額保守（5ユーザーまで）') }}</span><strong>{{ __('¥8,000〜／月') }}</strong></li>
                <li><span>{{ __('追加ユーザー') }}</span><strong>{{ __('¥1,000／人／月') }}</strong></li>
                <li><span>{{ __('メールボックス（1アドレス）') }}</span><strong>{{ __('¥300／月 または ¥3,000／年') }}</strong></li>
              </ul>
              <p class="hint">{{ __('写真・ファイルはお客様自身のクラウド（例: Cloudflare R2）へ置く方式を推奨します。クラウド利用料はお客様負担です。年一括の保守は1か月分相当オフにできます。') }}</p>
              <a href="#contact" class="lp-btn lp-btn-primary lp-plan-cta">{{ __('専用環境の相談') }}</a>
            </div>
          </article>

          <article class="lp-plan is-standard">
            <header class="lp-plan-head">
              <p>{{ __('個人・招待') }}</p>
              <h3>{{ __('スタンダード') }}</h3>
            </header>
            <div class="lp-plan-body">
              <p class="lp-plan-price">{{ __('¥980／月') }}</p>
              <p class="hint">{{ __('年額 ¥9,800。約 200GB 込み。周辺メニューも使えます。') }}</p>
              <ul class="lp-plan-includes">
                <li>{{ __('Todo・メモ・Photos・メッセージ・マップ・路線') }}</li>
                <li>{{ __('@sa2-plus.com メール（プランに含む）') }}</li>
                <li>{{ __('Gmail などの外部接続は無料') }}</li>
              </ul>
            </div>
          </article>

          <article class="lp-plan is-light">
            <header class="lp-plan-head">
              <p>{{ __('個人・招待') }}</p>
              <h3>{{ __('ライト') }}</h3>
            </header>
            <div class="lp-plan-body">
              <p class="lp-plan-price">{{ __('¥0') }}</p>
              <p class="hint">{{ __('約 50GB。まずは試せる枠です。') }}</p>
              <ul class="lp-plan-includes">
                <li>{{ __('Todo・メモ・Photos・メッセージ・マップ・路線') }}</li>
                <li>{{ __('@sa2-plus.com はオプション') }}</li>
              </ul>
            </div>
          </article>
        </div>
        <p class="hint lp-plans-note">{{ __('個人向けは共有インスタンスです。ストレージ超過は ¥300／100GB／月。専用環境は試用後でも、最初から分離が必要な場合でも相談できます。') }}</p>
      </section>

      <section class="lp-section" id="flow" aria-labelledby="landing-flow-title">
        <h2 id="landing-flow-title">{{ __('ご利用の流れ') }}</h2>
        <p class="lp-section-lead">{{ __('専用環境を最初に契約する必要はありません。使ってから選べます。') }}</p>
        <ol class="lp-flow">
          <li>
            <span class="lp-flow-num">1</span>
            <h3>{{ __('無料で触る') }}</h3>
            <p>{{ __('招待コードでライト（¥0）として登録し、Todo・メモ・写真などを実際に使います。') }}</p>
          </li>
          <li>
            <span class="lp-flow-num">2</span>
            <h3>{{ __('気に入ったら有料') }}</h3>
            <p>{{ __('日常で使うならスタンダード（¥980／月）。容量と周辺メニューが揃います。') }}</p>
          </li>
          <li>
            <span class="lp-flow-num">3</span>
            <h3>{{ __('組織・家族なら専用環境') }}</h3>
            <p>{{ __('同居したくない、管理者が必要、クラウドを自分で持ちたい、という段階で専用インスタンスへ。') }}</p>
          </li>
        </ol>
      </section>

      <section class="lp-section lp-values" aria-labelledby="landing-values-title">
        <h2 id="landing-values-title" class="visually-hidden">{{ __('選ばれる理由') }}</h2>
        <ul class="lp-value-grid">
          <li>
            <span class="lp-value-icon is-lock" aria-hidden="true"></span>
            <strong>{{ __('安心のプライバシー') }}</strong>
            <span>{{ __('通信は暗号化します。専用環境ではデータが他のお客様と同居しません。') }}</span>
          </li>
          <li>
            <span class="lp-value-icon is-cloud" aria-hidden="true"></span>
            <strong>{{ __('外部ストレージで経済的に') }}</strong>
            <span>{{ __('写真・ファイルは Cloudflare R2 や Backblaze B2 へ置けます。専用ではお客様クラウドを推奨します。') }}</span>
          </li>
          <li>
            <span class="lp-value-icon is-admin" aria-hidden="true"></span>
            <strong>{{ __('管理者もらくらく運用') }}</strong>
            <span>{{ __('専用契約の代表がユーザー・休日・メニューを管理できます。バックアップ方針もお渡しします。') }}</span>
          </li>
        </ul>
      </section>

      <section class="lp-section" id="faq" aria-labelledby="landing-faq-title">
        <h2 id="landing-faq-title">{{ __('よくある質問') }}</h2>
        <div class="lp-faq">
          <details open>
            <summary>{{ __('最初から専用環境を契約すべきですか？') }}</summary>
            <p>{{ __('多くの場合は不要です。まずライトで試し、必要ならスタンダードへ。同居や管理者が必要になったら専用へ進むのが費用対効果が高いです。最初から分離が必要な組織は、その旨をご相談ください。') }}</p>
          </details>
          <details>
            <summary>{{ __('個人向けと専用環境の違いは何ですか？') }}</summary>
            <p>{{ __('個人向けは運営の共有インスタンスです。専用はお客様だけのアプリとデータベースで、契約代表が管理者になります。ストレージの鍵もお客様持ちにできます。') }}</p>
          </details>
          <details>
            <summary>{{ __('招待コードがないと使えませんか？') }}</summary>
            <p>{{ __('はい。当面は招待制です。コードがある人だけ登録できます。専用環境は個別のお見積です。') }}</p>
          </details>
        </div>
      </section>

      <section class="lp-section" id="contact" aria-labelledby="landing-contact-title">
        <h2 id="landing-contact-title">{{ __('お問い合わせ') }}</h2>
        <p class="lp-section-lead">{{ __('専用環境のご相談、導入の見積は運営が対応します。まずは招待で触っていただき、そのうえでご連絡いただく形がスムーズです。') }}</p>
        <p class="lp-cta">
          <a href="/login" class="lp-btn lp-btn-primary">{{ __('ログイン') }}</a>
          @if(!empty($registrationOpen))
            <a href="/register" class="lp-btn lp-btn-ghost">{{ __('招待コードで登録') }}</a>
          @endif
        </p>
        <p class="hint">{{ __('ログイン後、管理者はアプリ内の問い合わせから運営へ送れます。') }}</p>
      </section>
    </main>

    <section class="lp-banner" aria-labelledby="landing-banner-title">
      <div class="lp-banner-inner">
        <div>
          <h2 id="landing-banner-title">{{ __('今すぐはじめて、あなたの毎日をもっと快適に。') }}</h2>
          <p>{{ __('招待コードをお持ちの方は、今すぐ登録・ログインできます。') }}</p>
        </div>
        <p class="lp-cta">
          <a href="/login" class="lp-btn lp-btn-on-dark">{{ __('ログイン') }}</a>
          @if(!empty($registrationOpen))
            <a href="/register" class="lp-btn lp-btn-on-dark">{{ __('招待コードで登録') }}</a>
          @endif
        </p>
      </div>
    </section>

    <footer class="lp-footer">
      <a href="/terms">{{ __('利用規約') }}</a>
      <a href="/privacy">{{ __('プライバシーポリシー') }}</a>
      <a href="/login">{{ __('ログイン') }}</a>
    </footer>
    @include('partials.csrf-keepalive')
  </body>
</html>
