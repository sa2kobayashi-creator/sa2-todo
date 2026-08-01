<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    <title>{{ $title ?? config('app.name') }}</title>
  </head>
  <body style="margin:0;padding:24px;background:#f4f6f8;font-family:'Hiragino Sans','Yu Gothic',sans-serif;color:#171b1f;">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;">
      <p style="margin:0 0 18px;font-size:18px;font-weight:600;">{{ config('app.name') }}</p>
      {{ $slot }}
      <hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0 12px;" />
      <p style="margin:0;font-size:12px;color:#6b7280;">
        {{ __('このメールに心当たりがない場合は破棄してください。') }}
      </p>
    </div>
  </body>
</html>
