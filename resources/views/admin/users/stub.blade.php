<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
<head><meta charset="UTF-8"><title>{{ __('ユーザー管理') }}</title><link rel="stylesheet" href="{{ asset('app.css') }}"></head>
<body>@include('partials.header', ['active' => 'admin'])<main class="page-main"><div class="panel"><h2>{{ __('ユーザー管理') }}</h2><p class="hint">{{ __('移行中') }}</p></div></main></body></html>
