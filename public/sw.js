/* Sa2 Plus app PWA - installability only; do not cache authenticated HTML/API */
const CACHE = 'sa2-app-shell-v2'
/* 共有シートから受け取った音声の一時置き場。/music/share の画面が読み出して送信する */
const SHARE_CACHE = 'sa2-shared-audio-v1'
const SHARE_TARGET_PATH = '/music/share'
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
          .filter((key) => key !== CACHE && key !== SHARE_CACHE)
          .map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  )
})

/* 共有された音声を Cache Storage に置き換える。Cookie が乗らない POST を
   ここで受け止め、以降は同一オリジンの GET 画面から送信させる。 */
async function stashSharedAudio(request) {
  const cache = await caches.open(SHARE_CACHE)
  const previous = await cache.keys()
  await Promise.all(previous.map((key) => cache.delete(key)))

  let form
  try {
    form = await request.formData()
  } catch (_) {
    return 0
  }

  const files = form.getAll('tracks').filter((file) => file && typeof file.size === 'number' && file.size > 0)
  let stored = 0
  for (const file of files) {
    stored += 1
    const headers = new Headers()
    headers.set('Content-Type', file.type || 'audio/mpeg')
    headers.set('X-Shared-Name', encodeURIComponent(file.name || `track-${stored}.mp3`))
    await cache.put(`/__shared-audio/${stored}`, new Response(file, { headers }))
  }

  return stored
}

self.addEventListener('fetch', (event) => {
  const req = event.request

  let url
  try {
    url = new URL(req.url)
  } catch (_) {
    return
  }
  if (url.origin !== self.location.origin) return

  if (req.method === 'POST' && url.pathname === SHARE_TARGET_PATH) {
    event.respondWith(
      stashSharedAudio(req)
        .then((count) => Response.redirect(`${SHARE_TARGET_PATH}?shared=${count}`, 303))
        .catch(() => Response.redirect(`${SHARE_TARGET_PATH}?shared=0`, 303))
    )
    return
  }

  if (req.method !== 'GET') return

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

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch (_) {
    data = {}
  }

  const title = data.title || 'Sa2 Plus'
  const options = {
    body: data.body || '新しい通知があります。',
    icon: '/icons/pwa-192.png',
    badge: '/icons/pwa-192.png',
    tag: data.tag || 'sa2-notification',
    renotify: true,
    data: { url: data.url || '/messages' },
    vibrate: [180, 90, 180, 300, 180],
  }

  event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const targetUrl = new URL(
    (event.notification.data && event.notification.data.url) || '/messages',
    self.location.origin
  ).href

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if (client.url === targetUrl && 'focus' in client) return client.focus()
      }
      if (self.clients.openWindow) return self.clients.openWindow(targetUrl)
      return undefined
    })
  )
})
