<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('ヘルプ') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'help'])
    <main class="page-main page-main-narrow">
      @include('help.partials.topics', ['helpTopic' => 'basic'])

      <div class="panel">
        <h1>{{ __('ヘルプ') }}</h1>
        <p class="hint">{{ __('画面の基本的な使い方です。権限やメニューはアカウントごとに異なります。') }}</p>

        @if(($htmlLang ?? app()->getLocale()) === 'en')
          <section class="help-section">
            <h2>Getting started</h2>
            <ul>
              <li>Open <strong>Dashboard</strong> for today and shortcuts. Switch <strong>Personal / Work</strong> in the header.</li>
              <li>Phone: bottom bar. PC: header. Extra items are in the ⋮ menu.</li>
              <li>Google Calendar, LINE, and Messenger are linked on <a href="/mypage">My Page</a> (your own account, not the app login).</li>
            </ul>
            <h2>ToDos &amp; notes</h2>
            <ul>
              <li>ToDos have list and calendar views, reminders, and group sharing when your role allows it.</li>
              <li>Set your own holidays on <a href="/mypage/holidays">My Page → Holidays</a>. They apply only to your calendar and ToDos.</li>
              <li>Notes can be pinned, archived, and filtered. If translation is set up, use the translate button (Japanese ⇔ English).</li>
            </ul>
            <h2>Photos</h2>
            <ul>
              <li>Each account has a storage allowance (about 20GB on Light trial, 200GB on Standard). Uploads stop when that quota is full. Light has a weekly intake limit; after about 90 days without login we warn, then delete if there is no response.</li>
              <li>Albums can be private or shared with groups you belong to.</li>
            </ul>
            <h2>Messages, mail, and more</h2>
            <ul>
              <li>Groups are join-by-invite. You cannot browse every user’s email.</li>
              <li>A @sa2-plus.com address is included in Standard. On Light it is a separate add-on. Connecting Gmail stays free. The operator creates the address and password by hand and sends you the password separately.</li>
              <li>LINE / Messenger reminders need an admin to save the channel, then you link on My Page.</li>
              <li>Other menus (household budget, maps, transit, translate, music, videos, mail) appear when your account includes them.</li>
            </ul>
            <h2>Account</h2>
            <ul>
              <li><a href="/mypage">My Page</a>: profile, password, integrations, data export, account deletion.</li>
              <li>Export is JSON metadata (photo/music files themselves are not included).</li>
              <li>Need another menu or a higher role? Ask an administrator.</li>
            </ul>
            <h2>Legal</h2>
            <p><a href="/terms">{{ __('利用規約') }}</a> · <a href="/privacy">{{ __('プライバシーポリシー') }}</a> · <a href="/about">{{ __('Sa2 Plus について') }}</a></p>
          </section>
        @else
          <section class="help-section">
            <h2>はじめての方へ</h2>
            <ul>
              <li><strong>ダッシュボード</strong>で今日の予定とショートカットを見ます。ヘッダーの<strong>個人／仕事</strong>で表示を切り替えられます。</li>
              <li>スマホは画面下のメニュー、PCは上部メニューです。入りきらない項目は「⋮」にあります。</li>
              <li>Googleカレンダー／LINE／Messenger の個人連携は <a href="/mypage">マイページ</a> です（このアプリのログインとは別です）。</li>
            </ul>
            <h2>Todo・メモ</h2>
            <ul>
              <li>Todo は一覧またはカレンダー、リマインダ、権限があればグループ共有が使えます。</li>
              <li>自分の休日は <a href="/mypage/holidays">マイページ → 休日</a> で設定します。カレンダーと Todo の定休日にだけ反映され、他の人には見えません。</li>
              <li>メモはピン留め・アーカイブ、カテゴリや日付での絞り込みができます。翻訳が使える場合は、日本語⇔英語の「訳」ボタンがあります。</li>
            </ul>
            <h2>Photos</h2>
            <ul>
              <li>ユーザーごとに保管枠があります（ライトは約 20GB・お試し、スタンダードは約 200GB）。枠を超えると追加アップロードが止まることがあります。ライトは週の受付上限があり、約90日未ログインで警告、応答がなければ削除されます。</li>
              <li>アルバムは非公開、または所属グループへの共有ができます。</li>
            </ul>
            <h2>メッセージ・メール・その他</h2>
            <ul>
              <li>グループは招待／承諾で参加します。全ユーザーのメール一覧は出ません。</li>
              <li>@sa2-plus.com のメールアドレスはスタンダード契約に含まれます。ライトは個別契約です。Gmail などの外部接続はこれまでどおり無料です。アドレス作成とパスワード設定は運営者が手動で行い、パスワードは別途お知らせします。</li>
              <li>LINE／Messenger 通知は、管理者がチャネルを設定したあと、各自がマイページで連携します。</li>
              <li>家計簿、マップ、路線、翻訳、音楽、動画、メールなどは、アカウントにメニューがあるとき表示されます。</li>
            </ul>
            <h2>アカウント</h2>
            <ul>
              <li><a href="/mypage">マイページ</a>：プロフィール、パスワード、連携、データエクスポート、退会。</li>
              <li>エクスポートは JSON です（写真・音楽のファイル本体は含みません）。</li>
              <li>使いたいメニューや権限の変更は、管理者に依頼してください。</li>
            </ul>
            <h2>関連ページ</h2>
            <p><a href="/terms">{{ __('利用規約') }}</a> · <a href="/privacy">{{ __('プライバシーポリシー') }}</a> · <a href="/about">{{ __('Sa2 Plus について') }}</a></p>
          </section>
        @endif
      </div>
    </main>
  </body>
</html>
