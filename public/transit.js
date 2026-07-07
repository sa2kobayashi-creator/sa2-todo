(function () {
  const config = window.TRANSIT_CONFIG || {}
  const category = config.category || 'nishitetsu_bus'

  const modal = document.getElementById('transit-favorite-modal')
  const form = document.getElementById('transit-favorite-form')
  const openBtn = document.getElementById('transit-open-add')
  const modalTitle = document.getElementById('transit-modal-title')
  const submitBtn = document.getElementById('transit-favorite-submit')
  const favoriteIdInput = document.getElementById('transit-favorite-id')
  const fromInput = document.getElementById('transit-from')
  const toInput = document.getElementById('transit-to')

  function closeModal() {
    if (modal) modal.setAttribute('hidden', '')
  }

  function openAddModal() {
    modalTitle.textContent = 'よく使う路線を登録'
    submitBtn.textContent = '保存'
    favoriteIdInput.value = ''
    form.action = '/transit'
    form.querySelector('#transit-favorite-name').value = ''
    form.querySelector('#transit-favorite-from').value = ''
    form.querySelector('#transit-favorite-to').value = ''
    form.querySelector('#transit-favorite-line').value = ''
    form.querySelector('#transit-favorite-notes').value = ''
    const categorySelect = form.querySelector('#transit-favorite-category')
    if (categorySelect) {
      const hasOption = Array.prototype.some.call(categorySelect.options, function (o) { return o.value === category })
      categorySelect.value = hasOption ? category : categorySelect.options[0].value
    }
    if (modal) modal.removeAttribute('hidden')
  }

  function openEditModal(data) {
    modalTitle.textContent = '路線を編集'
    submitBtn.textContent = '更新'
    favoriteIdInput.value = String(data.id)
    form.action = '/transit/' + data.id + '/update'
    form.querySelector('#transit-favorite-category').value = data.category
    form.querySelector('#transit-favorite-name').value = data.name
    form.querySelector('#transit-favorite-from').value = data.fromPlace || ''
    form.querySelector('#transit-favorite-to').value = data.toPlace || ''
    form.querySelector('#transit-favorite-line').value = data.lineName || ''
    form.querySelector('#transit-favorite-notes').value = data.notes || ''
    if (modal) modal.removeAttribute('hidden')
  }

  if (openBtn) openBtn.addEventListener('click', openAddModal)
  document.querySelectorAll('[data-close-transit-modal]').forEach(function (el) {
    el.addEventListener('click', closeModal)
  })
  document.querySelectorAll('.transit-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const card = btn.closest('.transit-favorite-card')
      openEditModal(JSON.parse(card.dataset.favorite))
    })
  })

  const searchForm = document.getElementById('transit-search-form')
  const resultsBox = document.getElementById('transit-search-results')
  const resultsTitle = document.getElementById('transit-search-results-title')
  const linksBox = document.getElementById('transit-search-links')

  function enc(value) {
    return encodeURIComponent(value)
  }

  function makeLink(label, url, primary) {
    const a = document.createElement('a')
    a.href = url
    a.target = '_blank'
    a.rel = 'noopener noreferrer'
    a.className = primary ? 'button-link' : 'button-link secondary'
    a.textContent = label
    return a
  }

  function cleanForTransit(value) {
    if (!value) return ''
    return labelFromJapaneseAddress(value.trim()) || value.trim()
  }

  const yearSelect = document.getElementById('transit-year')
  const monthSelect = document.getElementById('transit-month')
  const daySelect = document.getElementById('transit-day')
  const hourSelect = document.getElementById('transit-hour')
  const minuteSelect = document.getElementById('transit-minute')

  function pad2(n) {
    return (n < 10 ? '0' : '') + n
  }

  function fillSelect(select, from, to, current, label, pad) {
    if (!select) return
    let html = ''
    for (let i = from; i <= to; i++) {
      const value = pad ? pad2(i) : String(i)
      html += '<option value="' + value + '"' + (i === current ? ' selected' : '') + '>' + value + label + '</option>'
    }
    select.innerHTML = html
  }

  function initDatetimeSelects() {
    const now = new Date()
    const year = now.getFullYear()
    fillSelect(yearSelect, year, year + 1, year, '年', false)
    fillSelect(monthSelect, 1, 12, now.getMonth() + 1, '月', false)
    fillSelect(daySelect, 1, 31, now.getDate(), '日', false)
    fillSelect(hourSelect, 0, 23, now.getHours(), '時', false)
    // 分は5分刻み（現在の分を切り捨て）
    if (minuteSelect) {
      let html = ''
      const currentMin = Math.floor(now.getMinutes() / 5) * 5
      for (let m = 0; m < 60; m += 5) {
        html += '<option value="' + pad2(m) + '"' + (m === currentMin ? ' selected' : '') + '>' + pad2(m) + '分</option>'
      }
      minuteSelect.innerHTML = html
    }
  }

  function selectedTimeType() {
    const checked = document.querySelector('input[name="transit-time-type"]:checked')
    return checked ? checked.value : '1'
  }

  function syncDatetimeState() {
    const disabled = selectedTimeType() === '0'
    ;[yearSelect, monthSelect, daySelect, hourSelect, minuteSelect].forEach(function (sel) {
      if (sel) sel.disabled = disabled
    })
  }

  if (yearSelect) {
    initDatetimeSelects()
    document.querySelectorAll('input[name="transit-time-type"]').forEach(function (radio) {
      radio.addEventListener('change', syncDatetimeState)
    })
    syncDatetimeState()
  }

  // Yahoo!路線の時刻パラメータ（type: 1=出発, 4=到着, 2=始発, 3=終電, 0=指定なし / 分は m1=十の位, m2=一の位）
  function yahooTimeParams() {
    const type = selectedTimeType()
    if (type === '0') return ''
    if (!yearSelect || !monthSelect || !daySelect || !hourSelect || !minuteSelect) return ''
    const minutes = parseInt(minuteSelect.value, 10) || 0
    return '&type=' + enc(type) +
      '&y=' + yearSelect.value +
      '&m=' + monthSelect.value +
      '&d=' + daySelect.value +
      '&hh=' + hourSelect.value +
      '&m1=' + Math.floor(minutes / 10) +
      '&m2=' + (minutes % 10)
  }

  function runSearch() {
    const fromRaw = fromInput.value.trim()
    const toRaw = toInput.value.trim()

    if (!fromRaw && !toRaw) {
      alert('出発（バス停・駅）を入力してください')
      return
    }

    const from = cleanForTransit(fromRaw)
    const to = cleanForTransit(toRaw)
    const timeParams = yahooTimeParams()

    linksBox.innerHTML = ''

    if (fromRaw && toRaw) {
      resultsTitle.textContent = from + ' → ' + to + ' の検索結果'
      linksBox.appendChild(makeLink(
        'Google Maps でルート',
        'https://www.google.com/maps/dir/?api=1&travelmode=transit&origin=' + enc(fromRaw) + '&destination=' + enc(toRaw),
        true
      ))
      linksBox.appendChild(makeLink(
        'Yahoo!路線でルート',
        'https://transit.yahoo.co.jp/search/result?from=' + enc(from) + '&to=' + enc(to) + timeParams,
        false
      ))
    } else {
      const placeRaw = fromRaw || toRaw
      const place = from || to
      resultsTitle.textContent = place + ' の時刻表・地図'
      let query = placeRaw
      if (category === 'nishitetsu_bus') query = placeRaw + ' バス停'
      else if (category === 'ferry') query = placeRaw + ' 渡船場'
      else if (category !== 'all') query = placeRaw + ' 駅'
      linksBox.appendChild(makeLink(
        'Google Maps で開く',
        'https://www.google.com/maps/search/?api=1&query=' + enc(query),
        true
      ))
      linksBox.appendChild(makeLink(
        'Yahoo!路線で時刻表',
        'https://transit.yahoo.co.jp/search/result?from=' + enc(place) + timeParams,
        false
      ))
      if (category === 'nishitetsu_bus') {
        linksBox.appendChild(makeLink('西鉄バスナビ', 'https://busnavi.nishitetsu.jp/', false))
      }
    }

    resultsBox.removeAttribute('hidden')
  }

  if (searchForm) {
    searchForm.addEventListener('submit', function (e) {
      e.preventDefault()
      runSearch()
    })
  }

  const saveSearchBtn = document.getElementById('transit-save-search')
  if (saveSearchBtn) {
    saveSearchBtn.addEventListener('click', function () {
      const from = cleanForTransit(fromInput.value.trim())
      const to = cleanForTransit(toInput.value.trim())
      if (!from && !to) return
      openAddModal()
      const nameInput = form.querySelector('#transit-favorite-name')
      const fromField = form.querySelector('#transit-favorite-from')
      const toField = form.querySelector('#transit-favorite-to')
      if (fromField) fromField.value = from
      if (toField) toField.value = to
      if (nameInput) nameInput.value = to ? (from + ' → ' + to) : from
    })
  }

  function isWeakPlaceLabel(label) {
    if (!label) return true
    const text = label.trim()
    if (text.length <= 1) return true
    if (/^[０-９0-9一二三四五六七八九十百千]+丁目$/.test(text)) return true
    if (/^丁目/.test(text)) return true
    return false
  }

  function labelFromJapaneseAddress(address) {
    if (!address) return ''
    const normalized = address.replace(/^日本[、,]\s*/, '').trim()
    const afterWard = normalized.match(/区\s*([^、,]+)/)
    if (afterWard && afterWard[1]) return afterWard[1].trim()
    const parts = address.split(/[、,]/).map(function (s) { return s.trim() }).filter(Boolean)
    if (parts.length > 0) {
      const last = parts[parts.length - 1]
      const fromLast = last.match(/区\s*(.+)$/)
      if (fromLast && fromLast[1]) return fromLast[1].trim()
      return last
    }
    return address
  }

  function resolvePlaceLabel(place, prediction) {
    const displayName = place.displayName || ''
    const formattedAddress = place.formattedAddress || ''
    const fromAddress = labelFromJapaneseAddress(formattedAddress)
    const predictionText = prediction && prediction.text ? prediction.text.text : ''

    if (fromAddress && (isWeakPlaceLabel(displayName) || fromAddress.length > displayName.length)) {
      return fromAddress
    }
    if (predictionText && !isWeakPlaceLabel(predictionText)) {
      return predictionText
    }
    return displayName || fromAddress || formattedAddress || predictionText
  }

  function attachPlaceAutocomplete(holderId, targetInput) {
    const holder = document.getElementById(holderId)
    if (!holder || !targetInput) return
    const el = new google.maps.places.PlaceAutocompleteElement({
      includedRegionCodes: ['jp'],
    })
    el.style.width = '100%'
    holder.appendChild(el)
    targetInput.style.display = 'none'

    el.addEventListener('gmp-select', async function (event) {
      try {
        const prediction = event.placePrediction
        const place = prediction.toPlace()
        await place.fetchFields({ fields: ['displayName', 'formattedAddress'] })
        targetInput.value = resolvePlaceLabel(place, prediction)
      } catch (e) {
        // ignore
      }
    })
  }

  window.initTransitAutocomplete = async function initTransitAutocomplete() {
    try {
      await google.maps.importLibrary('places')
    } catch (e) {
      return
    }
    if (google.maps.places && google.maps.places.PlaceAutocompleteElement) {
      attachPlaceAutocomplete('transit-from-holder', fromInput)
      attachPlaceAutocomplete('transit-to-holder', toInput)
    }
  }

  if (window.google && window.google.maps) {
    window.initTransitAutocomplete()
  }
})()
