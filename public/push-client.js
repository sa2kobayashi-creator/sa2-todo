(function () {
  const btn = document.getElementById('push-subscribe-btn')
  const status = document.getElementById('push-status')
  const hint = document.getElementById('push-secure-hint')
  const mobileBanner = document.getElementById('mobile-push-unavailable')
  const registerHint = document.getElementById('push-register-hint')
  const caGuide = document.getElementById('ca-install-guide')
  if (!btn) return

  const isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)
  const isAndroid = /Android/i.test(navigator.userAgent)

  function pushUnsupportedReason() {
    if (isMobile) {
      return (
        'スマホ（特に Xiaomi / Android 14）では、開発環境のプッシュ通知は OS の制限で使えません。\n\n' +
        'ToDo 登録時に「LINE通知」を選んでください。\n' +
        '設定 → LINE連携 で .env を設定します。'
      )
    }
    if (!window.isSecureContext) {
      return (
        'プッシュ通知には HTTPS が必要です。\n\n' +
        'PC では https://localhost:3443 で開いてください。'
      )
    }
    if (!('serviceWorker' in navigator)) {
      return 'このブラウザは Service Worker に非対応です。'
    }
    if (!('PushManager' in window)) {
      return 'このブラウザはプッシュ通知に非対応です。PC の Chrome をお試しください。'
    }
    return null
  }

  function refreshHint() {
    const reason = pushUnsupportedReason()
    const mobileBlocked = isMobile

    if (mobileBanner) mobileBanner.hidden = !mobileBlocked
    if (mobileBlocked && btn) {
      btn.disabled = true
      btn.title = 'スマホでは LINE 通知をご利用ください'
    }
    if (registerHint && mobileBlocked) {
      registerHint.textContent = 'スマホでは LINE 通知をご利用ください（下のボタンは PC 専用です）。'
    }
    if (caGuide) caGuide.hidden = mobileBlocked
    if (hint) {
      hint.hidden = mobileBlocked || !reason || !reason.includes('HTTPS')
      if (!hint.hidden) {
        hint.textContent = 'HTTPS（:3443）で開いてから登録してください。'
      }
    }
    if (status && mobileBlocked) {
      status.textContent = 'スマホではプッシュ登録不可 — LINE 通知を推奨'
    } else if (status && reason && !window.isSecureContext) {
      status.textContent = 'HTTP ではプッシュ通知を登録できません'
    }
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
    const raw = atob(base64)
    const output = new Uint8Array(raw.length)
    for (let i = 0; i < raw.length; ++i) output[i] = raw.charCodeAt(i)
    return output
  }

  refreshHint()

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

      const keyRes = await fetch('/api/push/vapid-public-key')
      if (!keyRes.ok) throw new Error('VAPID 鍵が設定されていません（.env を確認）')
      const { publicKey } = await keyRes.json()

      let reg
      try {
        reg = await navigator.serviceWorker.register('/sw.js')
        await navigator.serviceWorker.ready
      } catch (swErr) {
        const msg = swErr instanceof Error ? swErr.message : String(swErr)
        if (/ssl|certificate|security/i.test(msg)) {
          throw new Error('SSL 証明書エラー: PC の https://localhost:3443 でお試しください。')
        }
        throw swErr
      }

      let sub = await reg.pushManager.getSubscription()
      if (!sub) {
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(publicKey)
        })
      }

      const saveRes = await fetch('/api/push/subscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(sub)
      })
      if (!saveRes.ok) throw new Error('登録に失敗しました')

      if (status) status.textContent = 'この端末でプッシュ通知を登録しました'
      btn.textContent = '登録済み'
      btn.disabled = true
    } catch (err) {
      alert(err instanceof Error ? err.message : 'プッシュ通知の登録に失敗しました')
    }
  })
})()
