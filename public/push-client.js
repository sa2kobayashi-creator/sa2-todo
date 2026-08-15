(function () {
  const btn = document.getElementById('push-subscribe-btn')
  const status = document.getElementById('push-status')
  if (!btn) return

  function pushUnsupportedReason() {
    if (!window.isSecureContext) {
      return 'プッシュ通知には HTTPS が必要です。'
    }
    if (!('serviceWorker' in navigator)) {
      return 'このブラウザは Service Worker に非対応です。'
    }
    if (!('PushManager' in window)) {
      return 'このブラウザはプッシュ通知に非対応です。PC の Chrome をお試しください。'
    }
    return null
  }

  function setStatus(text) {
    if (status) status.textContent = text
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
    const raw = atob(base64)
    const output = new Uint8Array(raw.length)
    for (let i = 0; i < raw.length; ++i) output[i] = raw.charCodeAt(i)
    return output
  }

  async function registration() {
    const reg = await navigator.serviceWorker.register('/sw.js')
    await navigator.serviceWorker.ready
    return reg
  }

  async function refreshButton() {
    const reason = pushUnsupportedReason()
    if (reason) {
      btn.disabled = true
      btn.title = reason
      setStatus(reason)
      return
    }
    try {
      const reg = await registration()
      const sub = await reg.pushManager.getSubscription()
      if (sub) {
        btn.textContent = '登録済み'
        setStatus('この端末で通話の着信通知を受け取れます')
      }
    } catch (_) {
      setStatus('プッシュ通知の準備に失敗しました')
    }
  }

  refreshButton()

  btn.addEventListener('click', async () => {
    const reason = pushUnsupportedReason()
    if (reason) {
      alert(reason)
      return
    }

    try {
      const perm = await Notification.requestPermission()
      if (perm !== 'granted') {
        alert('通知の許可が必要です')
        return
      }

      const keyRes = await fetch('/api/push/vapid-public-key', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      })
      if (!keyRes.ok) throw new Error('VAPID 鍵が設定されていません（.env を確認）')
      const { publicKey } = await keyRes.json()

      const reg = await registration()

      let sub = await reg.pushManager.getSubscription()
      if (!sub) {
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(publicKey)
        })
      }

      const saveRes = await fetch('/api/push/subscribe', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        credentials: 'same-origin',
        body: JSON.stringify(sub.toJSON())
      })
      if (!saveRes.ok) throw new Error('登録に失敗しました')

      setStatus('この端末で通話の着信通知を登録しました')
      btn.textContent = '登録済み'
      btn.disabled = true
    } catch (err) {
      alert(err instanceof Error ? err.message : 'プッシュ通知の登録に失敗しました')
    }
  })
})()
