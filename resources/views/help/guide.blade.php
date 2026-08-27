<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('このアプリの使用方法') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'help-guide'])
    <main class="page-main page-main-narrow">
      @include('help.partials.topics', ['helpTopic' => 'guide'])

      <div class="panel">
        <h1>{{ __('このアプリの使用方法') }}</h1>
        <p class="hint">{{ __('最初の準備から、日常の使い方まで、管理者向けの手順です。') }}</p>

        @if(($htmlLang ?? app()->getLocale()) === 'en')
          <section class="help-section">
            <h2>1. First-time setup</h2>
            <ol>
              <li>Open <a href="/admin/users">User management</a> and set an <strong>invite code</strong> if people should self-register. Leave it empty to close self-registration and add users yourself.</li>
              <li>Create accounts with the right role (Admin / Standard / Light). Extra menus can be ticked per user.</li>
              <li>Under <a href="/settings?section=enhance">API Settings</a>, add Google Maps and Google Calendar OAuth (Client ID / Secret). Users then connect their own Google account on My Page.</li>
              <li>Under <a href="/settings?section=storage">Storage</a>, confirm R2 / B2 (and related) credentials if photos should upload. For personal sales, save capacity mode “Store originals on B2”. Thumbnails stay on R2; new originals go to B2.</li>
              <li>Under <a href="/settings?section=ai">AI Settings</a>, add DeepL for translation, ChatGPT / Gemini for voice entry, and Cloudflare Workers AI for the life guide. Usage fees are yours after you save keys.</li>
            </ol>

            <h2>2. Users, groups, mail</h2>
            <ul>
              <li><a href="/admin/users">User management</a> — roles, menus, invite code.</li>
              <li><a href="/admin/groups">Group management</a> — approve groups and optional extra menus for members.</li>
              <li><a href="/admin/mail-requests">Mailbox requests</a> — included in Standard; add-on on Light. The operator creates the address and password in Lolipop by hand, tells the user the password, then marks it provisioned.</li>
            </ul>

            <h2>3. Calendar, maps, messaging</h2>
            <ul>
              <li>Calendar: save the OAuth app in API Settings, then each person taps Connect on <a href="/mypage">My Page</a>. Work ToDos can create Google Calendar events with Meet.</li>
              <li>Maps / Transit: need the Maps key in API Settings.</li>
              <li>LINE / Messenger: save the channel in <a href="/settings?section=integration">Integrations</a>, then each person links their account on My Page.</li>
            </ul>

            <h2>4. Storage and usage</h2>
            <ul>
              <li><a href="/settings?section=usage">Usage</a> — per-user photo storage and integration counts.</li>
              <li><a href="/admin/storage-archive">Storage monitor</a> — whole-server R2 / mail / database. Overflow is moved by the server’s scheduled job (cron), not from this screen per user.</li>
            </ul>

            <h2>5. Everyday use (for everyone)</h2>
            <ul>
              <li><strong>Dashboard</strong> — today and shortcuts. Switch Personal / Work in the header.</li>
              <li><strong>ToDo</strong> — list or calendar, reminders, optional group sharing.</li>
              <li><strong>Notes</strong> — pin, archive, translate JA ⇔ EN when DeepL is configured.</li>
              <li><strong>Photos</strong> — albums, private or shared with groups. Uploads stop when a user’s free quota is full.</li>
              <li><strong>Messages</strong> — group and DM chat. Calls need LiveKit in Integrations.</li>
              <li><strong>Life guide</strong> — everyday tips, recipes, and today’s calendar. Needs Workers AI under AI Settings.</li>
              <li>Phone: bottom bar. PC: header. Change which items appear under <a href="/settings?section=nav">Display menus</a>.</li>
            </ul>

            <h2>6. Holidays and display</h2>
            <p><a href="/settings?section=holidays">Initial setup → Holidays</a> affect the calendar. <a href="/settings?section=nav">Display menus</a> control the header and phone bar for your own account; user/group menus are set in User / Group management.</p>

            <h2>7. If something fails</h2>
            <p>Check Usage and the relevant Settings panel first. For server or operator issues, use <a href="/contact">{{ __('問い合わせ') }}</a>.</p>
            <p><a href="/help">{{ __('ヘルプ') }}</a> · <a href="/help/overview">{{ __('このアプリの概要') }}</a></p>
          </section>
        @else
          <section class="help-section">
            <h2>1. 最初にやること</h2>
            <ol>
              <li><a href="/admin/users">ユーザー管理</a>で、<strong>招待コード</strong>を決めます。入れるとそのコードを知っている人だけ自己登録できます。空にすると自己登録は閉じ、管理者がユーザーを追加します。</li>
              <li>権限（管理者／スタンダード／ライト）を選んでアカウントを作ります。ユーザーごとに追加メニューを付けられます。</li>
              <li><a href="/settings?section=enhance">初期設定 → API設定</a>で Google マップと Google カレンダー（OAuth の Client ID / Secret）を登録します。そのあと、各自が <a href="/mypage">マイページ</a> で自分の Google アカウントを連携します。</li>
              <li><a href="/settings?section=storage">初期設定 → ストレージ</a>で写真用の R2 / B2 などを確認します。個人販売では容量モード「原本はB2に置く」を保存してください。サムネは R2、新規原本は B2 です。</li>
              <li>翻訳や音声入力、生活ガイドを使うなら <a href="/settings?section=ai">初期設定 → AI</a> に DeepL、ChatGPT / Gemini、Cloudflare Workers AI を入れます。キー保存後の利用料は管理者の責任です。</li>
            </ol>

            <h2>2. ユーザー・グループ・メール</h2>
            <ul>
              <li><a href="/admin/users">ユーザー管理</a> … 権限、メニュー、招待コード。</li>
              <li><a href="/admin/groups">グループ管理</a> … グループの承認と、メンバーへの追加メニュー。</li>
              <li><a href="/admin/mail-requests">メール申請</a> … スタンダードはプランに含む。ライトは個別契約。アドレス作成とパスワード設定は運営者がロリポップで手動。利用者へパスワードを伝えたあと「作成済み」にする。</li>
            </ul>

            <h2>3. カレンダー・地図・通知</h2>
            <ul>
              <li>カレンダーは API設定に OAuth アプリを保存したあと、各自がマイページから連携します。仕事 Todo は Google カレンダーへ予定を出せます（Meet 付き）。</li>
              <li>マップ／路線検索は API設定の Maps キーが必要です。</li>
              <li>路線検索の電車・バスは <strong>NAVITIME</strong> を登録します。Google Maps Routes は日本の交通機関ルートが API の提供対象外です。未登録のときは福岡都心の内蔵ダイヤで検索します。地図の車・徒歩ルートに Google Maps Routes を使います。</li>
              <li>LINE／Messenger は <a href="/settings?section=integration">外部連携</a> でチャネルを保存し、各自がマイページでつなぎます。</li>
            </ul>

            <h2>4. ストレージと使用量</h2>
            <ul>
              <li><a href="/settings?section=usage">使用量</a> … ユーザーごとの写真容量と、外部連携の回数。</li>
              <li><a href="/admin/storage-archive">ストレージ監視</a> … サーバー全体の R2・メール・DB。あふれ分の退避はサーバーの定期処理（cron）が行います。この画面からユーザー単位で退避する操作はありません。</li>
            </ul>

            <h2>5. 日常の使い方（全員共通）</h2>
            <ul>
              <li><strong>ダッシュボード</strong> … 今日の予定とショートカット。ヘッダーの個人／仕事で切り替えます。</li>
              <li><strong>Todo</strong> … 一覧またはカレンダー、リマインダ、権限があればグループ共有。</li>
              <li><strong>メモ</strong> … ピン留め・アーカイブ。DeepL があれば日本語⇔英語の翻訳。</li>
              <li><strong>Photos</strong> … アルバム。非公開、またはグループ共有。無料枠を超えると追加アップロードが止まります。</li>
              <li><strong>メッセージ</strong> … グループと DM。通話は外部連携の LiveKit が必要です。</li>
              <li><strong>生活ガイド</strong> … 知恵・話し相手、料理、今日のカレンダー。AI 設定の Workers AI が必要です。</li>
              <li>スマホは画面下、PC は上部メニューです。自分の表示は <a href="/settings?section=nav">表示メニュー管理</a>、他人のメニューはユーザー／グループ管理です。</li>
            </ul>

            <h2>6. 休日と表示</h2>
            <p><a href="/settings?section=holidays">初期設定の休日</a>は組織全体のカレンダーに反映されます。各ユーザーは <a href="/mypage/holidays">マイページ → 休日</a> で自分専用の休日を持てます。表示メニュー管理は自分のヘッダーとスマホ下部です。</p>

            <h2>7. うまくいかないとき</h2>
            <p>まず使用量と、該当する設定画面を確認してください。サーバーや運営側の問題は <a href="/contact">{{ __('問い合わせ') }}</a> へ。</p>
            <p><a href="/help">{{ __('ヘルプ') }}</a> · <a href="/help/overview">{{ __('このアプリの概要') }}</a></p>
          </section>
        @endif
      </div>
    </main>
  </body>
</html>
