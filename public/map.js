(function () {
  const config = window.MAP_PAGE_CONFIG || {}
  const strings = config.strings || {}
  let map = null
  let directionsService = null
  let directionsRenderer = null
  let originAutocomplete = null
  let destinationAutocomplete = null
  let lastOrigin = null
  let lastDestination = null
  let routeOverlays = []

  const originInput = document.getElementById('map-origin')
  const destinationInput = document.getElementById('map-destination')
  const travelModeSelect = document.getElementById('map-travel-mode')
  const directionsPanel = document.getElementById('map-results-section')
  const routeResultsBox = document.getElementById('map-route-results')
  const directionsSteps = document.getElementById('map-directions-steps')
  const showRouteBtn = document.getElementById('map-show-route')
  let presentedRoutes = []
  let selectedRouteIndex = 0
  let lastGoogleDirections = null

  function getTravelMode() {
    return travelModeSelect?.value || 'transit'
  }

  function placeToLocation(place) {
    if (!place) return null
    if (place.geometry?.location) {
      return {
        label: place.formatted_address || place.name || '',
        lat: place.geometry.location.lat(),
        lng: place.geometry.location.lng(),
      }
    }
    return { label: place.name || '', lat: null, lng: null }
  }

  function readInputLocation(input, autocomplete) {
    const place = autocomplete?.getPlace?.()
    const fromPlace = placeToLocation(place)
    if (fromPlace?.label) {
      return fromPlace
    }
    return { label: input.value.trim(), lat: null, lng: null }
  }

  function syncSaveForm() {
    const origin = lastOrigin || { label: originInput?.value.trim() || '', lat: null, lng: null }
    const destination = lastDestination || { label: destinationInput?.value.trim() || '', lat: null, lng: null }
    document.getElementById('map-save-origin-label').value = origin.label
    document.getElementById('map-save-destination-label').value = destination.label
    document.getElementById('map-save-origin-lat').value = origin.lat ?? ''
    document.getElementById('map-save-origin-lng').value = origin.lng ?? ''
    document.getElementById('map-save-destination-lat').value = destination.lat ?? ''
    document.getElementById('map-save-destination-lng').value = destination.lng ?? ''
    document.getElementById('map-save-travel-mode').value = getTravelMode()
  }

  function buildGoogleUrl(navigate) {
    const origin = lastOrigin || { label: originInput?.value.trim() || '' }
    const destination = lastDestination || { label: destinationInput?.value.trim() || '' }
    const params = new URLSearchParams({
      api: '1',
      travelmode: getTravelMode(),
      origin: origin.lat != null && origin.lng != null ? `${origin.lat},${origin.lng}` : origin.label,
      destination: destination.lat != null && destination.lng != null ? `${destination.lat},${destination.lng}` : destination.label,
    })
    if (navigate) params.set('dir_action', 'navigate')
    return `https://www.google.com/maps/dir/?${params.toString()}`
  }

  function decodePolyline(encoded) {
    const points = []
    let index = 0
    let lat = 0
    let lng = 0
    const text = String(encoded || '')
    while (index < text.length) {
      let b
      let shift = 0
      let result = 0
      do {
        b = text.charCodeAt(index++) - 63
        result |= (b & 0x1f) << shift
        shift += 5
      } while (b >= 0x20)
      lat += (result & 1) ? ~(result >> 1) : (result >> 1)
      shift = 0
      result = 0
      do {
        b = text.charCodeAt(index++) - 63
        result |= (b & 0x1f) << shift
        shift += 5
      } while (b >= 0x20)
      lng += (result & 1) ? ~(result >> 1) : (result >> 1)
      points.push({ lat: lat / 1e5, lng: lng / 1e5 })
    }
    return points
  }

  function strokeForMode(mode) {
    const m = String(mode || '').toUpperCase()
    if (m === 'WALK' || m === 'WALKING') {
      return { color: '#5f6368', dashed: true }
    }
    if (m === 'BICYCLE' || m === 'BICYCLING') {
      return { color: '#188038', dashed: false }
    }
    return { color: '#1a73e8', dashed: false }
  }

  function clearRouteOverlays() {
    routeOverlays.forEach((line) => line.setMap(null))
    routeOverlays = []
    try {
      directionsRenderer?.setDirections({ routes: [] })
    } catch (_) {}
  }

  function fitToPoints(points) {
    if (!map || !points.length) return
    if (points.length === 1) {
      map.setCenter(points[0])
      map.setZoom(15)
      return
    }
    const bounds = new google.maps.LatLngBounds()
    points.forEach((point) => bounds.extend(point))
    map.fitBounds(bounds, 48)
  }

  function drawEncodedPolyline(encoded, mode) {
    const points = decodePolyline(encoded)
    if (!points.length || !map) return []
    const stroke = strokeForMode(mode)
    const line = new google.maps.Polyline({
      path: points,
      map,
      strokeColor: stroke.color,
      strokeOpacity: 0.95,
      strokeWeight: stroke.dashed ? 4 : 5,
      icons: stroke.dashed
        ? [{
            icon: { path: 'M 0,-1 0,1', strokeOpacity: 1, scale: 3 },
            offset: '0',
            repeat: '12px',
          }]
        : undefined,
    })
    if (stroke.dashed) {
      line.setOptions({ strokeOpacity: 0 })
    }
    routeOverlays.push(line)
    return points
  }

  // Routes API の instructions は HTML 断片で返る。太字などは残しつつ、
  // 属性とタグの許可リストで絞ってから差し込む
  const ALLOWED_STEP_TAGS = new Set(['B', 'STRONG', 'I', 'EM', 'SPAN', 'DIV', 'BR', 'WBR'])

  function appendSanitizedHtml(target, html) {
    const parsed = new DOMParser().parseFromString(String(html), 'text/html')

    const copy = (from, to) => {
      from.childNodes.forEach((node) => {
        if (node.nodeType === Node.TEXT_NODE) {
          to.appendChild(document.createTextNode(node.nodeValue))
          return
        }
        if (node.nodeType !== Node.ELEMENT_NODE) return
        if (!ALLOWED_STEP_TAGS.has(node.tagName)) {
          copy(node, to)
          return
        }
        const clean = document.createElement(node.tagName.toLowerCase())
        copy(node, clean)
        to.appendChild(clean)
      })
    }

    copy(parsed.body, target)
  }

  function renderCustomSteps(steps) {
    if (!directionsSteps) return
    directionsSteps.innerHTML = ''
    ;(steps || []).forEach((step) => {
      const item = document.createElement('div')
      item.className = 'map-direction-step'
      if (step.text) {
        item.textContent = step.text
      } else if (step.instructions) {
        appendSanitizedHtml(item, step.instructions)
      }
      directionsSteps.appendChild(item)
    })
  }

  function renderRouteCards() {
    if (!routeResultsBox) return
    routeResultsBox.innerHTML = ''
    presentedRoutes.forEach((route, index) => {
      const card = document.createElement('button')
      card.type = 'button'
      card.className = 'map-result-card' + (index === selectedRouteIndex ? ' is-selected' : '')
      const duration = document.createElement('p')
      duration.className = 'map-result-duration'
      duration.textContent = route.durationText || route.summary || ''
      const modes = document.createElement('p')
      modes.className = 'map-result-modes'
      modes.textContent = route.modeSummary || ''
      const meta = document.createElement('p')
      meta.className = 'map-result-meta'
      meta.textContent = [
        route.departureTime && route.arrivalTime ? `${route.departureTime} → ${route.arrivalTime}` : '',
        route.fareText,
        route.distanceText,
      ].filter(Boolean).join(' · ')
      card.appendChild(duration)
      if (modes.textContent) card.appendChild(modes)
      if (meta.textContent) card.appendChild(meta)
      card.addEventListener('click', () => selectPresentedRoute(index))
      routeResultsBox.appendChild(card)
    })
  }

  function drawPresentedRoute(route) {
    clearRouteOverlays()
    const lines = Array.isArray(route?.polylines) ? route.polylines.filter((line) => line?.encoded) : []
    let points = []
    if (lines.length) {
      lines.forEach((line) => {
        points = points.concat(drawEncodedPolyline(line.encoded, line.mode))
      })
    } else if (route?.polyline) {
      points = drawEncodedPolyline(route.polyline, getTravelMode())
    }
    fitToPoints(points)
    renderCustomSteps(route?.steps || [])
  }

  function selectPresentedRoute(index) {
    const route = presentedRoutes[index]
    if (!route) return
    selectedRouteIndex = index
    renderRouteCards()
    if (route.googleRoute && lastGoogleDirections && directionsRenderer) {
      clearRouteOverlays()
      directionsRenderer.setDirections(lastGoogleDirections)
      directionsRenderer.setRouteIndex(index)
      renderCustomSteps(route.steps)
      return
    }
    lastGoogleDirections = null
    drawPresentedRoute(route)
  }

  function showResultsPanel() {
    if (directionsPanel) directionsPanel.hidden = false
  }

  function showRouteError(message) {
    clearRouteOverlays()
    presentedRoutes = []
    if (routeResultsBox) routeResultsBox.innerHTML = ''
    if (!directionsSteps || !directionsPanel) {
      window.alert(message)
      return
    }
    directionsSteps.innerHTML = ''
    const text = document.createElement('p')
    text.className = 'hint'
    text.textContent = message
    const link = document.createElement('a')
    link.href = buildGoogleUrl(false)
    link.target = '_blank'
    link.rel = 'noopener'
    link.textContent = strings.openInGoogleMaps || 'Google Maps'
    directionsSteps.appendChild(text)
    directionsSteps.appendChild(link)
    showResultsPanel()
  }

  function drawRoutesResult(result) {
    const list = Array.isArray(result.routes) && result.routes.length
      ? result.routes
      : [{
          summary: result.summary || '',
          durationText: result.summary || '',
          distanceText: '',
          fareText: '',
          modeSummary: '',
          steps: result.steps || [],
          polylines: result.polylines || [],
          polyline: result.polyline || '',
        }]
    presentedRoutes = list
    selectedRouteIndex = 0
    lastGoogleDirections = null
    showResultsPanel()
    const noteEl = document.getElementById('map-results-note')
    if (noteEl) {
      const note = String(result.engineNote || '')
      noteEl.textContent = note
      noteEl.hidden = note === ''
    }
    renderRouteCards()
    selectPresentedRoute(0)
  }

  function googleResultToPresented(result) {
    return (result?.routes || []).map((route) => {
      const leg = route.legs?.[0] || {}
      const steps = (leg.steps || []).map((step) => ({
        text: '',
        instructions: step.instructions || '',
        mode: step.travel_mode || '',
      }))
      return {
        summary: `${leg.duration?.text || ''} / ${leg.distance?.text || ''}`.trim(),
        durationText: leg.duration?.text || '',
        distanceText: leg.distance?.text || '',
        fareText: '',
        modeSummary: (route.summary || '').replace(/,/g, ' → '),
        steps,
        polylines: [],
        polyline: '',
        googleRoute: route,
      }
    })
  }

  function renderGoogleSteps(result) {
    presentedRoutes = googleResultToPresented(result)
    selectedRouteIndex = 0
    showResultsPanel()
    renderRouteCards()
    const route = presentedRoutes[0]
    if (!route) {
      if (directionsPanel) directionsPanel.hidden = true
      return
    }
    renderCustomSteps(route.steps)
  }

  async function fetchMapDirections() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || ''
    const res = await fetch('/map/directions', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrf,
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        originLabel: lastOrigin.label,
        originLat: lastOrigin.lat,
        originLng: lastOrigin.lng,
        destinationLabel: lastDestination.label,
        destinationLat: lastDestination.lat,
        destinationLng: lastDestination.lng,
        travelMode: getTravelMode(),
      }),
    })
    return res.json().catch(() => ({}))
  }

  function requestLegacyDirections() {
    if (!directionsService || !directionsRenderer) {
      showRouteError(strings.routeFailed || '')
      return
    }
    const request = {
      origin: lastOrigin.lat != null ? { lat: lastOrigin.lat, lng: lastOrigin.lng } : lastOrigin.label,
      destination: lastDestination.lat != null ? { lat: lastDestination.lat, lng: lastDestination.lng } : lastDestination.label,
      travelMode: google.maps.TravelMode[getTravelMode().toUpperCase()] || google.maps.TravelMode.TRANSIT,
    }
    directionsService.route(request, (result, status) => {
      if (status === 'OK') {
        lastGoogleDirections = result
        clearRouteOverlays()
        directionsRenderer.setDirections(result)
        renderGoogleSteps(result)
        return
      }
      if (status === 'REQUEST_DENIED') {
        showRouteError(strings.directionsDenied || strings.routeFailed || status)
        return
      }
      showRouteError((strings.routeFailed || '') + (status ? ` (${status})` : ''))
    })
  }

  async function requestRoute() {
    lastOrigin = readInputLocation(originInput, originAutocomplete)
    lastDestination = readInputLocation(destinationInput, destinationAutocomplete)
    syncSaveForm()

    if (!lastOrigin.label || !lastDestination.label) {
      window.alert(strings.needPlaces || '')
      return
    }

    if (showRouteBtn) {
      showRouteBtn.disabled = true
    }
    if (directionsSteps && directionsPanel) {
      directionsPanel.hidden = false
      directionsSteps.textContent = strings.loading || ''
    }

    try {
      const data = await fetchMapDirections()
      if (data?.ok) {
        drawRoutesResult(data)
        return
      }
      if (data?.fallback) {
        requestLegacyDirections()
        return
      }
      showRouteError(data?.message || strings.routeFailed || '')
    } catch (_) {
      showRouteError(strings.routeFailed || '')
    } finally {
      if (showRouteBtn) {
        showRouteBtn.disabled = false
      }
    }
  }

  function loadSavedRoute(route) {
    if (!route) return
    originInput.value = route.originLabel || ''
    destinationInput.value = route.destinationLabel || ''
    travelModeSelect.value = route.travelMode || 'transit'
    lastOrigin = {
      label: route.originLabel,
      lat: route.originLat,
      lng: route.originLng,
    }
    lastDestination = {
      label: route.destinationLabel,
      lat: route.destinationLat,
      lng: route.destinationLng,
    }
    document.getElementById('map-save-name').value = route.name || ''
    syncSaveForm()
    requestRoute()
  }

  window.initMapPage = function initMapPage() {
    const center = config.defaultCenter || { lat: 33.5904, lng: 130.4017 }
    map = new google.maps.Map(document.getElementById('map-canvas'), {
      center,
      zoom: 13,
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: true,
    })
    directionsService = new google.maps.DirectionsService()
    directionsRenderer = new google.maps.DirectionsRenderer({
      map,
      suppressMarkers: false,
    })

    originAutocomplete = new google.maps.places.Autocomplete(originInput, {
      componentRestrictions: { country: 'jp' },
      fields: ['formatted_address', 'geometry', 'name'],
    })
    destinationAutocomplete = new google.maps.places.Autocomplete(destinationInput, {
      componentRestrictions: { country: 'jp' },
      fields: ['formatted_address', 'geometry', 'name'],
    })

    originAutocomplete.addListener('place_changed', () => {
      lastOrigin = readInputLocation(originInput, originAutocomplete)
      syncSaveForm()
    })
    destinationAutocomplete.addListener('place_changed', () => {
      lastDestination = readInputLocation(destinationInput, destinationAutocomplete)
      syncSaveForm()
    })

    if (config.selectedRoute) {
      loadSavedRoute(config.selectedRoute)
    }
  }

  showRouteBtn?.addEventListener('click', requestRoute)
  document.getElementById('map-start-nav')?.addEventListener('click', () => {
    lastOrigin = lastOrigin || readInputLocation(originInput, originAutocomplete)
    lastDestination = lastDestination || readInputLocation(destinationInput, destinationAutocomplete)
    window.open(buildGoogleUrl(true), '_blank', 'noopener')
  })

  document.querySelectorAll('.map-load-route-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      const card = btn.closest('.map-route-card')
      loadSavedRoute(JSON.parse(card.dataset.route))
    })
  })

  document.getElementById('map-save-form')?.addEventListener('submit', () => {
    syncSaveForm()
  })

  if (!config.hasApiKey && config.selectedRoute) {
    loadSavedRoute(config.selectedRoute)
  }
})()
