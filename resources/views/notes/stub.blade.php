<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
<head><meta charset="UTF-8"><title>{{ __('準備中') }} - {{ config('app.name') }}</title><link rel="stylesheet" href="{{ asset('app.css') }}"></head>
<body>
@include('partials.header', ['active' => 'notes'])
<main class="page-main"><div class="panel"><h2>{{ __('メモ') }}</h2><p class="hint">{{ __('Laravel 移行中です。次のフェーズで実装予定です。') }}</p><a href="/todos">{{ __('Todo 一覧へ') }}</a></div></main>
</body></html>
