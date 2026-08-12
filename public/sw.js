/* Sa2 Plus app PWA - installability only; do not cache authenticated HTML/API */
const CACHE = 'sa2-app-shell-v1'
const PRECACHE = [
  '/manifest.webmanifest',
  '/offline.html',
  '/icons/pwa-192.png',
  '/icons/pwa-512.png',
]

function offlineResponse() {
  return caches.match('/offline.html').then((cached) => {
    if (cached) return cached
    return new Response('Offline', {
      status: 503,
      statusText: 'Service Unavailable',
      headers: { 'Content-Type': 'text/plain; charset=utf-8' },
    })
  })
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE)
          .map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  if (req.method !== 'GET') return

  let url
  try {
    url = new URL(req.url)
  } catch (_) {
    return
  }
  if (url.origin !== self.location.origin) return

  // HTML 画面遷移: 常にネットワーク。失敗時のみ静的 offline（ログイン/Photos へ落とさない）
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => offlineResponse())
    )
    return
  }

  // 静的アイコン・manifest・offline のみ cache-first
  const path = url.pathname
  const isPrecacheAsset =
    path === '/manifest.webmanifest'
    || path === '/offline.html'
    || path === '/icons/pwa-192.png'
    || path === '/icons/pwa-512.png'

  if (!isPrecacheAsset) {
    // Laravel / API / CSS / JS / Photos 等は SW が介入しない（ブラウザ既定のネットワーク）
    return
  }

  event.respondWith(
    caches.match(req).then((cached) => {
      const fetched = fetch(req).then((res) => {
        if (res && res.ok) {
          const copy = res.clone()
          caches.open(CACHE).then((cache) => cache.put(req, copy)).catch(() => {})
        }
        return res
      }).catch(() => cached || offlineResponse())

      return cached || fetched
    })
  )
})
