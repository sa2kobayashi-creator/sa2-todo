(() => {
  const suggestUrl = '/travel/airports/suggest'

  function debounce(fn, ms) {
    let t = 0
    return (...args) => {
      window.clearTimeout(t)
      t = window.setTimeout(() => fn(...args), ms)
    }
  }

  function attachAirportInput(input) {
    if (!input || input.dataset.travelAirportBound === '1') return
    input.dataset.travelAirportBound = '1'
    const wrap = input.closest('.travel-airport-control') || input.parentElement
    if (!wrap) return
    wrap.classList.add('travel-airport-control')
    const list = document.createElement('ul')
    list.className = 'travel-airport-suggest'
    list.hidden = true
    wrap.appendChild(list)

    let abort = null
    const run = debounce(async () => {
      const q = input.value.trim()
      if (q.length < 1) {
        list.hidden = true
        list.innerHTML = ''
        return
      }
      abort?.abort()
      abort = new AbortController()
      try {
        const res = await fetch(`${suggestUrl}?q=${encodeURIComponent(q)}`, {
          headers: { Accept: 'application/json' },
          signal: abort.signal,
        })
        const data = await res.json().catch(() => ({}))
        const items = Array.isArray(data.items) ? data.items : []
        list.innerHTML = ''
        items.forEach((item) => {
          const li = document.createElement('li')
          const btn = document.createElement('button')
          btn.type = 'button'
          btn.textContent = item.label || item.code
          btn.addEventListener('click', () => {
            input.value = item.code
            list.hidden = true
            input.dispatchEvent(new Event('change', { bubbles: true }))
          })
          li.appendChild(btn)
          list.appendChild(li)
        })
        list.hidden = items.length === 0
      } catch (err) {
        if (err?.name !== 'AbortError') {
          list.hidden = true
        }
      }
    }, 200)

    input.addEventListener('input', run)
    input.addEventListener('focus', run)
    document.addEventListener('click', (ev) => {
      if (!wrap.contains(ev.target)) {
        list.hidden = true
      }
    })
  }

  function bindReturnFields() {
    const mode = document.getElementById('travel-fare-table-mode')
    const form = document.getElementById('travel-fare-table-form')
    if (!mode || !form) return
    const returnFields = form.querySelectorAll('.travel-fare-return-field')
    const sync = () => {
      const isRt = mode.value === 'rt'
      returnFields.forEach((el) => {
        el.classList.toggle('is-hidden', !isRt)
        const field = el.querySelector('input')
        if (field) {
          field.required = isRt
        }
      })
    }
    mode.addEventListener('change', sync)
    sync()
  }

  function bindSwap() {
    const btn = document.getElementById('travel-places-swap')
    const form = document.getElementById('travel-fare-table-form')
    if (!btn || !form) return
    btn.addEventListener('click', () => {
      const origin = form.querySelector('[name="origin"]')
      const dest = form.querySelector('[name="destination"]')
      if (!origin || !dest) return
      const tmp = origin.value
      origin.value = dest.value
      dest.value = tmp
    })
  }

  document.querySelectorAll('[data-travel-airport]').forEach(attachAirportInput)
  bindReturnFields()
  bindSwap()

  window.travelAiContext = function travelAiContext() {
    const form = document.getElementById('travel-fare-table-form')
    if (!form) return {}
    const value = (name) => form.querySelector(`[name="${name}"]`)?.value || ''
    return {
      origin: value('origin'),
      destination: value('destination'),
      tableMode: value('tableMode'),
      tableCurrency: value('tableCurrency'),
      airlineCode: value('airlineCode'),
      departFrom: value('departFrom'),
      departTo: value('departTo'),
      returnFrom: value('returnFrom'),
      returnTo: value('returnTo'),
    }
  }
})()
