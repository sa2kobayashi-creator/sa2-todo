<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('このアプリの概要') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'help-overview'])
    <main class="page-main page-main-narrow">
      @include('help.partials.topics', ['helpTopic' => 'overview'])

      <div class="panel">
        <h1>{{ __('このアプリの概要') }}</h1>
        <p class="hint">{{ __('管理者が、このアプリで何ができ、何を任されているかをまとめています。') }}</p>

        @if(($htmlLang ?? app()->getLocale()) === 'en')
          <section class="help-section">
            <h2>What this app is</h2>
            <p>{{ config('app.name') }} brings everyday life and work into one place: a dashboard calendar, ToDos, notes, photos, messages, mail, expenses, maps, transit, translation, music, and videos. Each person signs in with their own account. Personal and Work modes switch what you see on the dashboard and ToDos.</p>

            <h2>Who does what</h2>
            <ul>
              <li><strong>Operator</strong> — runs the server. Use Settings → Contact if something is broken at the infrastructure level.</li>
              <li><strong>Administrator (you)</strong> — owns this copy of the app: users, invite code, API keys, storage, and AI usage. Fees from DeepL, Google, and similar services are the admin’s responsibility after you save keys.</li>
              <li><strong>Standard</strong> — everyday menus except Settings (Travel is off by default; you can grant it). Can create groups.</li>
              <li><strong>Light</strong> — Dashboard, ToDo, Notes, Photos, Messages, Translate, My Page. Cannot create groups unless you add menus.</li>
            </ul>

            <h2>What lives on this instance</h2>
            <ul>
              <li>API keys (Maps, Calendar OAuth, storage, DeepL, LLM, LINE, and so on) are <strong>one set for the whole app</strong>, not per user.</li>
              <li>Photos and files are stored per user. Light is about 50GB; Standard is about 200GB.</li>
              <li>The Storage monitor page shows <strong>this server as a whole</strong>, not a per-user breakdown. Per-user photo usage is under Settings → Usage.</li>
            </ul>

            <h2>What you do not need to run</h2>
            <ul>
              <li>Photo AI enhance is a preview for the operator only. It is not a customer feature.</li>
              <li>Sales quotes are operator-only.</li>
            </ul>

            <h2>Privacy in short</h2>
            <p>Users see their own data and items shared in groups they belong to. They cannot browse every email on the instance. Legal pages: <a href="/terms">{{ __('利用規約') }}</a> · <a href="/privacy">{{ __('プライバシーポリシー') }}</a>.</p>
            <p><a href="/help/guide">{{ __('このアプリの使用方法') }}</a> · <a href="/contact">{{ __('問い合わせ') }}</a></p>
          </section>
        @else
          <section class="help-section">
            <h2>このアプリは何か</h2>
            <p>{{ config('app.name') }} は、生活と仕事をひとまとめにするアプリです。ダッシュボードのカレンダー、Todo、メモ、写真、メッセージ、メール、入出金、マップ、路線、翻訳、音楽、動画を、各自のアカウントで使います。画面右上の「個人／仕事」で、ダッシュボードと Todo の見え方を切り替えられます。</p>

            <h2>誰が何をするか</h2>
            <ul>
              <li><strong>運営者</strong> … サーバー側の運用です。インフラの不具合は 設定 → 問い合わせ から連絡してください。</li>
              <li><strong>管理者（あなた）</strong> … このアプリ一式の責任者です。ユーザー、招待コード、外部 API、ストレージ、AI の設定を自分で行います。キーを保存したあとの DeepL や Google などの利用料は、管理者の責任です。</li>
              <li><strong>スタンダード</strong> … 設定以外の基本メニュー（Travel は既定オフ。必要なら付与できます）。グループの作成ができます。</li>
              <li><strong>ライト</strong> … ダッシュボード、Todo、メモ、Photos、メッセージ、翻訳、マイページが基本です。グループ作成はできません（メニューを足せば使えます）。</li>
            </ul>

            <h2>このアプリで共有されるもの</h2>
            <ul>
              <li>マップ・カレンダー OAuth・ストレージ・DeepL・LLM・LINE などの <strong>API キーはアプリ全体で1セット</strong>です（ユーザーごとではありません）。</li>
              <li>写真やファイルはユーザーごとに保存されます。ライトは目安 50GB、スタンダードは約 200GB です。</li>
              <li>ストレージ監視は <strong>このサーバー全体</strong> の使用量です。ユーザー単位の写真容量は 設定 → 使用量 で確認します。</li>
            </ul>

            <h2>管理者が扱わないもの</h2>
            <ul>
              <li>写真の AI 鮮明化は運営側の試作機能です。顧客向け機能ではありません。</li>
              <li>見積画面は運営専用です。</li>
            </ul>

            <h2>データの見え方</h2>
            <p>各ユーザーは自分のデータと、参加しているグループで共有したものだけを見ます。全ユーザーのメール一覧は出ません。規約は <a href="/terms">{{ __('利用規約') }}</a> · <a href="/privacy">{{ __('プライバシーポリシー') }}</a> です。</p>
            <p><a href="/help/guide">{{ __('このアプリの使用方法') }}</a> · <a href="/contact">{{ __('問い合わせ') }}</a></p>
          </section>
        @endif
      </div>
    </main>
  </body>
</html>
