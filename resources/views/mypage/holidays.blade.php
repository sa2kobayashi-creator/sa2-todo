<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#1a73e8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('休日設定') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'mypage'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <p class="hint"><a href="/mypage">{{ __('マイページ') }}</a></p>
      <h1>{{ __('自分の休日') }}</h1>
      <p class="hint">{{ __('ここで設定した休日は、あなたのカレンダーと Todo にだけ反映されます。他の人には見えません。') }}</p>

      @include('settings.partials.holidays', [
        'holidayBase' => '/mypage',
        'prevHolidayHref' => '/mypage/holidays?year='.$prevHolidayYear,
        'nextHolidayHref' => '/mypage/holidays?year='.$nextHolidayYear,
        'holidayMasterHint' => __('日本・フィリピンの祝日を取り込めます。あなた専用の休日も追加できます。'),
      ])
    </main>
  </body>
</html>
