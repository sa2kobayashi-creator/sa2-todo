/* Sa2 Photos PWA service worker - cache app shell only */
const CACHE = 'sa2-photos-shell-v4'
const SHELL = [
  '/photos-start.html',
  '/app.css',
  '/manifest.webmanifest',
  '/icons/pwa-192.png',
  '/icons/pwa-512.png',
]

function offlineResponse(message = 'Offline') {
  return new Response(message, {
    status: 503,
    statusText: 'Service Unavailable',
    headers: { 'Content-Type': 'text/plain; charset=utf-8' },
  })
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting())
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key)))
    ).then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  if (req.method !== 'GET') return

  const url = new URL(req.url)
  if (url.origin !== self.location.origin) return

  // 画面遷移のみオフライン時にシェルへフォールバック（必ず Response を返す）
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(async () => {
        const shell = await caches.match('/photos-start.html')
        return shell || offlineResponse('Offline')
      })
    )
    return
  }

  // /photos 配下の動的API・画像は SW で触らない（ログイン・鮮明化・file 配信を壊さない）
  if (url.pathname.startsWith('/photos')) {
    return
  }

  // シェル資産のみ cache-first
  const isShellAsset =
    url.pathname.endsWith('.css')
    || url.pathname.startsWith('/icons/')
    || url.pathname.endsWith('.webmanifest')
    || url.pathname.endsWith('photos-start.html')

  if (!isShellAsset) return

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
