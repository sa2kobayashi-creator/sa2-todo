(function () {
  const config = window.TRANSIT_CONFIG || {}
  const t = Object.assign({
    addFavorite: 'よく使う路線を登録',
    editFavorite: '路線を編集',
    save: '保存',
    update: '更新',
    noRoute: '経路が見つかりませんでした',
    noMatchingRoute: '条件に合う経路がありません。地名を「天神」「博多」「姪浜」「福岡空港」などに変えて試してください。',
    nishitetsuPreferred: '優先',
    operatorPreferred: '優先',
    walk: '徒歩',
    min: '分',
    wait: '待ち',
    waitPrefix: '待ち',
    transfers: '乗換',
    times: '回',
    externalTitle: '外部サービスでも確認',
    googleMapsRoute: 'Google Maps でルート',
    yahooRoute: 'Yahoo!路線でルート',
    needFrom: '出発（バス停・駅）を入力してください',
    searching: '経路を検索中…',
    searchFailed: '検索に失敗しました。再読み込みして再度お試しください。',
    timetableFor: ':place の時刻表・地図',
    enterDestinationHint: '到着地も入力すると乗換経路を出せます。',
    googleMapsOpen: 'Google Maps で開く',
    yahooTimetable: 'Yahoo!路線で時刻表',
    zeroMin: '0分',
    share: '共有',
    shareNeedResult: '先に経路を検索してください。',
    shareSent: 'チャットに送りました',
    shareFailed: '共有に失敗しました',
    shareOpenChat: 'チャットを開く',
    groupAll: 'グループ全体',
    swapPlaces: '出発と到着を入れ替え',
    voiceListening: '聞いています…',
    voiceUnsupported: 'このブラウザでは音声入力に対応していません。',
    voiceHttps: '音声入力は HTTPS（または localhost）でのみ利用できます。',
    voiceStartFailed: '音声認識を開始できませんでした。',
    voiceMicDenied: 'マイクの使用が許可されていません。',
  }, config.strings || {})

  const modal = document.getElementById('transit-favorite-modal')
  const form = document.getElementById('transit-favorite-form')
  const openBtn = document.getElementById('transit-open-add')
  const modalTitle = document.getElementById('transit-modal-title')
  const submitBtn = document.getElementById('transit-favorite-submit')
  const favoriteIdInput = document.getElementById('transit-favorite-id')
  const fromInput = document.getElementById('transit-from')
  const toInput = document.getElementById('transit-to')
  const placeWidgets = { from: null, to: null }

  function autocompleteInnerInput(widget) {
    if (!widget) return null
    try {
      return widget.querySelector('input') || (widget.shadowRoot && widget.shadowRoot.querySelector('input')) || null
    } catch (_) {
      return null
    }
  }

  function widgetValue(widget) {
    if (!widget) return ''
    const inner = autocompleteInnerInput(widget)
    if (inner && typeof inner.value === 'string') return inner.value.trim()
    try {
      if (typeof widget.value === 'string') return widget.value.trim()
    } catch (_) {}
    return ''
  }

  function readPlaceField(input, widget) {
    const inner = autocompleteInnerInput(widget)
    const innerText = inner && typeof inner.value === 'string' ? inner.value.trim() : null
    const nativeText = input && input.value ? String(input.value).trim() : ''
    if (innerText) return innerText
    if (nativeText) return nativeText
    return innerText === '' ? '' : widgetValue(widget)
  }

  function writePlaceField(input, widget, value) {
    value = String(value || '')
    if (input) input.value = value
    if (!widget) return
    try { widget.value = value } catch (_) {}
    try {
      if ('inputValue' in widget) widget.inputValue = value
    } catch (_) {}
    const inner = autocompleteInnerInput(widget)
    if (inner) inner.value = value
  }

  function currentFromValue() {
    return readPlaceField(fromInput, placeWidgets.from)
  }

  function currentToValue() {
    return readPlaceField(toInput, placeWidgets.to)
  }

  window.setTransitPlaceField = function setTransitPlaceField(which, value) {
    if (which === 'to') writePlaceField(toInput, placeWidgets.to, value)
    else writePlaceField(fromInput, placeWidgets.from, value)
  }

  function closeModal() {
    if (modal) modal.setAttribute('hidden', '')
  }

  function openAddModal() {
    modalTitle.textContent = t.addFavorite
    submitBtn.textContent = t.save
    favoriteIdInput.value = ''
    form.action = '/transit'
    form.querySelector('#transit-favorite-name').value = ''
    form.querySelector('#transit-favorite-from').value = ''
    form.querySelector('#transit-favorite-to').value = ''
    form.querySelector('#transit-favorite-line').value = ''
    form.querySelector('#transit-favorite-notes').value = ''
    const categorySelect = form.querySelector('#transit-favorite-category')
    if (categorySelect && categorySelect.options && categorySelect.options.length) {
      categorySelect.value = categorySelect.options[0].value
    }
    if (modal) modal.removeAttribute('hidden')
  }

  function openEditModal(data) {
    modalTitle.textContent = t.editFavorite
    submitBtn.textContent = t.update
    favoriteIdInput.value = String(data.id)
    form.action = '/transit/' + data.id + '/update'
    if (form.querySelector('#transit-favorite-category') && data.category) {
      form.querySelector('#transit-favorite-category').value = data.category
    }
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
  const preferredOperatorInput = document.getElementById('transit-preferred-operator')
  const operatorOpenBtn = document.getElementById('transit-operator-open')
  const operatorLabel = document.getElementById('transit-operator-label')
  const operatorModal = document.getElementById('transit-operator-modal')
  const operatorFilter = document.getElementById('transit-operator-filter')
  const operatorList = document.getElementById('transit-operator-list')
  const operatorEmpty = document.getElementById('transit-operator-empty')
  const noneOperator = config.noneOperator || '指定なし'
  const OPERATOR_STORAGE_KEY = 'transit-preferred-operator'
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

  function currentPreferredOperator() {
    return preferredOperatorInput ? String(preferredOperatorInput.value || '').trim() : ''
  }

  function operatorItemById(id) {
    if (!operatorList) return null
    const safe = String(id).replace(/"/g, '')
    return operatorList.querySelector('.transit-operator-item[data-operator-id="' + safe + '"]')
  }

  function highlightSelectedOperator() {
    if (!operatorList) return
    const current = currentPreferredOperator()
    operatorList.querySelectorAll('.transit-operator-item').forEach(function (item) {
      const id = item.getAttribute('data-operator-id') || ''
      item.classList.toggle('is-selected', id === current)
    })
  }

  function applyPreferredOperator(id, nameHint) {
    id = String(id || '').trim()
    const item = id ? operatorItemById(id) : operatorList ? operatorList.querySelector('.transit-operator-item.is-none') : null
    if (id && !item) {
      id = ''
    }
    const name = id
      ? ((item && item.getAttribute('data-operator-name')) || nameHint || noneOperator)
      : noneOperator
    if (preferredOperatorInput) preferredOperatorInput.value = id
    if (operatorLabel) operatorLabel.textContent = name
    if (operatorOpenBtn) operatorOpenBtn.classList.toggle('is-set', !!id)
    highlightSelectedOperator()
    try {
      localStorage.setItem(OPERATOR_STORAGE_KEY, id)
    } catch (_) {}
  }

  function preferredOperatorBadge(it) {
    if (!it || !it.usesPreferredOperator) return ''
    const name = it.preferredOperatorName || ''
    const suffix = t.operatorPreferred || t.nishitetsuPreferred || '優先'
    const label = name ? name + suffix : suffix
    return '<span class="transit-itinerary-badge is-preferred">' + escapeHtml(label) + '</span>'
  }

  function filterOperatorList() {
    if (!operatorList) return
    const query = (operatorFilter && operatorFilter.value ? operatorFilter.value : '').trim().toLowerCase()
    let regionEl = null
    let regionVisible = false
    let anyOperator = false
    Array.from(operatorList.children).forEach(function (el) {
      if (el.hasAttribute('data-operator-region')) {
        if (regionEl) regionEl.hidden = !regionVisible
        regionEl = el
        regionVisible = false
        return
      }
      if (el.classList.contains('is-none')) {
        el.hidden = false
        return
      }
      const hay = (el.getAttribute('data-operator-search') || '').toLowerCase()
      const show = query === '' || hay.indexOf(query) !== -1
      el.hidden = !show
      if (show) {
        regionVisible = true
        anyOperator = true
      }
    })
    if (regionEl) regionEl.hidden = !regionVisible
    if (operatorEmpty) operatorEmpty.hidden = query === '' || anyOperator
  }

  function openOperatorModal() {
    if (!operatorModal) return
    if (operatorFilter) operatorFilter.value = ''
    filterOperatorList()
    highlightSelectedOperator()
    operatorModal.removeAttribute('hidden')
    if (operatorOpenBtn) operatorOpenBtn.setAttribute('aria-expanded', 'true')
    if (operatorFilter) operatorFilter.focus()
  }

  function closeOperatorModal() {
    if (operatorModal) operatorModal.setAttribute('hidden', '')
    if (operatorOpenBtn) operatorOpenBtn.setAttribute('aria-expanded', 'false')
  }

  try {
    applyPreferredOperator(localStorage.getItem(OPERATOR_STORAGE_KEY) || '')
  } catch (_) {
    applyPreferredOperator('')
  }

  operatorOpenBtn?.addEventListener('click', openOperatorModal)
  document.querySelectorAll('[data-close-transit-operator]').forEach(function (el) {
    el.addEventListener('click', closeOperatorModal)
  })
  operatorFilter?.addEventListener('input', filterOperatorList)
  operatorList?.addEventListener('click', function (e) {
    const item = e.target.closest('.transit-operator-item')
    if (!item || !operatorList.contains(item)) return
    applyPreferredOperator(item.getAttribute('data-operator-id') || '', item.getAttribute('data-operator-name') || '')
    closeOperatorModal()
  })
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && operatorModal && !operatorModal.hasAttribute('hidden')) {
      closeOperatorModal()
    }
  })

  function renderItineraries(data) {
    if (!itinerariesBox) return
    const list = data.itineraries || []
    if (!data.ok) {
      itinerariesBox.innerHTML = '<p class="hint transit-raptor-error">' + escapeHtml(data.message || t.noRoute) + '</p>'
      return
    }
    if (list.length === 0) {
      itinerariesBox.innerHTML = '<p class="hint">' + escapeHtml(t.noMatchingRoute) + '</p>'
      return
    }
    const note = data.engineNote
      ? '<p class="hint transit-engine-note">' + escapeHtml(data.engineNote) + '</p>'
      : ''
    itinerariesBox.innerHTML = note + list.map(function (it, index) {
      const badge = preferredOperatorBadge(it)
      const legs = (it.legs || []).map(function (leg) {
        if (leg.type === 'walk') {
          return '<li class="is-walk">' + escapeHtml(t.walk) + ' ' + escapeHtml(leg.from) + ' → ' + escapeHtml(leg.to) + '（' + escapeHtml(String(Math.round((leg.durationSec || 0) / 60))) + escapeHtml(t.min) + '）</li>'
        }
        return '<li class="is-ride"><strong>' + escapeHtml(leg.routeName || '') + '</strong> '
          + escapeHtml(leg.boardTime || '') + ' ' + escapeHtml(leg.from || '')
          + ' → ' + escapeHtml(leg.alightTime || '') + ' ' + escapeHtml(leg.to || '')
          + (leg.waitSec ? ' <span class="hint">' + escapeHtml(t.wait) + Math.round(leg.waitSec / 60) + escapeHtml(t.min) + '</span>' : '')
          + '</li>'
      }).join('')
      return '<article class="transit-itinerary-card">'
        + '<div class="transit-itinerary-head">'
        + '<strong>#' + (index + 1) + ' ' + escapeHtml(it.summary || '') + '</strong>'
        + badge
        + '<button type="button" class="text-btn transit-share-itinerary" data-share-index="' + index + '">' + escapeHtml(t.share) + '</button>'
        + '</div>'
        + '<div class="transit-itinerary-meta">'
        + escapeHtml(it.departureTime) + ' → ' + escapeHtml(it.arrivalTime)
        + ' · ' + escapeHtml(it.durationLabel)
        + ' · ' + escapeHtml(t.waitPrefix) + ' ' + escapeHtml(it.waitLabel || t.zeroMin)
        + ' · ' + escapeHtml(t.transfers) + ' ' + escapeHtml(String(it.transfers || 0)) + escapeHtml(t.times)
        + ' · ' + escapeHtml(it.fareLabel || '')
        + '</div>'
        + '<ol class="transit-itinerary-legs">' + legs + '</ol>'
        + '</article>'
    }).join('')
    itinerariesBox.querySelectorAll('[data-share-index]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openShareModal(parseInt(btn.getAttribute('data-share-index'), 10) || 0)
      })
    })
  }

  function appendExternalLinks(fromRaw, toRaw, from, to) {
    const timeParams = yahooTimeParams()
    linksBox.innerHTML = ''
    const title = document.createElement('h4')
    title.className = 'transit-external-title'
    title.textContent = t.externalTitle
    linksBox.appendChild(title)
    linksBox.appendChild(makeLink(
      t.googleMapsRoute,
      'https://www.google.com/maps/dir/?api=1&travelmode=transit&origin=' + enc(fromRaw) + '&destination=' + enc(toRaw),
      false
    ))
    linksBox.appendChild(makeLink(
      t.yahooRoute,
      'https://transit.yahoo.co.jp/search/result?from=' + enc(from) + '&to=' + enc(to) + timeParams,
      false
    ))
  }

  async function runRouteSearch(from, to) {
    const hour = hourSelect ? hourSelect.value : '08'
    const minute = minuteSelect ? minuteSelect.value : '00'
    const year = yearSelect ? yearSelect.value : String(new Date().getFullYear())
    const month = monthSelect ? pad2(parseInt(monthSelect.value, 10)) : '01'
    const day = daySelect ? pad2(parseInt(daySelect.value, 10)) : '01'
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
        preferredOperator: currentPreferredOperator(),
        preferNishitetsuBus: currentPreferredOperator() === 'nishitetsu_bus',
        minTransferMin: 2,
        maxTransferWaitMin: 10,
        departureAt: departureAt,
        timeType: selectedTimeType() === '4' ? 'arrival' : 'departure',
        hour: parseInt(hour, 10),
        minute: parseInt(minute, 10),
      }),
    })
    return response.json()
  }

  async function runSearch() {
    const fromRaw = currentFromValue()
    const toRaw = currentToValue()

    if (!fromRaw && !toRaw) {
      alert(t.needFrom)
      return
    }

    const from = cleanForTransit(fromRaw)
    const to = cleanForTransit(toRaw)
    linksBox.innerHTML = ''
    if (itinerariesBox) itinerariesBox.innerHTML = '<p class="hint">' + escapeHtml(t.searching) + '</p>'
    resultsBox.removeAttribute('hidden')

    if (fromRaw && toRaw) {
      resultsTitle.textContent = from + ' → ' + to
      try {
        const data = await runRouteSearch(from, to)
        resultsTitle.textContent = from + ' → ' + to + '（' + (data.engine || 'RAPTOR') + '）'
        rememberResult(from, to, data)
        renderItineraries(data)
      } catch (err) {
        if (itinerariesBox) {
          itinerariesBox.innerHTML = '<p class="hint transit-raptor-error">' + escapeHtml(t.searchFailed) + '</p>'
        }
      }
      appendExternalLinks(fromRaw, toRaw, from, to)
      return
    }

    const placeRaw = fromRaw || toRaw
    const place = from || to
    resultsTitle.textContent = (t.timetableFor || ':place の時刻表・地図').replace(':place', place)
    if (itinerariesBox) {
      itinerariesBox.innerHTML = '<p class="hint">' + escapeHtml(t.enterDestinationHint) + '</p>'
    }
    const timeParams = yahooTimeParams()
    linksBox.appendChild(makeLink(
      t.googleMapsOpen,
      'https://www.google.com/maps/search/?api=1&query=' + enc(placeRaw),
      true
    ))
    linksBox.appendChild(makeLink(
      t.yahooTimetable,
      'https://transit.yahoo.co.jp/search/result?from=' + enc(place) + timeParams,
      false
    ))
  }

  if (searchForm) {
    searchForm.addEventListener('submit', function (e) {
      e.preventDefault()
      runSearch()
    })
  }

  const swapPlacesBtn = document.getElementById('transit-places-swap')
  if (swapPlacesBtn) {
    swapPlacesBtn.addEventListener('click', function () {
      const fromVal = currentFromValue()
      const toVal = currentToValue()
      writePlaceField(fromInput, placeWidgets.from, toVal)
      writePlaceField(toInput, placeWidgets.to, fromVal)
      swapPlacesBtn.classList.remove('is-spin')
      void swapPlacesBtn.offsetWidth
      swapPlacesBtn.classList.add('is-spin')
    })
  }

  const voiceStatus = document.getElementById('transit-voice-status')
  function bindPlaceMic(button, slot) {
    if (!button || !window.Sa2SpeechDictation) return
    window.Sa2SpeechDictation.bind(button, {
      replace: true,
      getValue: function () { return slot === 'to' ? currentToValue() : currentFromValue() },
      setValue: function (text) { window.setTransitPlaceField(slot, text) },
      onListening: function (on) {
        if (voiceStatus) voiceStatus.textContent = on ? t.voiceListening : ''
      },
      onError: function (text) {
        if (voiceStatus) voiceStatus.textContent = text
      },
      messages: {
        unsupported: t.voiceUnsupported,
        https: t.voiceHttps,
        startFailed: t.voiceStartFailed,
        micDenied: t.voiceMicDenied,
      },
    })
  }
  bindPlaceMic(document.getElementById('transit-from-mic'), 'from')
  bindPlaceMic(document.getElementById('transit-to-mic'), 'to')


  const saveSearchBtn = document.getElementById('transit-save-search')
  if (saveSearchBtn) {
    saveSearchBtn.addEventListener('click', function () {
      const from = cleanForTransit(fromInput ? currentFromValue() : '')
      const to = cleanForTransit(toInput ? currentToValue() : '')
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

  const shareTargets = Array.isArray(config.shareTargets) ? config.shareTargets : []
  const shareModal = document.getElementById('transit-share-modal')
  const shareForm = document.getElementById('transit-share-form')
  const shareGroup = document.getElementById('transit-share-group')
  const sharePeer = document.getElementById('transit-share-peer')
  const shareItinerary = document.getElementById('transit-share-itinerary')
  const shareNote = document.getElementById('transit-share-note')
  const shareStatus = document.getElementById('transit-share-status')
  const shareSubmit = document.getElementById('transit-share-submit')

  function rememberResult(from, to, data) {
    window.__transitLastResult = {
      from: from || '',
      to: to || '',
      itineraries: (data && data.itineraries) || [],
    }
  }

  function fillSharePeers() {
    if (!sharePeer || !shareGroup) return
    const groupId = parseInt(shareGroup.value, 10)
    const group = shareTargets.find(function (item) { return Number(item.id) === groupId })
    const members = (group && group.members) || []
    sharePeer.innerHTML = '<option value="">' + escapeHtml(t.groupAll) + '</option>'
    members.forEach(function (member) {
      const opt = document.createElement('option')
      opt.value = String(member.userId)
      opt.textContent = member.displayName
      sharePeer.appendChild(opt)
    })
  }

  function fillShareItineraries(selectedIndex) {
    if (!shareItinerary) return
    const items = (window.__transitLastResult && window.__transitLastResult.itineraries) || []
    shareItinerary.innerHTML = ''
    items.forEach(function (it, index) {
      const opt = document.createElement('option')
      opt.value = String(index)
      opt.textContent = '#' + (index + 1) + ' ' + (it.summary || it.departureTime || '')
      if (index === selectedIndex) opt.selected = true
      shareItinerary.appendChild(opt)
    })
  }

  function openShareModal(itineraryIndex) {
    const last = window.__transitLastResult
    if (!last || !last.from || !last.to || !(last.itineraries && last.itineraries.length)) {
      alert(t.shareNeedResult)
      return
    }
    fillSharePeers()
    fillShareItineraries(typeof itineraryIndex === 'number' ? itineraryIndex : 0)
    if (shareNote) shareNote.value = ''
    if (shareStatus) shareStatus.textContent = ''
    if (shareModal) shareModal.removeAttribute('hidden')
  }

  function closeShareModal() {
    if (shareModal) shareModal.setAttribute('hidden', '')
  }

  document.getElementById('transit-share-search')?.addEventListener('click', function () {
    openShareModal(0)
  })
  document.querySelectorAll('[data-close-transit-share]').forEach(function (el) {
    el.addEventListener('click', closeShareModal)
  })
  shareGroup?.addEventListener('change', fillSharePeers)
  shareForm?.addEventListener('submit', async function (e) {
    e.preventDefault()
    const last = window.__transitLastResult
    const items = (last && last.itineraries) || []
    const index = shareItinerary ? parseInt(shareItinerary.value, 10) || 0 : 0
    const itinerary = items[index]
    if (!last || !itinerary) {
      alert(t.shareNeedResult)
      return
    }
    if (shareSubmit) shareSubmit.disabled = true
    if (shareStatus) shareStatus.textContent = t.searching.replace('…', '...')
    try {
      const res = await fetch('/transit/share', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({
          groupId: shareGroup ? parseInt(shareGroup.value, 10) : 0,
          peerUserId: sharePeer && sharePeer.value ? parseInt(sharePeer.value, 10) : null,
          from: last.from,
          to: last.to,
          note: shareNote ? shareNote.value : '',
          itinerary: itinerary,
        }),
      })
      const data = await res.json().catch(function () { return {} })
      if (!data.ok) {
        if (shareStatus) shareStatus.textContent = data.message || t.shareFailed
        return
      }
      if (shareStatus) {
        shareStatus.innerHTML = ''
        const ok = document.createTextNode((data.message || t.shareSent) + ' ')
        shareStatus.appendChild(ok)
        if (data.href) {
          const a = document.createElement('a')
          a.href = data.href
          a.textContent = t.shareOpenChat
          shareStatus.appendChild(a)
        }
      }
    } catch (_) {
      if (shareStatus) shareStatus.textContent = t.shareFailed
    } finally {
      if (shareSubmit) shareSubmit.disabled = false
    }
  })

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

  function attachPlaceAutocomplete(holderId, targetInput, slot) {
    const holder = document.getElementById(holderId)
    if (!holder || !targetInput) return null
    const el = new google.maps.places.PlaceAutocompleteElement({
      includedRegionCodes: ['jp'],
    })
    el.style.width = '100%'
    holder.appendChild(el)
    targetInput.style.display = 'none'
    placeWidgets[slot] = el
    if (targetInput.value) writePlaceField(targetInput, el, targetInput.value)

    const syncTypedValue = function (event) {
      const typed = event && event.target && typeof event.target.value === 'string'
        ? event.target.value
        : widgetValue(el)
      targetInput.value = typed
    }
    el.addEventListener('input', syncTypedValue)
    el.addEventListener('change', syncTypedValue)

    const onPlaceSelect = async function (event) {
      try {
        const prediction = event.placePrediction || event.place
        if (!prediction) {
          syncTypedValue()
          return
        }
        const place = typeof prediction.toPlace === 'function' ? prediction.toPlace() : prediction
        if (place && typeof place.fetchFields === 'function') {
          await place.fetchFields({ fields: ['displayName', 'formattedAddress'] })
        }
        writePlaceField(targetInput, el, resolvePlaceLabel(place, prediction))
      } catch (e) {
        syncTypedValue()
      }
    }
    el.addEventListener('gmp-select', onPlaceSelect)
    el.addEventListener('gmp-placeselect', onPlaceSelect)
    return el
  }

  window.initTransitAutocomplete = async function initTransitAutocomplete() {
    try {
      await google.maps.importLibrary('places')
    } catch (e) {
      return
    }
    if (placeWidgets.from || placeWidgets.to) return
    if (google.maps.places && google.maps.places.PlaceAutocompleteElement) {
      attachPlaceAutocomplete('transit-from-holder', fromInput, 'from')
      attachPlaceAutocomplete('transit-to-holder', toInput, 'to')
    }
  }

  if (window.google && window.google.maps) {
    window.initTransitAutocomplete()
  }

  function currentSearchQuery() {
    const year = yearSelect ? yearSelect.value : String(new Date().getFullYear())
    const month = monthSelect ? pad2(parseInt(monthSelect.value, 10)) : '01'
    const day = daySelect ? pad2(parseInt(daySelect.value, 10)) : '01'
    const hour = hourSelect ? hourSelect.value : '08'
    const minute = minuteSelect ? minuteSelect.value : '00'
    return {
      from: currentFromValue(),
      to: currentToValue(),
      departureAt: year + '-' + month + '-' + day + 'T' + hour + ':' + minute,
      timeType: selectedTimeType() === '4' ? 'arrival' : 'departure',
      preference: preferenceSelect ? preferenceSelect.value : 'fastest',
      preferredOperator: currentPreferredOperator(),
      lastSearch: window.__transitLastAiSearch || null,
    }
  }

  function applyAiSearch(data) {
    const search = data && data.search
    if (!search) return
    window.__transitLastAiSearch = search
    if (search.from) window.setTransitPlaceField('from', search.from)
    if (search.to) window.setTransitPlaceField('to', search.to)
    if (preferenceSelect && search.preference) preferenceSelect.value = search.preference
    if (search.preferredOperator) {
      applyPreferredOperator(search.preferredOperator, search.preferredOperatorName || '')
    }
    if (search.timeType === 'arrival') {
      const arrival = document.querySelector('input[name="transit-time-type"][value="4"]')
      if (arrival) arrival.checked = true
    } else if (search.timeType === 'departure') {
      const dep = document.querySelector('input[name="transit-time-type"][value="1"]')
      if (dep) dep.checked = true
    }
    if (search.departureAt && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(search.departureAt)) {
      const stamp = search.departureAt
      if (yearSelect) yearSelect.value = stamp.slice(0, 4)
      if (monthSelect) monthSelect.value = String(parseInt(stamp.slice(5, 7), 10))
      if (daySelect) daySelect.value = String(parseInt(stamp.slice(8, 10), 10))
      if (hourSelect) hourSelect.value = String(parseInt(stamp.slice(11, 13), 10))
      if (minuteSelect) minuteSelect.value = stamp.slice(14, 16)
    }
    if (resultsBox && (data.searched || (data.itineraries && data.itineraries.length))) {
      resultsBox.removeAttribute('hidden')
      if (resultsTitle) {
        resultsTitle.textContent = (search.from || '') + ' → ' + (search.to || '')
          + (search.engine ? '（' + search.engine + '）' : '')
      }
      renderItineraries({
        ok: !!search.ok,
        message: search.message,
        engine: search.engine,
        engineNote: search.engineNote,
        itineraries: data.itineraries || [],
      })
      rememberResult(search.from || '', search.to || '', {
        ok: !!search.ok,
        engine: search.engine,
        itineraries: data.itineraries || [],
      })
      if (search.from && search.to) {
        appendExternalLinks(search.from, search.to, search.from, search.to)
      }
    }
  }

  window.transitAiContext = currentSearchQuery
  window.transitAiResult = applyAiSearch
})()
