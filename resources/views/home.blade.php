<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#2563eb" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="description" content="{{ __('写真と動画を安く安定して置き、カレンダーとメモをすぐ使える生活アプリ。家族向けテナント契約、英語表示、これから Workers AI。') }}" />
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
          <a href="/login" class="lp-btn lp-btn-ghost lp-btn-chevron">{{ __('ログイン') }}</a>
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
            <p class="lp-hero-lead">{{ __('個人の招待利用と、家族・小組織向けの専用環境（管理者契約）があります。Todo、メモ、Photos、メッセージ、メール、マップ、路線をまとめて使えます。') }}</p>
            <p class="lp-cta">
              <a href="/login" class="lp-btn lp-btn-primary lp-btn-lg lp-btn-chevron">{{ __('ログイン') }}</a>
              @if(!empty($registrationOpen))
                <a href="/register" class="lp-btn lp-btn-ghost lp-btn-lg">{{ __('招待コードで登録') }}</a>
              @endif
            </p>
            <p class="lp-hero-note">
              <span class="lp-info-shield" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M12 3.2 20 6.2v6.1c0 4.7-3.2 8.2-8 9.7-4.8-1.5-8-5-8-9.7V6.2z" fill="#dbeafe" stroke="#2563eb" stroke-width="1.6" stroke-linejoin="round"/><path d="m8.6 12.1 2.2 2.2 4.6-4.8" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
              {{ __('このサービスは招待制です。招待コードがあると誰でも登録できます。') }}
            </p>
          </div>

          <div class="lp-devices" aria-hidden="true">
            <div class="lp-badge">{{ __('すべてがつながるオールインワンサービス') }}</div>
            <div class="lp-laptop">
              <div class="lp-laptop-lid">
                <div class="lp-laptop-bezel">
                  <span class="lp-laptop-camera"></span>
                  <div class="lp-laptop-screen">
                    <aside class="lp-mini-side">
                      <span class="is-on">📅</span>
                      <span>✓</span>
                      <span>📝</span>
                      <span>🖼</span>
                      <span>💬</span>
                      <span>✉</span>
                      <span>🗺</span>
                    </aside>
                    <div class="lp-mini-main">
                      <div class="lp-mini-card is-todo">
                        <b>{{ __('今日の予定') }}</b>
                        <ul>
                          <li><i></i>{{ __('提案書を作成') }}</li>
                          <li><i class="is-on"></i>{{ __('チームミーティング 10:00') }}</li>
                          <li><i></i>{{ __('買い物リストを確認') }}</li>
                        </ul>
                      </div>
                      <div class="lp-mini-card is-photos">
                        <b>{{ __('Photos') }}</b>
                        <div class="lp-mini-photos">
                          <span class="is-a"></span>
                          <span class="is-b"></span>
                          <span class="is-c"></span>
                        </div>
                      </div>
                      <div class="lp-mini-card is-note">
                        <b>{{ __('メモ') }}</b>
                        <em>{{ __('アイデアメモ') }}</em>
                        <span>{{ __('週末の予定と買い物を整理') }}</span>
                      </div>
                      <div class="lp-mini-card is-msg">
                        <b>{{ __('メッセージ') }}</b>
                        <ul class="lp-mini-chats">
                          <li><i class="is-t">田</i><span>{{ __('田中さん') }}</span></li>
                          <li><i class="is-s">佐</i><span>{{ __('佐藤さん') }}</span></li>
                          <li><i class="is-y">山</i><span>{{ __('山本さん') }}</span></li>
                        </ul>
                      </div>
                      <div class="lp-mini-card is-mail">
                        <b>{{ __('メール') }}</b>
                        <div class="lp-mini-mail">
                          <span>{{ __('受信トレイ') }} (3)</span>
                          <span>{{ __('重要') }} (1)</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="lp-laptop-hinge"></div>
              <div class="lp-laptop-base"></div>
            </div>
            <div class="lp-phone">
              <div class="lp-phone-island"></div>
              <div class="lp-phone-screen">
                <b>{{ __('マップ') }} · {{ __('路線') }}</b>
                <div class="lp-phone-map">
                  <span class="lp-phone-path"></span>
                  <span class="lp-phone-pin"></span>
                </div>
                <ul class="lp-phone-stops">
                  <li><span>{{ __('渋谷') }}</span><strong>{{ __('15分') }}</strong></li>
                  <li><span>{{ __('新宿') }}</span><strong>{{ __('22分') }}</strong></li>
                  <li><span>{{ __('東京') }}</span><strong>{{ __('33分') }}</strong></li>
                </ul>
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
          <li>
            <span class="lp-feature-icon is-media" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#db2777" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            </span>
            <strong>{{ __('音楽再生') }} · {{ __('動画再生') }}</strong>
            <span>{{ __('プレイリストで再生。動画は検索して視聴。') }}</span>
          </li>
          <li>
            <span class="lp-feature-icon is-life" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#d97706" stroke-width="2"><path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z"/></svg>
            </span>
            <strong>{{ __('生活ガイド(AI)') }} · {{ __('家計簿') }}</strong>
            <span>{{ __('AI に生活のことを聞ける。収支は家計簿で記録。') }}</span>
          </li>
        </ul>
      </section>

      <section class="lp-section" id="pricing" aria-labelledby="landing-plans-title">
        <h2 id="landing-plans-title">{{ __('ご利用料金（税込）') }}</h2>
        <p class="lp-section-lead">{{ __('金額はすべて税込です。テナント契約は最初から期間つきの優良プランとして公開しています。') }}</p>
        <div class="lp-plans">
          <article class="lp-plan is-tenant is-featured">
            <header class="lp-plan-head">
              <p>{{ __('家族・小規模向け') }} <span class="lp-plan-badge">{{ __('おすすめ') }}</span></p>
              <h3>{{ __('テナント契約') }}</h3>
            </header>
            <div class="lp-plan-body">
              <p>{{ __('同じ sa2-plus.com 上で、契約ごとにユーザーと鍵を分けます。極力安価で安定した家族の場所です。') }}</p>
              <p class="lp-plan-price">{{ __('¥:yen／月', ['yen' => number_format((int) ($tenantMonthlyYen ?? 3980))]) }}</p>
              <p class="hint">{{ __('最初の:days日間は無料。年額 ¥:yearly（:monthsか月分）。', ['days' => (int) ($tenantTrialDays ?? 30), 'yearly' => number_format((int) ($tenantYearlyYen ?? 43780)), 'months' => (int) ($yearlyMonthsCharged ?? 11)]) }}</p>
              <ul class="lp-plan-includes">
                <li>{{ __('管理者は契約代表1名だけ') }}</li>
                <li>{{ __('月5ユーザーまで（代表を含む）') }}</li>
                <li>{{ __('@sa2-plus.com メールは各1アドレス込み') }}</li>
                <li>{{ __('代表が R2／B2／AI／API キーを設定できる') }}</li>
                <li>{{ __('追加ユーザー ¥:yen／人／月', ['yen' => number_format((int) ($tenantExtraUserYen ?? 1000))]) }}</li>
                <li>{{ __('サーバー分離が必要なら専用インスタンスへ') }}</li>
              </ul>
              <a href="#contact" class="lp-btn lp-btn-primary lp-plan-cta">{{ __('テナント契約を申し込む') }}</a>
            </div>
          </article>

          <article class="lp-plan is-dedicated">
            <header class="lp-plan-head">
              <p>{{ __('組織・管理者向け') }}</p>
              <h3>{{ __('専用インスタンス') }}</h3>
            </header>
            <div class="lp-plan-body">
              <p>{{ __('運営がお客様専用のサーバーへ、同じアプリを設置します。sa2-plus.com の共有環境とは別です。') }}</p>
              <ul class="lp-plan-includes">
                <li>{{ __('契約代表が管理者（ユーザー・休日・メニュー）') }}</li>
                <li>{{ __('写真はお客様のクラウド（R2／B2）接続を推奨') }}</li>
                <li>{{ __('月額はアプリ更新・障害一次対応・バックアップ確認') }}</li>
                <li>{{ __('サーバー代・独自ドメインメールは含みません（別途）') }}</li>
              </ul>
              <ul class="lp-price-rows">
                <li><span>{{ __('初期構築') }}</span><strong>{{ __('¥50,000〜') }}</strong></li>
                <li><span>{{ __('月額保守（5ユーザーまで）') }}</span><strong>{{ __('¥8,000〜／月') }}</strong></li>
                <li><span>{{ __('追加ユーザー') }}</span><strong>{{ __('¥1,000／人／月') }}</strong></li>
                <li><span>{{ __('メールボックス（1アドレス）') }}</span><strong>{{ __('¥300／月 または ¥3,000／年') }}</strong></li>
              </ul>
              <p class="hint">{{ __('専用は別サーバーです。共有 sa2-plus.com 上の管理者契約はテナント契約です。') }}</p>
              <a href="#contact" class="lp-btn lp-btn-ghost lp-plan-cta">{{ __('専用環境の相談') }}</a>
            </div>
          </article>

          <article class="lp-plan is-standard">
            <header class="lp-plan-head">
              <p>{{ __('個人・招待') }}</p>
              <h3>{{ __('スタンダード') }}</h3>
            </header>
            <div class="lp-plan-body">
              <p class="lp-plan-price">{{ __('¥:yen／月', ['yen' => number_format((int) ($standardMonthlyYen ?? 980))]) }}</p>
              <p class="hint">{{ __('年額 ¥:yearly。約 200GB 込み。周辺メニューも使えます。', ['yearly' => number_format((int) ($standardYearlyYen ?? 9800))]) }}</p>
              <ul class="lp-plan-includes">
                <li>{{ __('運営の共有サーバー（sa2-plus.com）で利用') }}</li>
                <li>{{ __('Todo・メモ・Photos・メッセージ・マップ・路線') }}</li>
                <li>{{ __('@sa2-plus.com メール（プランに含む）') }}</li>
                <li>{{ __('Gmail などの外部接続は無料') }}</li>
                <li>{{ __('管理者権限・ストレージ鍵の設定はありません') }}</li>
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
                <li>{{ __('運営の共有サーバー（sa2-plus.com）で利用') }}</li>
                <li>{{ __('Todo・メモ・Photos・メッセージ・マップ・路線') }}</li>
                <li>{{ __('@sa2-plus.com はオプション') }}</li>
                <li>{{ __('管理者権限・ストレージ鍵の設定はありません') }}</li>
              </ul>
            </div>
          </article>
        </div>
        <p class="hint lp-plans-note">{{ __('個人向けは運営の共有インスタンスです。ライト／スタンダードに管理者はありません。家族向けの管理者契約はテナント、サーバー分離は専用です。') }}</p>
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
            <h3>{{ __('家族ならテナント契約') }}</h3>
            <p>{{ __('同じ共有サーバー上で、管理者1名・5ユーザー・メール各1アドレス。最初の:days日は無料、その後 ¥:yen／月です。サーバーを分けたいときは専用インスタンスへ。', ['days' => (int) ($tenantTrialDays ?? 30), 'yen' => number_format((int) ($tenantMonthlyYen ?? 3980))]) }}</p>
          </li>
        </ol>
      </section>

      <section class="lp-section" id="ai-roadmap" aria-labelledby="landing-ai-title">
        <h2 id="landing-ai-title">{{ __('Workers AI で、これからもっと便利に') }}</h2>
        <p class="lp-section-lead">{{ __('生活の知恵・話し相手と、料理レシピ、今日のカレンダー案内はもう使えます。路線検索は NAVITIME の経路検索に対応済みです。') }}</p>
        <ul class="lp-roadmap">
          <li>
            <strong>{{ __('生活の知恵・話し相手') }}</strong>
            <span>{{ __('いま使える') }}</span>
          </li>
          <li>
            <strong>{{ __('料理レシピ・調理方法') }}</strong>
            <span>{{ __('いま使える') }}</span>
          </li>
          <li>
            <strong>{{ __('カレンダーと一緒に考える') }}</strong>
            <span>{{ __('いま使える') }}</span>
          </li>
          <li>
            <strong>{{ __('精度の高い路線案内（NAVITIME）') }}</strong>
            <span>{{ __('いま使える') }}</span>
          </li>
        </ul>
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
            <span>{{ __('専用契約の代表、またはテナント契約の代表がユーザー・休日・メニューを管理できます。バックアップ方針もお渡しします。') }}</span>
          </li>
        </ul>
      </section>

      <section class="lp-section" id="faq" aria-labelledby="landing-faq-title">
        <h2 id="landing-faq-title">{{ __('よくある質問') }}</h2>
        <div class="lp-faq">
          <details open>
            <summary>{{ __('最初から専用環境を契約すべきですか？') }}</summary>
            <p>{{ __('多くの場合は不要です。まずライトで試し、必要ならスタンダードへ。家族で管理者が必要ならテナント契約、サーバー分離が必要なら専用へ。最初から分離が必要な組織は、その旨をご相談ください。') }}</p>
          </details>
          <details>
            <summary>{{ __('個人向けと専用環境の違いは何ですか？') }}</summary>
            <p>{{ __('個人向けは運営の sa2-plus.com で同居し、管理者はありません。テナント契約は同じ共有サーバー上で契約ごとに分け、代表が管理者になります。専用は別サーバーに同じアプリを置きます。写真のクラウド接続は、テナントでは代表が、専用では運営が行います。サーバー代や独自ドメインメールは専用に含みません。') }}</p>
          </details>
          <details>
            <summary>{{ __('sa2-plus.com で管理者アカウントを発行してもらえますか？') }}</summary>
            <p>{{ __('はい。テナント契約として、管理者は代表1名・ユーザーは月5名まで（代表を含む）・@sa2-plus.com メールは各1アドレス込みです。最初の:days日は無料、その後 ¥:yen／月（税込）です。個人のライト／スタンダードには管理者は付けません。サーバーを分けたい場合は専用インスタンスです。', ['days' => (int) ($tenantTrialDays ?? 30), 'yen' => number_format((int) ($tenantMonthlyYen ?? 3980))]) }}</p>
          </details>
          <details>
            <summary>{{ __('招待コードがないと使えませんか？') }}</summary>
            <p>{{ __('はい。当面は招待制です。コードがある人だけ登録できます。専用環境は個別のお見積です。') }}</p>
          </details>
        </div>
      </section>

      <section class="lp-section" id="contact" aria-labelledby="landing-contact-title">
        <h2 id="landing-contact-title">{{ __('お問い合わせ') }}</h2>
        <p class="lp-section-lead">{{ __('公開料金どおり、最初の:days日は試用です。テナント契約の開始は運営が対応します。まずは招待で触っていただき、そのうえでご連絡ください。', ['days' => (int) ($tenantTrialDays ?? 30)]) }}</p>
        <p class="lp-cta">
          <a href="/login" class="lp-btn lp-btn-primary">{{ __('ログイン') }}</a>
          @if(!empty($registrationOpen))
            <a href="/register" class="lp-btn lp-btn-ghost">{{ __('招待コードで登録') }}</a>
          @endif
        </p>
        @if(!empty($contactEmail))
          <p>{{ __('メールでのお問い合わせ') }}: <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a></p>
        @endif
        <p class="hint">{{ __('ログイン後は、アプリ内の問い合わせからも運営へ送れます。') }}</p>
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
      <a href="/tokushoho">{{ __('特定商取引法に基づく表記') }}</a>
      <a href="/login">{{ __('ログイン') }}</a>
    </footer>
    @include('partials.csrf-keepalive')
  </body>
</html>
