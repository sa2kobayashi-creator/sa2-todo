{{-- 端末の言語が日本語以外だと Chrome が自動翻訳を始め、JS が組み替える DOM と衝突して真っ白になる。
     日英の切替はアプリ内の言語スイッチで行うため、ブラウザ側の翻訳は止める。 --}}
<meta name="google" content="notranslate" />
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32.png') }}?v=9" />
<link rel="icon" type="image/png" href="{{ asset('icons/app-icon.png') }}?v=9" />
<link rel="apple-touch-icon" href="{{ asset('icons/pwa-192.png') }}?v=9" />
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}" />
<meta name="theme-color" content="#1a1f24" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
<meta name="application-name" content="{{ config('app.name', 'Sa2 Plus') }}" />
<meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Sa2 Plus') }}" />
<script>
  // 全体 PWA: 認証 HTML / API はキャッシュしない最小 SW を全ページで登録
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register(@json(asset('sw.js')), { scope: '/' })
        .then((reg) => reg.update().catch(() => {}))
        .catch(() => {})
    })
  }
</script>
