<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#2563eb" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <link rel="canonical" href="{{ url('/') }}" />
    <meta name="description" content="{{ __('Todo・メモ・写真をひとつにまとめて管理できるWebアプリ「sa2-plus」。やることの管理、メモの保存、写真の整理をシンプルに。スマートフォンやパソコンから利用できます。') }}" />
    <title>{{ __('Todo・メモ・写真をまとめて管理できるWebアプリ｜sa2-plus') }}</title>
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
          <a href="#about">{{ __('sa2-plusとは') }}</a>
          <a href="#features">{{ __('できること') }}</a>
          <a href="#pricing">{{ __('料金プラン') }}</a>
          <a href="#flow">{{ __('ご利用方法') }}</a>
          <a href="#faq">{{ __('よくある質問') }}</a>
          <a href="#contact">{{ __('お問い合わせ') }}</a>
          <a href="#start">{{ __('はじめる') }}</a>
        </nav>
        <div class="lp-header-actions">
          @include('partials.auth-lang-switcher')
          <a href="/login" class="lp-btn lp-btn-ghost lp-btn-chevron">{{ __('ログイン') }}</a>
          @include('partials.lp-apply-cta', [
            'href' => '/apply',
            'event' => 'cta.apply',
            'class' => 'lp-btn lp-btn-primary',
            'label' => __('利用申請'),
            'applicationsOpen' => !empty($applicationsOpen),
          ])
          @if(!empty($registrationOpen))
            <a href="/register" data-stat-event="cta.register_invite" class="lp-btn lp-btn-ghost">{{ __('招待コードで登録') }}</a>
          @endif
        </div>
      </div>
    </header>

    <main>
      <section class="lp-hero">
        <div class="lp-hero-inner">
          <div class="lp-hero-copy">
            <p class="lp-hero-tag">{{ __('予定・メモ・写真・メッセージを、ひとつの場所に。') }}</p>
            <h1>{{ __('Todo・メモ・写真をひとつにまとめて管理｜sa2-plus') }}</h1>
            <p class="lp-hero-lead">{{ __('sa2-plusは、Todo・メモ・写真をひとつにまとめて管理できるWebアプリです。「やること」はTodo、「残しておきたい情報」はメモ、「記録しておきたい画像」はPhotos。日々の情報をひとつのサービスでまとめて管理できます。') }}</p>
            <p class="lp-hero-lead">{{ __('写真の保存・整理から、Todoやメモの管理まで。写真管理アプリとしても、Todo・メモ・写真をまとめて使えるWebアプリとしても利用できます。') }}</p>
            <p class="lp-hero-lead">{{ __('本格利用はスタンダード、家族・小組織はテナント契約が中心です。ライトは短期間のお試し（約:gbGB）です。', ['gb' => (int) ($lightQuotaGb ?? 20)]) }}</p>
            <p class="lp-cta">
              @include('partials.lp-apply-cta', [
                'href' => '/apply?plan=light',
                'event' => 'cta.plan.light',
                'class' => 'lp-btn lp-btn-primary lp-btn-lg',
                'label' => __('無料で試してみる'),
                'plan' => 'light',
              ])
              <a href="#pricing" class="lp-btn lp-btn-ghost lp-btn-lg">{{ __('料金プランを見る') }}</a>
            </p>
            <p class="lp-hero-note">
              <span class="lp-info-shield" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M12 3.2 20 6.2v6.1c0 4.7-3.2 8.2-8 9.7-4.8-1.5-8-5-8-9.7V6.2z" fill="#dbeafe" stroke="#2563eb" stroke-width="1.6" stroke-linejoin="round"/><path d="m8.6 12.1 2.2 2.2 4.6-4.8" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
              {{ __('スタンダード／テナントは運営確認後に登録メールが届きます。ライト（お試し）は自動審査のうえメールが届きます。') }}
            </p>
          </div>

          <div class="lp-devices" aria-hidden="true">
            <div class="lp-badge">{{ __('Todo・予定・メモ・写真をひとつに') }}</div>
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

                  <section class="lp-section" id="about" aria-labelledby="landing-about-title">
        <h2 id="landing-about-title">{{ __('sa2-plusとは？') }}</h2>
        <p class="lp-section-lead">{{ __('sa2-plusは、Todo・メモ・写真をひとつにまとめて管理できるWebアプリです。') }}</p>
        <p>{{ __('「やること」はTodo、「残しておきたい情報」はメモ、「記録しておきたい画像」はPhotos。日々の情報をひとつのサービスでまとめて管理できます。') }}</p>
        <p>{{ __('Todoだけ、メモだけ、写真だけと、それぞれ別のアプリを使い分けるのではなく、日常で使うさまざまな情報をひとつのWebアプリにまとめることを目指しています。') }}</p>
        <p>{{ __('個人での予定管理やタスク管理はもちろん、家族や小規模な組織での利用にも対応しています。スマートフォンでもパソコンでも利用できます。') }}</p>
      </section>

            <section class="lp-section" id="features" aria-labelledby="landing-features-title">
        <h2 id="landing-features-title">{{ __('Sa2 Plusでできること') }}</h2>
        <ul class="lp-features">
          <li>
            <span class="lp-feature-icon is-todo" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#2563eb" stroke-width="2"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg>
            </span>
            <h3>{{ __('Todoをかんたんに管理') }}</h3>
            <p>{{ __('毎日のTodoやタスクを一覧でかんたんに管理。カレンダーと組み合わせて予定を確認しながら、やるべきことを整理できます。') }}</p>
          </li>
          <li>
            <span class="lp-feature-icon is-life" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#d97706" stroke-width="2"><path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z"/></svg>
            </span>
            <h3>{{ __('予定・カレンダー管理') }}</h3>
            <p>{{ __('日々の予定をカレンダーで確認。Todoと予定を一緒に管理することで、今日やることを分かりやすく整理できます。') }}</p>
          </li>
          <li>
            <span class="lp-feature-icon is-note" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#16a34a" stroke-width="2"><path d="M7 3h8l5 5v13H7z"/><path d="M15 3v5h5M9 13h6M9 17h4"/></svg>
            </span>
            <h3>{{ __('大切なメモをいつでも保存') }}</h3>
            <p>{{ __('思いついたアイデアや買い物リスト、仕事のメモなど、大切な情報をいつでも保存できます。ピン留めやアーカイブにも対応しています。') }}</p>
          </li>
          <li>
            <span class="lp-feature-icon is-photo" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#ea580c" stroke-width="2"><rect x="3" y="6" width="18" height="14" rx="2"/><circle cx="8.5" cy="11" r="1.5"/><path d="m21 16-5-5-7 7"/></svg>
            </span>
            <h3>{{ __('写真を保存・整理できるPhotos') }}</h3>
            <p>{{ __('sa2-plusのPhotos機能では、大切な写真をオンラインで保存・管理できます。旅行の写真や日常の記録など、必要な写真を整理して保存できます。Todoやメモとあわせて利用することで、写真だけでなく、日々の情報をひとつのWebアプリでまとめて管理できます。利用する環境に応じてCloudflare R2やBackblaze B2などの外部ストレージにも対応できます。') }}</p>
          </li>
          <li>
            <span class="lp-feature-icon is-mail" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#e11d48" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 7 9-7"/></svg>
            </span>
            <h3>{{ __('メール・メッセージ') }}</h3>
            <p>{{ __('Sa2 Plusでは、@sa2-plus.comのメールやGmailなどの外部メールサービスを利用できます。メッセージ機能ではグループやダイレクトメッセージにも対応しています。') }}</p>
          </li>
          <li>
            <span class="lp-feature-icon is-map" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#0d9488" stroke-width="2"><path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.2"/></svg>
            </span>
            <h3>{{ __('地図・路線検索') }}</h3>
            <p>{{ __('地図を確認しながら目的地までのルートを検索。路線検索・乗換案内にも対応しています。路線検索は NAVITIME の経路検索に対応済みです。') }}</p>
          </li>
          <li>
            <span class="lp-feature-icon is-life" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#d97706" stroke-width="2"><path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z"/></svg>
            </span>
            <h3>{{ __('AI相談') }}</h3>
            <p>{{ __('AIに生活のちょっとした疑問や料理、予定について相談できます。カレンダーの予定を確認しながら、毎日の生活をサポートします。') }}</p>
          </li>
        </ul>
        <p class="hint">{{ __('音楽再生・動画再生のほか、生活ガイド(AI)や家計簿も利用できます（機能は今後アップデートで拡張予定です）。') }}</p>
      </section>

            <section class="lp-section lp-values" id="reasons" aria-labelledby="landing-reasons-title">
        <h2 id="landing-reasons-title">{{ __('Sa2 Plusが選ばれる理由') }}</h2>
        <ul class="lp-value-grid">
          <li>
            <span class="lp-value-icon is-cloud" aria-hidden="true"></span>
            <h3>{{ __('Todo・メモ・写真をひとつのアプリで管理') }}</h3>
            <p>{{ __('Todo・メモ・写真を中心に、日々の情報をひとつのWebアプリでまとめて管理できます。') }}</p>
          </li>
          <li>
            <span class="lp-value-icon is-admin" aria-hidden="true"></span>
            <h3>{{ __('必要な機能をまとめて利用') }}</h3>
            <p>{{ __('タスク管理だけでなく、写真、メール、地図・路線検索、AI相談など、日常に必要な機能をまとめています。') }}</p>
          </li>
          <li>
            <span class="lp-value-icon is-lock" aria-hidden="true"></span>
            <h3>{{ __('個人から家族・小規模組織まで') }}</h3>
            <p>{{ __('個人での利用から、家族や小規模な組織での利用まで、利用スタイルに合わせたプランを用意しています。') }}</p>
          </li>
          <li>
            <span class="lp-value-icon is-cloud" aria-hidden="true"></span>
            <h3>{{ __('外部ストレージにも対応') }}</h3>
            <p>{{ __('写真やファイルを大量に保存したい場合は、Cloudflare R2やBackblaze B2などの外部ストレージを利用できます。') }}</p>
          </li>
        </ul>
      </section>

            <section class="lp-section" id="audience" aria-labelledby="landing-audience-title">
        <h2 id="landing-audience-title">{{ __('Sa2 Plusはこんな方におすすめ') }}</h2>
        <ul class="lp-plan-includes">
          <li>{{ __('Todoと予定をまとめて管理したい方') }}</li>
          <li>{{ __('複数のアプリを使い分けるのが面倒な方') }}</li>
          <li>{{ __('メモや写真をまとめて管理したい方') }}</li>
          <li>{{ __('日常の情報をひとつの場所に整理したい方') }}</li>
          <li>{{ __('家族で情報を管理したい方') }}</li>
          <li>{{ __('小規模なチームで利用できるサービスを探している方') }}</li>
          <li>{{ __('AIに生活について相談したい方') }}</li>
          <li>{{ __('地図や路線検索もひとつのサービスで利用したい方') }}</li>
        </ul>
      </section>

      <section class="lp-section" id="pricing" aria-labelledby="landing-plans-title">
        <h2 id="landing-plans-title">{{ __('料金プラン') }}</h2>
        <p class="lp-section-lead">{{ __('Sa2 Plusでは、無料で試せるライトプラン、個人向けのスタンダードプラン、家族・小規模組織向けのテナント契約、専用環境をご用意しています。') }}</p>
        @if(empty($applicationsFullyOpen))
          <p class="lp-plans-prep-notice" role="status">
            {{ __('現在最終準備中の機能がありますので今しばらくのお待ちをお願いいたします。') }}
          </p>
        @endif
        <div class="lp-plans">
          <article class="lp-plan is-standard is-featured">
            <header class="lp-plan-head">
              <p>{{ __('個人・本利用') }} <span class="lp-plan-badge">{{ __('おすすめ') }}</span></p>
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
              <p class="hint">{{ __('14日間のお試しは、申し込み後に始まります（カード登録が必要です）。') }}</p>
              @include('partials.lp-apply-cta', [
                'href' => '/apply?plan=standard',
                'event' => 'cta.plan.standard',
                'class' => 'lp-btn lp-btn-primary lp-plan-cta',
                'label' => __('スタンダードを申請する'),
                'plan' => 'standard',
              ])
            </div>
          </article>

          <article class="lp-plan is-tenant">
            <header class="lp-plan-head">
              <p>{{ __('家族・小規模向け') }}</p>
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
              @include('partials.lp-apply-cta', [
                'href' => '/apply?plan=tenant',
                'event' => 'cta.plan.tenant',
                'class' => 'lp-btn lp-btn-primary lp-plan-cta',
                'label' => __('テナント契約を申請する'),
                'plan' => 'tenant',
              ])
            </div>
          </article>

          <article class="lp-plan is-light">
            <header class="lp-plan-head">
              <p>{{ __('お試し') }}</p>
              <h3>{{ __('ライト') }}</h3>
            </header>
            <div class="lp-plan-body">
              <p class="lp-plan-price">{{ __('¥0') }}</p>
              <p class="hint">{{ __('約 :gbGB。短期間のお試し枠です（週:cap人まで）。', ['gb' => (int) ($lightQuotaGb ?? 20), 'cap' => (int) ($lightWeeklyCap ?? 50)]) }}</p>
              <ul class="lp-plan-includes">
                <li>{{ __('利用目的の記入が必須（短い・不明瞭は自動拒否）') }}</li>
                <li>{{ __('一時メールは自動拒否。管理者承認は不要（自動審査）') }}</li>
                <li>{{ __('Todo・メモ・Photos・メッセージ・マップ・路線') }}</li>
                <li>{{ __('約:warn日ログインがない場合は警告。さらに:grace日応答がなければ削除', ['warn' => (int) ($lightInactiveWarnDays ?? 90), 'grace' => (int) ($lightInactiveDeleteGraceDays ?? 14)]) }}</li>
                <li>{{ __('本格利用はスタンダードへ') }}</li>
              </ul>
              @include('partials.lp-apply-cta', [
                'href' => '/apply?plan=light',
                'event' => 'cta.plan.light',
                'class' => 'lp-btn lp-btn-ghost lp-plan-cta',
                'label' => __('ライト（お試し）を申請する'),
                'plan' => 'light',
              ])
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
              @include('partials.lp-apply-cta', [
                'href' => '#contact',
                'event' => 'cta.plan.dedicated',
                'class' => 'lp-btn lp-btn-ghost lp-plan-cta',
                'label' => __('専用環境の相談'),
                'plan' => 'dedicated',
              ])
            </div>
          </article>
        </div>
        <p class="hint lp-plans-note">{{ __('個人向けは運営の共有インスタンスです。ライトはお試し、本利用はスタンダードです。家族向けの管理者契約はテナント、サーバー分離は専用です。') }}</p>
      </section>

            <section class="lp-section" id="flow" aria-labelledby="landing-flow-title">
        <h2 id="landing-flow-title">{{ __('Sa2 Plusの利用方法') }}</h2>
        <p class="lp-section-lead">{{ __('まずはプランを選んで利用申請。メールの案内に沿って、すぐに使い始められます。') }}</p>
        <ol class="lp-flow">
          <li>
            <span class="lp-flow-num">1</span>
            <h3>{{ __('プランを選択') }}</h3>
            <p>{{ __('ライト、スタンダード、テナントなど、利用目的に合ったプランを選択します。') }}</p>
          </li>
          <li>
            <span class="lp-flow-num">2</span>
            <h3>{{ __('利用申請') }}</h3>
            <p>{{ __('必要事項を入力して利用を申請します。') }}</p>
          </li>
          <li>
            <span class="lp-flow-num">3</span>
            <h3>{{ __('登録メールを確認') }}</h3>
            <p>{{ __('審査・確認後、登録したメールアドレスに案内が届きます。スタンダード／テナントは運営確認、ライトは自動審査のうえメールが届きます。') }}</p>
          </li>
          <li>
            <span class="lp-flow-num">4</span>
            <h3>{{ __('Sa2 Plusを利用開始') }}</h3>
            <p>{{ __('案内に従って登録を完了すると、Sa2 Plusを利用できます。') }}</p>
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

            <section class="lp-section" id="faq" aria-labelledby="landing-faq-title">
        <h2 id="landing-faq-title">{{ __('よくある質問') }}</h2>
        <div class="lp-faq">
          <details>
            <summary>{{ __('Sa2 Plusとは何ですか？') }}</summary>
            <p>{{ __('Todo・予定・メモ・写真・メール・メッセージなどをまとめて管理できるオールインワンサービスです。') }}</p>
          </details>
          <details>
            <summary>{{ __('無料で利用できますか？') }}</summary>
            <p>{{ __('はい。ライトプランでお試しいただけます。') }}</p>
          </details>
          <details>
            <summary>{{ __('Todo管理はできますか？') }}</summary>
            <p>{{ __('はい。Todo一覧やカレンダーを利用してタスクを管理できます。') }}</p>
          </details>
          <details>
            <summary>{{ __('写真を保存できますか？') }}</summary>
            <p>{{ __('はい。Photos機能で写真を管理できます。') }}</p>
          </details>
          <details>
            <summary>{{ __('AI相談はできますか？') }}</summary>
            <p>{{ __('はい。生活の相談、料理、カレンダーなどについてAIに相談できます。') }}</p>
          </details>
          <details>
            <summary>{{ __('路線検索はできますか？') }}</summary>
            <p>{{ __('はい。地図・路線検索機能を利用できます。路線検索は NAVITIME の経路検索に対応しています。') }}</p>
          </details>
          <details>
            <summary>{{ __('家族で利用できますか？') }}</summary>
            <p>{{ __('はい。家族や小規模組織向けのテナント契約を用意しています。') }}</p>
          </details>
          <details>
            <summary>{{ __('専用サーバーを利用できますか？') }}</summary>
            <p>{{ __('はい。専用インスタンスをご用意しています。') }}</p>
          </details>
          <details>
            <summary>{{ __('どの端末で使えますか？') }}</summary>
            <p>{{ __('PCとスマートフォンの両方で利用できます。日常の管理を、同じデータで続けられます。') }}</p>
          </details>
          <details>
            <summary>{{ __('後からプラン変更できますか？') }}</summary>
            <p>{{ __('はい。まずはライト（無料）で試して、必要になったらスタンダードへ移行できます。家族・小規模で代表者の管理が必要ならテナント契約をご検討ください。') }}</p>
          </details>
          <details open>
            <summary>{{ __('最初から専用環境を契約すべきですか？') }}</summary>
            <p>{{ __('多くの場合は不要です。本利用はスタンダード、家族で管理者が必要ならテナント契約、サーバー分離が必要なら専用へ。ライトは短期間のお試しです。') }}</p>
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
            <summary>{{ __('ライト（お試し）の条件は？') }}</summary>
            <p>{{ __('約:gbGB・利用目的の記入必須・週:cap人まで。一時メールや目的不明は自動でお断りします。約:warn日ログインがない場合は警告メールを送り、さらに:grace日応答がなければ削除します。本格利用はスタンダードをご利用ください。', [
              'gb' => (int) ($lightQuotaGb ?? 20),
              'cap' => (int) ($lightWeeklyCap ?? 50),
              'warn' => (int) ($lightInactiveWarnDays ?? 90),
              'grace' => (int) ($lightInactiveDeleteGraceDays ?? 14),
            ]) }}</p>
          </details>
          <details>
            <summary>{{ __('招待コードがないと使えませんか？') }}</summary>
            <p>{{ __('いいえ。トップの「利用申請」から申し込めます。スタンダード／テナントは運営確認後、ライトは自動審査後に登録メールが届きます。招待コードは、運営が直接渡す場合の別経路です。') }}</p>
          </details>
        </div>
      </section>

      <section class="lp-section" id="start" aria-labelledby="landing-start-title">
        <h2 id="landing-start-title">{{ __('sa2-plusを始める') }}</h2>
        <p class="lp-section-lead">{{ __('Todo、予定、メモ、写真、メール、AI相談など、毎日の情報をひとつの場所にまとめてみませんか？') }}</p>
        <p>{{ __('まずはライトプランでSa2 Plusをお試しいただけます。') }}</p>
        <p class="lp-cta">
          @include('partials.lp-apply-cta', [
            'href' => '/apply?plan=light',
            'event' => 'cta.plan.light',
            'class' => 'lp-btn lp-btn-primary',
            'label' => __('無料で試す'),
            'plan' => 'light',
          ])
          <a href="#pricing" class="lp-btn lp-btn-ghost">{{ __('料金プランを見る') }}</a>
          <a href="#contact" class="lp-btn lp-btn-ghost">{{ __('お問い合わせ') }}</a>
        </p>
      </section>

      <section class="lp-section lp-contact" id="contact" hidden aria-labelledby="landing-contact-title">
        <h2 id="landing-contact-title">{{ __('お問い合わせ') }}</h2>
        <p class="lp-section-lead">{{ __('導入や専用インスタンスのご相談は、下のメールアドレスへご連絡ください。') }}</p>
        <div class="lp-contact-card">
          @if(!empty($contactEmail))
            <p class="lp-contact-email">
              <span class="lp-contact-label">{{ __('連絡先メール') }}</span>
              <a href="mailto:{{ $contactEmail }}?subject={{ rawurlencode('Sa2 Plus お問い合わせ') }}">{{ $contactEmail }}</a>
            </p>
            <p class="lp-cta lp-contact-actions">
              <a href="mailto:{{ $contactEmail }}?subject={{ rawurlencode('Sa2 Plus お問い合わせ') }}" class="lp-btn lp-btn-primary">{{ __('メールアプリで問い合わせる') }}</a>
              <a href="/tokushoho" class="lp-btn lp-btn-ghost">{{ __('特商法表記を見る') }}</a>
            </p>
            <p class="hint">{{ __('すでにご利用中の方は、ログイン後のアプリ内「お問い合わせ」フォームからも送れます。') }}</p>
            <p class="lp-cta lp-contact-actions">
              <a href="/login" class="lp-btn lp-btn-ghost">{{ __('ログインして問い合わせる') }}</a>
            </p>
          @else
            <p class="hint">{{ __('公開用の連絡先メールは準備中です。特商法表記の連絡先もあわせてご確認ください。') }}</p>
            <p class="lp-cta lp-contact-actions">
              <a href="/tokushoho" class="lp-btn lp-btn-primary">{{ __('特商法表記を見る') }}</a>
              <a href="/login" class="lp-btn lp-btn-ghost">{{ __('ログインして問い合わせる') }}</a>
            </p>
          @endif
        </div>
      </section>
    </main>

    <section class="lp-banner" aria-labelledby="landing-banner-title">
      <div class="lp-banner-inner">
        <div>
          <h2 id="landing-banner-title">{{ __('sa2-plusを始める') }}</h2>
          <p>{{ __('まずはライトプランでお試しいただけます。') }}</p>
        </div>
        <p class="lp-cta">
          @include('partials.lp-apply-cta', [
            'href' => '/apply?plan=light',
            'event' => 'cta.plan.light',
            'class' => 'lp-btn lp-btn-on-dark',
            'label' => __('無料で試してみる'),
            'plan' => 'light',
          ])
          <a href="#pricing" class="lp-btn lp-btn-on-dark">{{ __('料金プランを見る') }}</a>
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

    <script>
      (() => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        document.addEventListener('click', (event) => {
          const link = event.target.closest('[data-stat-event]');
          if (!link) return;
          const name = link.getAttribute('data-stat-event');
          if (!name) return;
          const body = new FormData();
          body.append('event', name);
          if (token) body.append('_token', token);
          try {
            if (navigator.sendBeacon) {
              navigator.sendBeacon('/stats/hit', body);
            } else {
              fetch('/stats/hit', { method: 'POST', body, credentials: 'same-origin', keepalive: true });
            }
          } catch (_) {}
        }, true);
      })();
    </script>
    <script>
      (() => {
        const section = document.getElementById('contact');
        if (!section) return;

        const openContact = () => {
          section.hidden = false;
          section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        document.addEventListener('click', (event) => {
          const link = event.target.closest('a[href="#contact"]');
          if (!link) return;
          event.preventDefault();
          if (history.replaceState) {
            history.replaceState(null, '', '#contact');
          } else {
            location.hash = 'contact';
          }
          openContact();
        });

        if (location.hash === '#contact') {
          openContact();
        }
      })();
    </script>
  </body>
</html>
