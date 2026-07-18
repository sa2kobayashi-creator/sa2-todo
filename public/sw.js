/* Sa2 Photos PWA service worker — cache app shell only */
const CACHE = 'sa2-photos-shell-v1'
const SHELL = [
  '/app.css',
  '/manifest.webmanifest',
  '/icons/pwa-192.png',
  '/icons/pwa-512.png',
]

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

  // HTML / API はネットワーク優先（ログイン状態を壊さない）
  if (req.mode === 'navigate' || url.pathname.startsWith('/photos') && !url.pathname.includes('.')) {
    event.respondWith(
      fetch(req).catch(() => caches.match('/photos').then((r) => r || caches.match(req)))
    )
    return
  }

  event.respondWith(
    caches.match(req).then((cached) => {
      const fetched = fetch(req).then((res) => {
        if (res && res.ok && (url.pathname.endsWith('.css') || url.pathname.startsWith('/icons/'))) {
          const copy = res.clone()
          caches.open(CACHE).then((cache) => cache.put(req, copy))
        }
        return res
      }).catch(() => cached)
      return cached || fetched
    })
  )
})
