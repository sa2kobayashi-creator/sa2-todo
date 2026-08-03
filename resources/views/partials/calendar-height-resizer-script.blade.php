{{-- スマホ月カレンダー: 下端バーをドラッグでセル高さを調整 --}}
<script>
  (function () {
    const STORAGE_KEY = 'sa2:cal-day-min-height-v2'
    const LEGACY_STORAGE_KEY = 'sa2:cal-day-min-height'
    const MOBILE_MQ = '(max-width: 900px)'
    const DEFAULT_MOBILE = 64
    const DEFAULT_DESKTOP = 110
    const MIN_H = 48
    const MAX_H = 320

    function isMobile() {
      return window.matchMedia(MOBILE_MQ).matches
    }

    function clamp(n) {
      return Math.max(MIN_H, Math.min(MAX_H, Math.round(n)))
    }

    function applyHeight(px) {
      const value = clamp(px)
      const css = value + 'px'
      document.documentElement.style.setProperty('--cal-day-min-height', css)
      document.querySelectorAll('.calendar-shell').forEach((shell) => {
        shell.style.setProperty('--cal-day-min-height', css)
      })
      document.querySelectorAll('[data-cal-height-handle]').forEach((el) => {
        el.setAttribute('aria-valuemin', String(MIN_H))
        el.setAttribute('aria-valuemax', String(MAX_H))
        el.setAttribute('aria-valuenow', String(value))
      })
      return value
    }

    function currentHeight() {
      const raw = getComputedStyle(document.documentElement).getPropertyValue('--cal-day-min-height')
      const n = parseFloat(raw)
      return Number.isFinite(n) ? n : (isMobile() ? DEFAULT_MOBILE : DEFAULT_DESKTOP)
    }

    function persist(px) {
      if (!isMobile()) return
      try {
        window.localStorage.setItem(STORAGE_KEY, String(clamp(px)))
      } catch (_) {}
    }

    function readStoredHeight() {
      try {
        const raw = window.localStorage.getItem(STORAGE_KEY)
        if (raw != null) {
          const n = Number(raw)
          if (Number.isFinite(n)) return clamp(n)
        }
        window.localStorage.removeItem(LEGACY_STORAGE_KEY)
      } catch (_) {}
      return DEFAULT_MOBILE
    }

    function loadHeight() {
      if (!isMobile()) {
        return applyHeight(DEFAULT_DESKTOP)
      }
      return applyHeight(readStoredHeight())
    }

    function bindHandle(handle) {
      let startY = 0
      let startH = DEFAULT_MOBILE
      let dragging = false
      let activePointerId = null

      function clientYFromEvent(e) {
        const t = e.touches && e.touches[0]
        const ct = e.changedTouches && e.changedTouches[0]
        const point = t || ct
        if (point && typeof point.clientY === 'number') return point.clientY
        if (typeof e.clientY === 'number') return e.clientY
        return null
      }

      function onMove(clientY) {
        if (!dragging || clientY == null) return
        applyHeight(startH + (clientY - startY))
      }

      function onEnd() {
        if (!dragging) return
        dragging = false
        activePointerId = null
        handle.classList.remove('is-dragging')
        handle.closest('.calendar-height-resizer')?.classList.remove('is-dragging')
        document.body.classList.remove('cal-height-dragging')
        persist(currentHeight())
        handle.removeEventListener('pointermove', onPointerMove)
        handle.removeEventListener('pointerup', onPointerUp)
        handle.removeEventListener('pointercancel', onPointerUp)
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
        handle.classList.add('is-dragging')
        handle.closest('.calendar-height-resizer')?.classList.add('is-dragging')
        document.body.classList.add('cal-height-dragging')
      }

      function onPointerMove(e) {
        if (!dragging) return
        if (activePointerId != null && e.pointerId !== activePointerId) return
        onMove(e.clientY)
        if (e.cancelable) e.preventDefault()
      }

      function onPointerUp(e) {
        if (activePointerId != null && e.pointerId !== activePointerId) return
        onEnd()
      }

      function onTouchMove(e) {
        if (!dragging) return
        onMove(clientYFromEvent(e))
        if (e.cancelable) e.preventDefault()
      }

      function onTouchEnd() {
        onEnd()
      }

      handle.addEventListener('pointerdown', (e) => {
        if (!isMobile()) return
        if (e.pointerType === 'mouse' && e.button !== 0) return
        beginDrag(e.clientY, e.pointerId)
        try { handle.setPointerCapture(e.pointerId) } catch (_) {}
        handle.addEventListener('pointermove', onPointerMove)
        handle.addEventListener('pointerup', onPointerUp)
        handle.addEventListener('pointercancel', onPointerUp)
        window.addEventListener('pointermove', onPointerMove, { passive: false })
        window.addEventListener('pointerup', onPointerUp)
        window.addEventListener('pointercancel', onPointerUp)
        window.addEventListener('touchmove', onTouchMove, { passive: false })
        window.addEventListener('touchend', onTouchEnd)
        window.addEventListener('touchcancel', onTouchEnd)
        if (e.cancelable) e.preventDefault()
      })

      handle.addEventListener('touchstart', (e) => {
        if (!isMobile() || window.PointerEvent) return
        beginDrag(clientYFromEvent(e), null)
        window.addEventListener('touchmove', onTouchMove, { passive: false })
        window.addEventListener('touchend', onTouchEnd)
        window.addEventListener('touchcancel', onTouchEnd)
        if (e.cancelable) e.preventDefault()
      }, { passive: false })
    }

    loadHeight()
    document.querySelectorAll('[data-cal-height-resizer]').forEach((root) => {
      const handle = root.querySelector('[data-cal-height-handle]') || root
      bindHandle(handle)
    })
    const mq = window.matchMedia(MOBILE_MQ)
    const onMq = () => loadHeight()
    if (mq.addEventListener) mq.addEventListener('change', onMq)
    else if (mq.addListener) mq.addListener(onMq)
  })()
</script>
