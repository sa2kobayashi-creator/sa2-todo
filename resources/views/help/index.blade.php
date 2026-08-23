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
      @if(!empty($canSettings))
        @include('partials.settings-subnav', ['active' => 'help'])
      @endif
      <div class="panel">
        <h1>{{ __('ヘルプ') }}</h1>
        <p class="hint">{{ __('Sa2 Plus の基本的な使い方です。権限やメニューはアカウントごとに異なります。') }}</p>

        @if(($htmlLang ?? app()->getLocale()) === 'en')
          <section class="help-section" style="line-height:1.7;">
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">Getting started</h2>
            <ul>
              <li>Open <strong>Dashboard</strong> for today’s overview and shortcuts.</li>
              <li>Use the bottom bar (phone) or header (web) to switch features.</li>
              <li>Personal links (Google Calendar, LINE, Messenger) are on <a href="/mypage">My Page</a>.</li>
            </ul>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">Todos &amp; notes</h2>
            <ul>
              <li>Todos support calendar/list views, reminders, and group sharing when available.</li>
              <li>Notes can be pinned, archived, and filtered by category or date.</li>
            </ul>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">Photos</h2>
            <ul>
              <li>Each account has a free storage allowance. Uploads stop when the free quota is full (unless paid overage is enabled later).</li>
              <li>Albums can be private or shared with your groups.</li>
            </ul>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">Groups &amp; messages</h2>
            <ul>
              <li>Join groups by invite/acceptance. You cannot browse every user’s email.</li>
              <li>Message delivery depends on linked channels (LINE / Messenger) configured by an admin.</li>
            </ul>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">Account</h2>
            <ul>
              <li><a href="/mypage">My Page</a>: profile, password, integrations, data export, account deletion.</li>
              <li>Export downloads JSON metadata (photo/music binary files are not included).</li>
              <li>Need a menu or higher role? Ask an administrator.</li>
            </ul>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">Legal</h2>
            <p><a href="/terms">{{ __('利用規約') }}</a> · <a href="/privacy">{{ __('プライバシーポリシー') }}</a> · <a href="/about">{{ __('Sa2 Plus について') }}</a></p>
          </section>
        @else
          <section class="help-section" style="line-height:1.7;">
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">はじめての方へ</h2>
            <ul>
              <li><strong>ダッシュボード</strong>で今日の予定やショートカットを確認できます。</li>
              <li>スマホは画面下のメニュー、PCは上部メニューで機能を切り替えます。</li>
              <li>Googleカレンダー／LINE／Messenger の個人連携は <a href="/mypage">マイページ</a> から行います。</li>
            </ul>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">Todo・メモ</h2>
            <ul>
              <li>Todo はカレンダー／一覧表示、リマインダ、（権限があれば）グループ共有が使えます。</li>
              <li>メモはピン留め・アーカイブ、カテゴリや日付での絞り込みができます。</li>
            </ul>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">Photos</h2>
            <ul>
              <li>ユーザーごとに無料のストレージ枠があります。枠を超えると追加アップロードが止まることがあります。</li>
              <li>アルバムは非公開、または所属グループへの共有ができます。</li>
            </ul>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">グループ・メッセージ</h2>
            <ul>
              <li>グループは招待／承諾で参加します。全ユーザーのメール一覧は出ません。</li>
              <li>LINE／Messenger 通知は、管理者がチャネル設定したうえで、各自がマイページで連携します。</li>
            </ul>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">アカウント</h2>
            <ul>
              <li><a href="/mypage">マイページ</a>：プロフィール、パスワード、連携、データエクスポート、退会。</li>
              <li>エクスポートは JSON（写真・音楽のファイル本体は含みません）。</li>
              <li>使いたいメニューや権限の変更は管理者に依頼してください。</li>
            </ul>
            <h2 style="font-size:1.05rem;margin-top:1.25rem;">関連ページ</h2>
            <p><a href="/terms">{{ __('利用規約') }}</a> · <a href="/privacy">{{ __('プライバシーポリシー') }}</a> · <a href="/about">{{ __('Sa2 Plus について') }}</a></p>
          </section>
        @endif
      </div>
    </main>
    @include('partials.mobile-nav', ['active' => 'help'])
  </body>
</html>
