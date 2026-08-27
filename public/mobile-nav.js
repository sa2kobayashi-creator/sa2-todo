(function () {
  if (window.__sa2MobileNavInit) return
  window.__sa2MobileNavInit = true

  const nav = document.querySelector('.mobile-bottom-nav')
  if (!nav) return

  const grid = nav.querySelector('.mobile-nav-grid')
  const doneBtn = nav.querySelector('.mobile-nav-done')
  const expandable = nav.hasAttribute('data-expandable')
  const expandedRows = Math.max(1, parseInt(nav.getAttribute('data-expanded-rows') || '1', 10) || 1)
  const reorderUrl = nav.getAttribute('data-reorder-url') || ''
  const LONG_PRESS_MS = 420
  const MOVE_CANCEL_PX = 12

  let expanded = false
  let reordering = false
  let dragging = null
  let ghost = null
  let pressTimer = null
  let pressStart = null
  let suppressClick = false

  function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
  }

  function items() {
    return Array.from(grid.querySelectorAll('.mobile-nav-item'))
  }

  function setHandleHeight() {
    document.documentElement.style.setProperty(
      '--mobile-nav-handle-height',
      reordering ? '32px' : '0px'
    )
  }

  function setExpanded(next) {
    expanded = Boolean(next) || reordering
    document.documentElement.style.setProperty(
      '--mobile-nav-visible-rows',
      String(expanded ? expandedRows : 1)
    )
    nav.classList.toggle('is-expanded', expanded)
    nav.setAttribute('aria-expanded', expanded ? 'true' : 'false')
  }

  function currentKeys() {
    return items().map((el) => el.getAttribute('data-nav-key') || '').filter(Boolean)
  }

  function indexFromPoint(x, y) {
    const list = items()
    let best = 0
    let bestDist = Infinity
    list.forEach((el, i) => {
      const box = el.getBoundingClientRect()
      const dx = box.left + box.width / 2 - x
      const dy = box.top + box.height / 2 - y
      const dist = dx * dx + dy * dy
      if (dist < bestDist) {
        bestDist = dist
        best = i
      }
    })
    return best
  }

  function moveItem(from, to) {
    if (from === to || from < 0 || to < 0) return
    const list = items()
    const el = list[from]
    const target = list[to]
    if (!el || !target) return
    if (from < to) target.after(el)
    else target.before(el)
  }

  async function saveOrder() {
    if (!reorderUrl) return
    const keys = currentKeys()
    if (keys.length === 0) return
    try {
      const res = await fetch(reorderUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ footer_nav: keys }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok || !data.ok) {
        if (Array.isArray(data.footer_nav) && data.footer_nav.length) {
          restoreOrder(data.footer_nav)
        }
      }
    } catch (_) {}
  }

  function restoreOrder(keys) {
    keys.forEach((key) => {
      const el = grid.querySelector('.mobile-nav-item[data-nav-key="' + key + '"]')
      if (el) grid.appendChild(el)
    })
  }

  function setReordering(next) {
    reordering = Boolean(next)
    nav.classList.toggle('is-reordering', reordering)
    if (doneBtn) doneBtn.hidden = !reordering
    setHandleHeight()
    if (reordering) setExpanded(true)
    else setExpanded(false)
  }

  function clearPressTimer() {
    if (pressTimer) {
      clearTimeout(pressTimer)
      pressTimer = null
    }
  }

  function removeGhost() {
    if (ghost) {
      ghost.remove()
      ghost = null
    }
    items().forEach((el) => el.classList.remove('is-placeholder'))
  }

  function startDrag(item, event) {
    const point = event.touches ? event.touches[0] : event
    if (!item || !point) return
    suppressClick = true
    setReordering(true)
    const rect = item.getBoundingClientRect()
    dragging = {
      item,
      from: items().indexOf(item),
      offsetX: point.clientX - rect.left,
      offsetY: point.clientY - rect.top,
      dirty: false,
    }
    ghost = item.cloneNode(true)
    ghost.classList.add('is-drag-ghost')
    ghost.style.width = rect.width + 'px'
    ghost.style.height = rect.height + 'px'
    ghost.style.left = rect.left + 'px'
    ghost.style.top = rect.top + 'px'
    document.body.appendChild(ghost)
    item.classList.add('is-placeholder')
    try {
      navigator.vibrate(12)
    } catch (_) {}
  }

  function expandMenus() {
    suppressClick = true
    setExpanded(true)
    try {
      navigator.vibrate(8)
    } catch (_) {}
  }

  function eventPoint(event) {
    if (event.changedTouches && event.changedTouches[0]) return event.changedTouches[0]
    if (event.touches && event.touches[0]) return event.touches[0]
    return event
  }

  function onPointerMove(event) {
    const point = eventPoint(event)
    if (!point) return

    if (pressStart && !dragging) {
      const dx = point.clientX - pressStart.x
      const dy = point.clientY - pressStart.y
      if ((dx * dx + dy * dy) > MOVE_CANCEL_PX * MOVE_CANCEL_PX) {
        clearPressTimer()
        pressStart = null
      }
      return
    }

    if (!dragging || !ghost) return
    if (event.cancelable) event.preventDefault()
    ghost.style.left = (point.clientX - dragging.offsetX) + 'px'
    ghost.style.top = (point.clientY - dragging.offsetY) + 'px'
    const to = indexFromPoint(point.clientX, point.clientY)
    const from = items().indexOf(dragging.item)
    if (from !== to) {
      moveItem(from, to)
      dragging.dirty = true
    }
  }

  function endDrag() {
    clearPressTimer()
    pressStart = null
    const dirty = Boolean(dragging?.dirty)
    dragging = null
    removeGhost()
    if (dirty) saveOrder()
    setTimeout(function () {
      suppressClick = false
    }, 80)
  }

  function onItemPointerDown(event) {
    const item = event.currentTarget
    if (event.button != null && event.button !== 0) return
    const point = event.touches ? event.touches[0] : event
    if (!point) return
    pressStart = { x: point.clientX, y: point.clientY }
    if (reordering) {
      startDrag(item, event)
      return
    }
    clearPressTimer()
    pressTimer = setTimeout(function () {
      if (expandable && !expanded) expandMenus()
      else startDrag(item, event)
    }, LONG_PRESS_MS)
  }

  setHandleHeight()
  setExpanded(false)

  items().forEach((item) => {
    item.addEventListener('pointerdown', onItemPointerDown)
    item.addEventListener('contextmenu', function (event) {
      event.preventDefault()
    })
  })

  window.addEventListener('pointermove', onPointerMove, { passive: false })
  window.addEventListener('touchmove', onPointerMove, { passive: false })
  window.addEventListener('pointerup', endDrag)
  window.addEventListener('pointercancel', endDrag)
  window.addEventListener('touchend', endDrag)
  window.addEventListener('touchcancel', endDrag)

  grid.addEventListener('click', function (event) {
    if (!reordering && !suppressClick) return
    event.preventDefault()
    event.stopPropagation()
  }, true)

  doneBtn?.addEventListener('click', function () {
    setReordering(false)
  })

  document.addEventListener('pointerdown', function (event) {
    if (!expanded || reordering) return
    if (nav.contains(event.target)) return
    setExpanded(false)
  })
})()
