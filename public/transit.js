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
  const itinerariesBox = document.getElementById('transit-itineraries')
  const preferenceSelect = document.getElementById('transit-preference')
  const preferNishitetsuInput = document.getElementById('transit-prefer-nishitetsu')
  const csrfToken = config.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || ''

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
    const units = (window.TRANSIT_CONFIG && window.TRANSIT_CONFIG.datetimeUnits) || {
      year: '年', month: '月', day: '日', hour: '時', minute: '分',
    }
    fillSelect(yearSelect, year, year + 1, year, units.year || '', false)
    fillSelect(monthSelect, 1, 12, now.getMonth() + 1, units.month || '', false)
    fillSelect(daySelect, 1, 31, now.getDate(), units.day || '', false)
    fillSelect(hourSelect, 0, 23, now.getHours(), units.hour || '', false)
    // 分は5分刻み（現在の分を切り捨て）
    if (minuteSelect) {
      let html = ''
      const currentMin = Math.floor(now.getMinutes() / 5) * 5
      const minuteUnit = units.minute || ''
      for (let m = 0; m < 60; m += 5) {
        html += '<option value="' + pad2(m) + '"' + (m === currentMin ? ' selected' : '') + '>' + pad2(m) + minuteUnit + '</option>'
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

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
  }

  function renderItineraries(data) {
    if (!itinerariesBox) return
    const list = data.itineraries || []
    if (!data.ok) {
      itinerariesBox.innerHTML = '<p class="hint transit-raptor-error">' + escapeHtml(data.message || '経路が見つかりませんでした') + '</p>'
      return
    }
    if (list.length === 0) {
      itinerariesBox.innerHTML = '<p class="hint">条件に合う経路がありません。地名を「天神」「博多」「姪浜」「福岡空港」などに変えて試してください。</p>'
      return
    }
    itinerariesBox.innerHTML = list.map(function (it, index) {
      const badge = it.usesNishitetsuBus
        ? '<span class="transit-itinerary-badge is-nishitetsu">西鉄バス優先</span>'
        : ''
      const legs = (it.legs || []).map(function (leg) {
        if (leg.type === 'walk') {
          return '<li class="is-walk">徒歩 ' + escapeHtml(leg.from) + ' → ' + escapeHtml(leg.to) + '（' + escapeHtml(String(Math.round((leg.durationSec || 0) / 60))) + '分）</li>'
        }
        return '<li class="is-ride"><strong>' + escapeHtml(leg.routeName || '') + '</strong> '
          + escapeHtml(leg.boardTime || '') + ' ' + escapeHtml(leg.from || '')
          + ' → ' + escapeHtml(leg.alightTime || '') + ' ' + escapeHtml(leg.to || '')
          + (leg.waitSec ? ' <span class="hint">待ち' + Math.round(leg.waitSec / 60) + '分</span>' : '')
          + '</li>'
      }).join('')
      return '<article class="transit-itinerary-card">'
        + '<div class="transit-itinerary-head">'
        + '<strong>#' + (index + 1) + ' ' + escapeHtml(it.summary || '') + '</strong>'
        + badge
        + '</div>'
        + '<div class="transit-itinerary-meta">'
        + escapeHtml(it.departureTime) + ' → ' + escapeHtml(it.arrivalTime)
        + ' · ' + escapeHtml(it.durationLabel)
        + ' · 待ち ' + escapeHtml(it.waitLabel || '0分')
        + ' · 乗換 ' + escapeHtml(String(it.transfers || 0)) + '回'
        + ' · ' + escapeHtml(it.fareLabel || '')
        + '</div>'
        + '<ol class="transit-itinerary-legs">' + legs + '</ol>'
        + '</article>'
    }).join('')
  }

  function appendExternalLinks(fromRaw, toRaw, from, to) {
    const timeParams = yahooTimeParams()
    linksBox.innerHTML = ''
    const title = document.createElement('h4')
    title.className = 'transit-external-title'
    title.textContent = '外部サービスでも確認'
    linksBox.appendChild(title)
    linksBox.appendChild(makeLink(
      'Google Maps でルート',
      'https://www.google.com/maps/dir/?api=1&travelmode=transit&origin=' + enc(fromRaw) + '&destination=' + enc(toRaw),
      false
    ))
    linksBox.appendChild(makeLink(
      'Yahoo!路線でルート',
      'https://transit.yahoo.co.jp/search/result?from=' + enc(from) + '&to=' + enc(to) + timeParams,
      false
    ))
    if (category === 'nishitetsu_bus') {
      linksBox.appendChild(makeLink('西鉄バスナビ', 'https://busnavi.nishitetsu.jp/', false))
    }
  }

  async function runRaptorSearch(from, to) {
    const hour = hourSelect ? hourSelect.value : '08'
    const minute = minuteSelect ? minuteSelect.value : '00'
    const year = yearSelect ? yearSelect.value : String(new Date().getFullYear())
    const month = monthSelect ? monthSelect.value : '01'
    const day = daySelect ? daySelect.value : '01'
    const departureAt = year + '-' + month + '-' + day + 'T' + hour + ':' + minute
    const response = await fetch('/transit/search', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({
        from: from,
        to: to,
        preference: preferenceSelect ? preferenceSelect.value : 'fastest',
        preferNishitetsuBus: preferNishitetsuInput ? preferNishitetsuInput.checked : true,
        minTransferMin: 2,
        maxTransferWaitMin: 10,
        departureAt: departureAt,
        hour: parseInt(hour, 10),
        minute: parseInt(minute, 10),
      }),
    })
    return response.json()
  }

  async function runSearch() {
    const fromRaw = fromInput.value.trim()
    const toRaw = toInput.value.trim()

    if (!fromRaw && !toRaw) {
      alert('出発（バス停・駅）を入力してください')
      return
    }

    const from = cleanForTransit(fromRaw)
    const to = cleanForTransit(toRaw)
    linksBox.innerHTML = ''
    if (itinerariesBox) itinerariesBox.innerHTML = '<p class="hint">RAPTOR で検索中…</p>'
    resultsBox.removeAttribute('hidden')

    if (fromRaw && toRaw) {
      resultsTitle.textContent = from + ' → ' + to + '（RAPTOR）'
      try {
        const data = await runRaptorSearch(from, to)
        renderItineraries(data)
      } catch (err) {
        if (itinerariesBox) {
          itinerariesBox.innerHTML = '<p class="hint transit-raptor-error">検索に失敗しました。再読み込みして再度お試しください。</p>'
        }
      }
      appendExternalLinks(fromRaw, toRaw, from, to)
      return
    }

    const placeRaw = fromRaw || toRaw
    const place = from || to
    resultsTitle.textContent = place + ' の時刻表・地図'
    if (itinerariesBox) {
      itinerariesBox.innerHTML = '<p class="hint">到着地も入力すると RAPTOR で乗換経路を出せます。</p>'
    }
    let query = placeRaw
    if (category === 'nishitetsu_bus') query = placeRaw + ' バス停'
    else if (category === 'ferry') query = placeRaw + ' 渡船場'
    else if (category !== 'all') query = placeRaw + ' 駅'
    const timeParams = yahooTimeParams()
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

  function extractStationLabel(text) {
    if (!text) return ''
    const matches = String(text).match(/([一-龥ぁ-んァ-ヶA-Za-z0-9]+?)(?:駅|バス停|バスセンター|渡船場)/g)
    if (!matches || !matches.length) return ''
    return matches[matches.length - 1]
  }

  function labelFromJapaneseAddress(address) {
    if (!address) return ''
    const normalized = address.replace(/^日本[、,]\s*/, '').trim()
    const station = extractStationLabel(normalized)
    if (station) return station

    // 「東区 志賀島」のように末尾が地名のケース
    const spaceParts = normalized.split(/\s+/).filter(Boolean)
    if (spaceParts.length > 1) {
      const last = spaceParts[spaceParts.length - 1].replace(/[、,]+$/g, '')
      if (last && !isWeakPlaceLabel(last) && !/^[0-9０-９\-−ー丁目番地号]+$/.test(last)) {
        return last
      }
    }

    const afterWard = normalized.match(/区\s*([^、,]+)/)
    if (afterWard && afterWard[1]) {
      const wardTail = afterWard[1].trim()
      const wardStation = extractStationLabel(wardTail)
      if (wardStation) return wardStation
      const wardSpace = wardTail.split(/\s+/).filter(Boolean)
      if (wardSpace.length) return wardSpace[wardSpace.length - 1]
      return wardTail
    }
    const parts = address.split(/[、,]/).map(function (s) { return s.trim() }).filter(Boolean)
    if (parts.length > 0) {
      const last = parts[parts.length - 1]
      const fromLast = last.match(/区\s*(.+)$/)
      if (fromLast && fromLast[1]) return fromLast[1].trim()
      return last
    }
    return address
  }

  function looksLikeTransitStop(label) {
    if (!label || isWeakPlaceLabel(label)) return false
    return /(?:駅|バス停|バスセンター|渡船場|港)$/.test(label) || label.length <= 8
  }

  function resolvePlaceLabel(place, prediction) {
    const displayName = place.displayName || ''
    const formattedAddress = place.formattedAddress || ''
    const fromAddress = labelFromJapaneseAddress(formattedAddress)
    const predictionText = prediction && prediction.text ? prediction.text.text : ''
    const stationFromAddress = extractStationLabel(formattedAddress)

    // 駅・バス停名が取れたらそれを最優先（住所全文より短い displayName を捨てない）
    if (stationFromAddress) return stationFromAddress
    if (displayName && looksLikeTransitStop(displayName)) return displayName
    if (predictionText && looksLikeTransitStop(predictionText)) return predictionText
    if (fromAddress && !isWeakPlaceLabel(fromAddress)) return fromAddress
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
