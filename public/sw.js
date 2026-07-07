self.addEventListener('push', (event) => {
  let data = { title: 'ToDo リマインダー', body: '', url: '/todos', silent: false }
  try {
    if (event.data) data = { ...data, ...event.data.json() }
  } catch {
    // ignore
  }

  const silent = data.silent === true
  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      silent,
      vibrate: silent ? [] : [300, 100, 300, 100, 300],
      data: { url: data.url || '/todos' }
    })
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = event.notification.data?.url || '/todos'
  event.waitUntil(clients.openWindow(url))
})
