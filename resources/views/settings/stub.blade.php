<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
<head><meta charset="UTF-8"><title>{{ __('準備中') }} - Sa2 ToDo</title><link rel="stylesheet" href="{{ asset('app.css') }}"></head>
<body>
@include('partials.header', ['active' => 'settings', 'settingsSection' => request('section', 'holidays')])
<main class="page-main"><div class="panel"><h2>{{ __('設定') }}</h2><p class="hint">{{ __('Laravel 移行中です。次のフェーズで実装予定です。') }}</p></div></main>
</body></html>
