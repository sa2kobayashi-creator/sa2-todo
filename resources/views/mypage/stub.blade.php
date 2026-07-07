<!DOCTYPE html>
<html lang="ja">
<head><meta charset="UTF-8"><title>マイページ</title><link rel="stylesheet" href="{{ asset('app.css') }}"></head>
<body>@include('partials.header', ['active' => 'mypage'])<main class="page-main"><div class="panel"><h2>マイページ</h2><p>{{ $currentUser['displayName'] ?? '' }}（{{ $currentUser['email'] ?? '' }}）</p></div></main></body></html>
