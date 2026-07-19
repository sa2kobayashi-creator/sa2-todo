<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
<head><meta charset="UTF-8"><title>{{ __('マイページ') }}</title><link rel="stylesheet" href="{{ asset('app.css') }}"></head>
<body>@include('partials.header', ['active' => 'mypage'])<main class="page-main"><div class="panel"><h2>{{ __('マイページ') }}</h2><p>{{ $currentUser['displayName'] ?? '' }}（{{ $currentUser['email'] ?? '' }}）</p></div></main></body></html>
