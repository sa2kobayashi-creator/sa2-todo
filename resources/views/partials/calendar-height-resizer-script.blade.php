{{-- スマホ月カレンダー: 下端ドラッグでセル高さを調整（既定は通常高さ） --}}
<script>
  (function () {
    const STORAGE_KEY = 'sa2:cal-day-min-height'
    const MOBILE_MQ = '(max-width: 768px)'
    const DEFAULT_MOBILE = 96
    const DEFAULT_DESKTOP = 110
    const MIN_H = 72
    const MAX_H = 220

    function isMobile() {
      return window.matchMedia(MOBILE_MQ).matches
    }

    function clamp(n) {
      return Math.max(MIN_H, Math.min(MAX_H, Math.round(n)))
    }

    function applyHeight(px) {
      document.documentElement.style.setProperty('--cal-day-min-height', px + 'px')
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
      })
    }

    function bindResizer(el) {
      let startY = 0
      let startH = DEFAULT_MOBILE
      let dragging = false

      function onMove(clientY) {
        if (!dragging) return
        const delta = clientY - startY
        const next = clamp(startH + delta)
        applyHeight(next)
      }

      function onEnd() {
        if (!dragging) return
        dragging = false
        el.classList.remove('is-dragging')
        const current = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--cal-day-min-height')) || DEFAULT_MOBILE
        try {
          window.localStorage.setItem(STORAGE_KEY, String(clamp(current)))
        } catch (_) {}
      }

      el.addEventListener('pointerdown', (e) => {
        if (!isMobile()) return
        dragging = true
        startY = e.clientY
        startH = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--cal-day-min-height')) || DEFAULT_MOBILE
        el.classList.add('is-dragging')
        el.setPointerCapture?.(e.pointerId)
        e.preventDefault()
      })
      el.addEventListener('pointermove', (e) => onMove(e.clientY))
      el.addEventListener('pointerup', onEnd)
      el.addEventListener('pointercancel', onEnd)
    }

    loadHeight()
    syncResizers()
    document.querySelectorAll('[data-cal-height-resizer]').forEach(bindResizer)
    window.matchMedia(MOBILE_MQ).addEventListener('change', () => {
      loadHeight()
      syncResizers()
    })
  })()
</script>
