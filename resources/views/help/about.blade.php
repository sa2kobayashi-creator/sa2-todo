<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('Sa2 Plus について') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'about'])
    <main class="page-main page-main-narrow">
      <div class="panel">
        <h1>{{ __('Sa2 Plus について') }}</h1>
        <p class="hint">{{ __('生活・予定・メモ・写真などをまとめて扱う個人向けアプリです。') }}</p>

        <dl class="profile-dl">
          <dt>{{ __('アプリ名') }}</dt>
          <dd>{{ $appName }}</dd>
          <dt>{{ __('バージョン') }}</dt>
          <dd>{{ $appVersion }}</dd>
          <dt>{{ __('フレームワーク') }}</dt>
          <dd>Laravel {{ $laravelVersion }}</dd>
          <dt>{{ __('PHP') }}</dt>
          <dd>{{ $phpVersion }}</dd>
          <dt>{{ __('タイムゾーン') }}</dt>
          <dd>{{ $timezone }}</dd>
          <dt>{{ __('言語') }}</dt>
          <dd>{{ $locale }}</dd>
          @if(!empty($showEnvironment))
            <dt>{{ __('実行環境') }}</dt>
            <dd>{{ $environment }}</dd>
          @endif
        </dl>

        <p class="hint" style="margin-top:1rem;">
          {{ __('不具合や権限の依頼は、アプリ管理者へ連絡してください。') }}
          <a href="/help">{{ __('ヘルプ') }}</a>
          ·
          <a href="/terms">{{ __('利用規約') }}</a>
          ·
          <a href="/privacy">{{ __('プライバシーポリシー') }}</a>
        </p>
      </div>
    </main>
    @include('partials.mobile-nav', ['active' => 'about'])
  </body>
</html>
