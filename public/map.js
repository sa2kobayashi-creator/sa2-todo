(function () {
  const config = window.MAP_PAGE_CONFIG || {}
  let map = null
  let directionsService = null
  let directionsRenderer = null
  let originAutocomplete = null
  let destinationAutocomplete = null
  let lastOrigin = null
  let lastDestination = null

  const originInput = document.getElementById('map-origin')
  const destinationInput = document.getElementById('map-destination')
  const travelModeSelect = document.getElementById('map-travel-mode')
  const directionsPanel = document.getElementById('map-directions-panel')
  const directionsSteps = document.getElementById('map-directions-steps')

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

  function renderSteps(result) {
    if (!directionsSteps || !directionsPanel) return
    const route = result?.routes?.[0]
    if (!route) {
      directionsPanel.hidden = true
      return
    }
    const leg = route.legs[0]
    directionsSteps.innerHTML = ''
    const summary = document.createElement('p')
    summary.className = 'map-directions-summary'
    summary.textContent = `${leg.distance?.text || ''} / ${leg.duration?.text || ''}`
    directionsSteps.appendChild(summary)
    ;(leg.steps || []).forEach((step) => {
      const item = document.createElement('div')
      item.className = 'map-direction-step'
      item.innerHTML = step.instructions
      directionsSteps.appendChild(item)
    })
    directionsPanel.hidden = false
  }

  function requestRoute() {
    if (!directionsService || !directionsRenderer) {
      window.open(buildGoogleUrl(false), '_blank', 'noopener')
      return
    }

    lastOrigin = readInputLocation(originInput, originAutocomplete)
    lastDestination = readInputLocation(destinationInput, destinationAutocomplete)
    syncSaveForm()

    if (!lastOrigin.label || !lastDestination.label) {
      alert('出発地と目的地を入力してください')
      return
    }

    const request = {
      origin: lastOrigin.lat != null ? { lat: lastOrigin.lat, lng: lastOrigin.lng } : lastOrigin.label,
      destination: lastDestination.lat != null ? { lat: lastDestination.lat, lng: lastDestination.lng } : lastDestination.label,
      travelMode: google.maps.TravelMode[getTravelMode().toUpperCase()] || google.maps.TravelMode.TRANSIT,
    }

    directionsService.route(request, (result, status) => {
      if (status === 'OK') {
        directionsRenderer.setDirections(result)
        renderSteps(result)
      } else {
        alert('ルートを取得できませんでした。Google Maps で開きます。')
        window.open(buildGoogleUrl(false), '_blank', 'noopener')
      }
    })
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

  document.getElementById('map-show-route')?.addEventListener('click', requestRoute)
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
