{{-- フォームを開いたまま放置してもセッション切れ（419）にならないよう、トークンを定期的に取り直す --}}
<script>
  (() => {
    const meta = document.querySelector('meta[name="csrf-token"]')
    if (!meta) return

    const applyToken = (token) => {
      if (!token) return
      meta.setAttribute('content', token)
      document.querySelectorAll('input[name="_token"]').forEach((input) => {
        input.value = token
      })
    }

    const refresh = async () => {
      try {
        const res = await fetch('/csrf-token', {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
          cache: 'no-store',
        })
        if (!res.ok) return
        const data = await res.json()
        applyToken(data.token)
      } catch (_) {
        // オフライン等は次の機会に取り直す
      }
    }

    setInterval(refresh, 10 * 60 * 1000)
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') refresh()
    })
    // 入力を始めた時点で取り直しておけば、送信までにトークンが新しくなっている
    document.addEventListener('focusin', refresh, { once: true })
  })()
</script>
