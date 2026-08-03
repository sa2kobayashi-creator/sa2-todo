{{-- スマホ月カレンダー: 下端ドラッグでセル高さを調整（既定は通常高さ） --}}
<script>
  (function () {
    const STORAGE_KEY = 'sa2:cal-day-min-height'
    // ツールバーと同じく 900px までをモバイル扱い（高DPI実機対策）
    const MOBILE_MQ = '(max-width: 900px)'
    const DEFAULT_MOBILE = 96
    const DEFAULT_DESKTOP = 110
    const MIN_H = 72
    const MAX_H = 280

    function isMobile() {
      return window.matchMedia(MOBILE_MQ).matches
    }

    function clamp(n) {
      return Math.max(MIN_H, Math.min(MAX_H, Math.round(n)))
    }

    function applyHeight(px) {
      const value = clamp(px) + 'px'
      document.documentElement.style.setProperty('--cal-day-min-height', value)
      document.querySelectorAll('.calendar-shell, .todos-calendar-panel').forEach((shell) => {
        shell.style.setProperty('--cal-day-min-height', value)
      })
    }

    function currentHeight() {
      const raw = getComputedStyle(document.documentElement).getPropertyValue('--cal-day-min-height')
      const n = parseFloat(raw)
      return Number.isFinite(n) ? n : (isMobile() ? DEFAULT_MOBILE : DEFAULT_DESKTOP)
    }

    function loadHeight() {
      if (!isMobile()) {
        applyHeight(DEFAULT_DESKTOP)
        return DEFAULT_DESKTOP
      }
      const raw = window.localStorage.getItem(STORAGE_KEY)
      const n = raw != null ? Number(raw) : DEFAULT_MOBILE
      const px = Number.isFinite(n) ? clamp(n) : DEFAULT_MOBILE
      applyHeight(px)
      return px
    }

    function syncResizers() {
      const show = isMobile()
      document.querySelectorAll('[data-cal-height-resizer]').forEach((el) => {
        el.hidden = !show
        el.setAttribute('aria-hidden', show ? 'false' : 'true')
      })
    }

    function bindResizer(el) {
      let startY = 0
      let startH = DEFAULT_MOBILE
      let dragging = false
      let activePointerId = null

      function clientYFromEvent(e) {
        if (typeof e.clientY === 'number') return e.clientY
        const t = e.touches && e.touches[0]
        const ct = e.changedTouches && e.changedTouches[0]
        return (t || ct || {}).clientY
      }

      function onMove(clientY) {
        if (!dragging || clientY == null) return
        applyHeight(startH + (clientY - startY))
      }

      function onEnd() {
        if (!dragging) return
        dragging = false
        activePointerId = null
        el.classList.remove('is-dragging')
        document.body.classList.remove('cal-height-dragging')
        try {
          window.localStorage.setItem(STORAGE_KEY, String(clamp(currentHeight())))
        } catch (_) {}
        window.removeEventListener('pointermove', onPointerMove)
        window.removeEventListener('pointerup', onPointerUp)
        window.removeEventListener('pointercancel', onPointerUp)
        window.removeEventListener('touchmove', onTouchMove)
        window.removeEventListener('touchend', onTouchEnd)
        window.removeEventListener('touchcancel', onTouchEnd)
      }

      function beginDrag(clientY, pointerId) {
        if (!isMobile() || clientY == null) return
        dragging = true
        activePointerId = pointerId != null ? pointerId : null
        startY = clientY
        startH = currentHeight()
        el.classList.add('is-dragging')
        document.body.classList.add('cal-height-dragging')
      }

      function onPointerMove(e) {
        if (!dragging) return
        if (activePointerId != null && e.pointerId !== activePointerId) return
        onMove(e.clientY)
        e.preventDefault()
      }

      function onPointerUp(e) {
        if (activePointerId != null && e.pointerId !== activePointerId) return
        onEnd()
      }

      function onTouchMove(e) {
        if (!dragging) return
        onMove(clientYFromEvent(e))
        e.preventDefault()
      }

      function onTouchEnd() {
        onEnd()
      }

      el.addEventListener('pointerdown', (e) => {
        if (!isMobile()) return
        if (e.pointerType === 'mouse' && e.button !== 0) return
        beginDrag(e.clientY, e.pointerId)
        try {
          el.setPointerCapture(e.pointerId)
        } catch (_) {}
        window.addEventListener('pointermove', onPointerMove, { passive: false })
        window.addEventListener('pointerup', onPointerUp)
        window.addEventListener('pointercancel', onPointerUp)
        e.preventDefault()
      })

      // 一部 Android で Pointer Events が使えない場合のフォールバック
      el.addEventListener('touchstart', (e) => {
        if (window.PointerEvent || !isMobile() || dragging) return
        beginDrag(clientYFromEvent(e), null)
        window.addEventListener('touchmove', onTouchMove, { passive: false })
        window.addEventListener('touchend', onTouchEnd)
        window.addEventListener('touchcancel', onTouchEnd)
      }, { passive: true })
    }

    loadHeight()
    syncResizers()
    document.querySelectorAll('[data-cal-height-resizer]').forEach(bindResizer)
    const mq = window.matchMedia(MOBILE_MQ)
    if (mq.addEventListener) {
      mq.addEventListener('change', () => {
        loadHeight()
        syncResizers()
      })
    } else if (mq.addListener) {
      mq.addListener(() => {
        loadHeight()
        syncResizers()
      })
    }
  })()
</script>
