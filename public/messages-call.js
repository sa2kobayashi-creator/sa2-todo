/**
 * LiveKit DM通話 + 着信通知（ポーリング連携）
 */
(function () {
  var callButton = document.getElementById('message-call-btn')
  var dialog = document.getElementById('message-call-dialog')
  var incomingDialog = document.getElementById('message-call-incoming-dialog')
  var status = document.getElementById('message-call-status')
  var localContainer = document.getElementById('message-call-local-video')
  var remoteContainer = document.getElementById('message-call-remote-videos')
  var remoteLabel = document.getElementById('message-call-remote-label')
  var micButton = document.getElementById('message-call-mic')
  var cameraButton = document.getElementById('message-call-camera')
  var cameraSelect = document.getElementById('message-call-camera-select')
  var endButton = document.getElementById('message-call-end')
  var acceptButton = document.getElementById('message-call-accept')
  var declineButton = document.getElementById('message-call-decline')
  var incomingFrom = document.getElementById('message-call-incoming-from')
  var csrfMeta = document.querySelector('meta[name="csrf-token"]')
  var csrf = csrfMeta ? csrfMeta.getAttribute('content') || '' : ''
  var room = null
  var livekit = null
  var micEnabled = true
  var cameraEnabled = true
  var inCall = false
  var isCaller = false
  var activeIncoming = null
  var ringTimer = 0
  var audioCtx = null
  var ringNodes = []

  function setStatus(text) {
    if (status) status.textContent = text
  }

  function clearTracks(container) {
    if (container) container.replaceChildren()
  }

  function openEl(el) {
    if (!el) return
    try {
      if (typeof el.showModal === 'function' && !el.open) {
        el.showModal()
        return
      }
    } catch (_) {}
    el.setAttribute('open', 'open')
    el.classList.add('is-open')
  }

  function closeEl(el) {
    if (!el) return
    try {
      if (typeof el.close === 'function' && el.open) el.close()
    } catch (_) {}
    el.removeAttribute('open')
    el.classList.remove('is-open')
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body ? JSON.stringify(body) : '{}',
    }).then(function (res) {
      return res.json().then(function (payload) {
        return { ok: res.ok, status: res.status, payload: payload || {} }
      })
    })
  }

  function stopRingtone() {
    clearInterval(ringTimer)
    ringTimer = 0
    ringNodes.forEach(function (node) {
      try { node.stop() } catch (_) {}
      try { node.disconnect() } catch (_) {}
    })
    ringNodes = []
    try {
      if (navigator.vibrate) navigator.vibrate(0)
    } catch (_) {}
  }

  function playPhoneWarble(start, duration) {
    // LFO→AudioParam は端末によって「ピピピ」化するので、
    // 440+480Hz を手動トレモロ（約20Hz）で揺らして「プルプル」にする。
    var master = audioCtx.createGain()
    master.gain.setValueAtTime(0, start)
    master.connect(audioCtx.destination)

    ;[440, 480].forEach(function (freq) {
      var osc = audioCtx.createOscillator()
      osc.type = 'sine'
      osc.frequency.setValueAtTime(freq, start)
      osc.connect(master)
      osc.start(start)
      osc.stop(start + duration + 0.05)
      ringNodes.push(osc)
    })

    var pulse = 1 / 20
    var t = start
    var end = start + duration
    master.gain.setValueAtTime(0.0001, t)
    while (t < end) {
      var peakAt = t + 0.012
      var midAt = t + pulse * 0.45
      var lowAt = t + pulse
      if (peakAt > end) break
      master.gain.linearRampToValueAtTime(0.3, Math.min(peakAt, end))
      if (midAt < end) master.gain.linearRampToValueAtTime(0.12, midAt)
      if (lowAt < end) master.gain.linearRampToValueAtTime(0.02, lowAt)
      t = lowAt
    }
    master.gain.linearRampToValueAtTime(0.0001, end)
  }

  function beepOnce() {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)()
      if (audioCtx.state === 'suspended') audioCtx.resume()
      var now = audioCtx.currentTime
      // プルプル～ → 休み → プルプル～
      playPhoneWarble(now, 1.05)
      playPhoneWarble(now + 1.4, 1.05)
      try {
        if (navigator.vibrate) navigator.vibrate([1000, 300, 1000])
      } catch (_) {}
    } catch (_) {}
  }

  function startRingtone() {
    stopRingtone()
    beepOnce()
    ringTimer = setInterval(beepOnce, 4200)
  }

  var heldLocalTracks = null
  var heldMediaStream = null
  var selectedCameraId = ''
  var publishedVideoTrack = null
  var switchingCamera = false

  function isVirtualCameraLabel(label) {
    return /virtual|obs|ivcam|droid|snap|manycam|ndi|phone link|phonelink|スマホ|スマートフォン|mobile.?cam|iriun|epoccam|camo|streamlabs|xsplit|splitcam|youcam|nvidia broadcast|continuity|continuity camera|iphone|android.?cam|appmanager/i.test(String(label || ''))
  }

  function cameraScore(device) {
    var label = String(device && device.label || '')
    if (isVirtualCameraLabel(label)) return -100
    var score = 10
    if (/integrated|built-?in|内蔵|laptop|surface|hd webcam|usb.?camera|logitech|facetime/i.test(label)) score += 20
    if (/front|user|facing/i.test(label)) score += 5
    return score
  }

  function sortCameras(cameras) {
    return cameras.slice().sort(function (a, b) {
      return cameraScore(b) - cameraScore(a)
    })
  }

  async function listCameras() {
    var devices = await navigator.mediaDevices.enumerateDevices()
    return devices.filter(function (d) { return d.kind === 'videoinput' && d.deviceId })
  }

  async function fillCameraSelect(selectedId) {
    if (!cameraSelect) return
    var cameras = sortCameras(await listCameras())
    cameraSelect.innerHTML = ''
    if (!cameras.length) {
      var empty = document.createElement('option')
      empty.value = ''
      empty.textContent = 'カメラが見つかりません'
      cameraSelect.appendChild(empty)
      return
    }
    cameras.forEach(function (cam, index) {
      var option = document.createElement('option')
      option.value = cam.deviceId
      var mark = isVirtualCameraLabel(cam.label) ? '（仮想） ' : ''
      option.textContent = mark + (cam.label || ('カメラ ' + (index + 1)))
      cameraSelect.appendChild(option)
    })
    var pick = selectedId || selectedCameraId || cameras[0].deviceId
    var exists = cameras.some(function (cam) { return cam.deviceId === pick })
    cameraSelect.value = exists ? pick : cameras[0].deviceId
    selectedCameraId = cameraSelect.value
  }

  function currentVideoTrack() {
    if (!heldMediaStream) return null
    var tracks = heldMediaStream.getVideoTracks()
    return tracks.length ? tracks[0] : null
  }

  function setLocalPlaceholder(message) {
    if (!localContainer) return
    var tip = localContainer.querySelector('.msg-call-local-tip')
    if (!message) {
      if (tip) tip.remove()
      return
    }
    if (!tip) {
      tip = document.createElement('div')
      tip.className = 'msg-call-local-tip'
      localContainer.appendChild(tip)
    }
    tip.textContent = message
  }

  function attachNativeLocalPreview(stream) {
    if (!localContainer || !stream) return null
    clearTracks(localContainer)
    var videoTracks = stream.getVideoTracks()
    if (!videoTracks.length) {
      setLocalPlaceholder('カメラ映像を取得できませんでした')
      return null
    }
    var videoEl = document.createElement('video')
    videoEl.className = 'is-local'
    videoEl.muted = true
    videoEl.defaultMuted = true
    videoEl.autoplay = true
    videoEl.playsInline = true
    videoEl.setAttribute('muted', 'true')
    videoEl.setAttribute('playsinline', 'true')
    videoEl.setAttribute('autoplay', 'true')
    videoEl.srcObject = new MediaStream([videoTracks[0]])
    videoEl.style.cssText = 'width:100%;height:100%;object-fit:cover;background:#0b1614;'
    localContainer.appendChild(videoEl)
    var playPromise = videoEl.play && videoEl.play()
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch(function () {})
    }
    var label = videoTracks[0].label || ''
    setTimeout(function () {
      if (!videoEl.videoWidth) {
        if (isVirtualCameraLabel(label)) {
          setLocalPlaceholder('仮想カメラが選ばれています。下の一覧でPC本体のカメラを選んでください。')
        } else {
          setLocalPlaceholder('映像がありません。下の一覧で別のカメラを試してください。')
        }
      } else {
        setLocalPlaceholder('')
      }
    }, 900)
    return videoEl
  }

  function attachTrack(track, container, isLocal) {
    if (!track || !container) return
    if (isLocal && track.kind === 'audio') return

    var element = track.attach()
    if (element.tagName === 'VIDEO') {
      element.autoplay = true
      element.playsInline = true
      element.setAttribute('playsinline', 'true')
      if (isLocal) {
        element.muted = true
        element.defaultMuted = true
        element.setAttribute('muted', 'true')
        element.classList.add('is-local')
      }
      var playPromise = element.play && element.play()
      if (playPromise && typeof playPromise.catch === 'function') {
        playPromise.catch(function () {})
      }
    }
    container.appendChild(element)
  }

  function stopVideoOnly() {
    if (!heldMediaStream) return
    heldMediaStream.getVideoTracks().forEach(function (track) {
      try { track.stop() } catch (_) {}
      try { heldMediaStream.removeTrack(track) } catch (_) {}
    })
  }

  function stopHeldLocalTracks() {
    if (heldLocalTracks) {
      heldLocalTracks.forEach(function (track) {
        try {
          track.detach().forEach(function (el) { el.remove() })
        } catch (_) {}
        try { track.stop() } catch (_) {}
      })
      heldLocalTracks = null
    }
    if (heldMediaStream) {
      heldMediaStream.getTracks().forEach(function (track) {
        try { track.stop() } catch (_) {}
      })
      heldMediaStream = null
    }
    publishedVideoTrack = null
  }

  function setLocalTrackEnabled(kind, enabled) {
    if (heldMediaStream) {
      heldMediaStream.getTracks().forEach(function (track) {
        if (track.kind === kind) track.enabled = !!enabled
      })
    }
    var videoEl = localContainer ? localContainer.querySelector('video.is-local') : null
    if (kind === 'video' && videoEl) {
      videoEl.style.opacity = enabled ? '1' : '0.2'
    }
  }

  async function ensureMediaPermission() {
    // ラベル取得のため一度許可を取る（Meet と同様）
    var warm = await navigator.mediaDevices.getUserMedia({ audio: true, video: true })
    warm.getTracks().forEach(function (track) {
      try { track.stop() } catch (_) {}
    })
  }

  async function openCameraById(deviceId, keepAudio) {
    var audioTrack = keepAudio && heldMediaStream
      ? heldMediaStream.getAudioTracks()[0]
      : null
    var stream = await navigator.mediaDevices.getUserMedia({
      audio: audioTrack ? false : true,
      video: {
        deviceId: { exact: deviceId },
        width: { ideal: 1280 },
        height: { ideal: 720 },
      },
    })
    if (audioTrack) {
      stream.addTrack(audioTrack)
    }
    return stream
  }

  async function openLocalMedia() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      throw new Error('このブラウザではカメラを利用できません。')
    }

    await ensureMediaPermission()
    var cameras = sortCameras(await listCameras())
    if (!cameras.length) throw new Error('利用できるカメラがありません。')

    var preferred = cameras.find(function (cam) { return !isVirtualCameraLabel(cam.label) }) || cameras[0]
    var candidates = preferred
      ? [preferred].concat(cameras.filter(function (cam) { return cam.deviceId !== preferred.deviceId }))
      : cameras

    var lastError = null
    for (var i = 0; i < candidates.length; i++) {
      try {
        heldMediaStream = await openCameraById(candidates[i].deviceId, false)
        selectedCameraId = candidates[i].deviceId
        await fillCameraSelect(selectedCameraId)
        attachNativeLocalPreview(heldMediaStream)
        return heldMediaStream
      } catch (err) {
        lastError = err
      }
    }
    throw lastError || new Error('カメラを開けませんでした。')
  }

  async function switchCamera(deviceId) {
    if (!deviceId || switchingCamera) return
    if (deviceId === selectedCameraId && currentVideoTrack()) return
    switchingCamera = true
    try {
      var oldVideo = currentVideoTrack()
      var nextStream = await openCameraById(deviceId, true)
      var nextVideo = nextStream.getVideoTracks()[0]
      if (!nextVideo) throw new Error('選択したカメラを開けませんでした。')

      var pubTrack = publishedVideoTrack && publishedVideoTrack.track
        ? publishedVideoTrack.track
        : publishedVideoTrack

      if (room && pubTrack && typeof pubTrack.replaceTrack === 'function') {
        await pubTrack.replaceTrack(nextVideo)
      } else if (room) {
        if (publishedVideoTrack) {
          try { await room.localParticipant.unpublishTrack(publishedVideoTrack.track || publishedVideoTrack) } catch (_) {}
        }
        publishedVideoTrack = await room.localParticipant.publishTrack(nextVideo, {
          source: livekit && livekit.Track && livekit.Track.Source ? livekit.Track.Source.Camera : 'camera',
        })
      }

      if (oldVideo) {
        try { oldVideo.stop() } catch (_) {}
        try { heldMediaStream.removeTrack(oldVideo) } catch (_) {}
      }
      if (!heldMediaStream) heldMediaStream = new MediaStream()
      heldMediaStream.addTrack(nextVideo)
      nextStream.getAudioTracks().forEach(function (track) {
        if (!heldMediaStream.getAudioTracks().length) heldMediaStream.addTrack(track)
      })

      selectedCameraId = deviceId
      await fillCameraSelect(selectedCameraId)
      attachNativeLocalPreview(heldMediaStream)
      if (!cameraEnabled) setLocalTrackEnabled('video', false)
    } catch (err) {
      window.alert((err && err.message) || 'カメラを切り替えられませんでした。')
      await fillCameraSelect(selectedCameraId)
    } finally {
      switchingCamera = false
    }
  }

  async function cancelRing() {
    if (!callButton) return
    var cancelUrl = callButton.getAttribute('data-cancel-url')
    if (!cancelUrl) return
    try { await postJson(cancelUrl) } catch (_) {}
  }

  async function leaveCall(options) {
    options = options || {}
    stopRingtone()
    if (room) {
      try { room.disconnect() } catch (_) {}
      room = null
    }
    stopHeldLocalTracks()
    clearTracks(localContainer)
    clearTracks(remoteContainer)
    setLocalPlaceholder('')
    if (options.cancelRing !== false && isCaller) {
      await cancelRing()
    }
    inCall = false
    isCaller = false
    setStatus('接続前')
    if (callButton) callButton.disabled = false
  }

  async function loadSdk() {
    if (livekit) return livekit
    livekit = await import('https://cdn.jsdelivr.net/npm/livekit-client@2.20.2/+esm')
    return livekit
  }

  async function joinCall(tokenUrl, asCaller) {
    if (inCall) return
    inCall = true
    isCaller = !!asCaller
    if (callButton) callButton.disabled = true
    setStatus(asCaller ? '呼び出し中…' : '接続準備中…')
    if (dialog) openEl(dialog)
    clearTracks(localContainer)
    clearTracks(remoteContainer)
    setLocalPlaceholder('')

    try {
      // 1) 先にPCカメラを開いて自分の映像を出す（仮想カメラは避ける）
      try {
        await openLocalMedia()
      } catch (_) {
        throw new Error('カメラまたはマイクを利用できません。ブラウザとPCのカメラ許可を確認してください。')
      }

      var sdk = await loadSdk()
      var result = await postJson(tokenUrl, asCaller ? { ring: true } : {})
      var payload = result.payload
      if (!result.ok || !payload.ok) throw new Error(payload.message || '通話の準備に失敗しました。')

      room = new sdk.Room({
        adaptiveStream: true,
        dynacast: true,
      })
      room.on(sdk.RoomEvent.TrackSubscribed, function (track) {
        attachTrack(track, remoteContainer, false)
      })
      room.on(sdk.RoomEvent.TrackUnsubscribed, function (track) {
        track.detach().forEach(function (element) { element.remove() })
      })
      room.on(sdk.RoomEvent.ParticipantConnected, function () {
        stopRingtone()
        setStatus('接続中')
      })
      room.on(sdk.RoomEvent.Disconnected, function () {
        clearTracks(remoteContainer)
        setStatus('通話を終了しました')
        inCall = false
        if (callButton) callButton.disabled = false
      })

      await room.connect(payload.serverUrl, payload.participantToken)

      // 2) 取得済みの MediaStreamTrack を LiveKit に公開
      publishedVideoTrack = null
      var tracks = heldMediaStream ? heldMediaStream.getTracks() : []
      for (var i = 0; i < tracks.length; i++) {
        var publication = await room.localParticipant.publishTrack(tracks[i], {
          source: tracks[i].kind === 'video'
            ? (sdk.Track && sdk.Track.Source ? sdk.Track.Source.Camera : 'camera')
            : (sdk.Track && sdk.Track.Source ? sdk.Track.Source.Microphone : 'microphone'),
        })
        if (tracks[i].kind === 'video') publishedVideoTrack = publication
      }
      if (typeof room.startAudio === 'function') {
        room.startAudio().catch(function () {})
      }

      micEnabled = true
      cameraEnabled = true
      if (micButton) micButton.textContent = 'マイクをオフ'
      if (cameraButton) cameraButton.textContent = 'カメラをオフ'

      var hasRemote = room.remoteParticipants && room.remoteParticipants.size > 0
      if (asCaller && !hasRemote) {
        setStatus('呼び出し中…')
      } else {
        stopRingtone()
        setStatus('接続中')
      }
    } catch (error) {
      await leaveCall({ cancelRing: true })
      closeEl(dialog)
      window.alert(error && error.message ? error.message : '通話に接続できませんでした。')
    }
  }

  function hideIncoming() {
    stopRingtone()
    activeIncoming = null
    closeEl(incomingDialog)
  }

  function showIncoming(call) {
    if (!call || inCall) return
    if (activeIncoming && activeIncoming.roomName === call.roomName) return
    activeIncoming = call
    if (incomingFrom) {
      incomingFrom.textContent = (call.fromName || '相手') + ' から着信中'
    }
    if (remoteLabel && call.fromName) remoteLabel.textContent = call.fromName
    openEl(incomingDialog)
    startRingtone()
  }

  window.__MSG_ON_POLL__ = function (data) {
    if (data && data.callDeclined && inCall && isCaller) {
      leaveCall({ cancelRing: false }).then(function () {
        closeEl(dialog)
        window.alert('相手が通話を拒否しました。')
      })
      return
    }
    var call = data && data.incomingCall ? data.incomingCall : null
    if (!call) {
      if (activeIncoming && !inCall) hideIncoming()
      return
    }
    showIncoming(call)
  }

  // 会話を選んでいない一覧画面でも、ユーザー宛の着信を見逃さない。
  if (!document.getElementById('message-thread')) {
    function pollIncomingCall() {
      fetch('/messages/incoming-call', {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
        .then(function (res) { return res.ok ? res.json() : null })
        .then(function (data) {
          if (data && data.ok) window.__MSG_ON_POLL__(data)
        })
        .catch(function () {})
    }
    pollIncomingCall()
    setInterval(pollIncomingCall, 4000)
  }

  if (callButton) {
    callButton.addEventListener('click', function () {
      var tokenUrl = callButton.getAttribute('data-token-url')
      if (!tokenUrl) return
      joinCall(tokenUrl, true)
    })
  }

  if (micButton) {
    micButton.addEventListener('click', async function () {
      if (!room && !heldMediaStream) return
      micEnabled = !micEnabled
      setLocalTrackEnabled('audio', micEnabled)
      micButton.textContent = micEnabled ? 'マイクをオフ' : 'マイクをオン'
    })
  }

  if (cameraButton) {
    cameraButton.addEventListener('click', async function () {
      if (!room && !heldMediaStream) return
      cameraEnabled = !cameraEnabled
      setLocalTrackEnabled('video', cameraEnabled)
      cameraButton.textContent = cameraEnabled ? 'カメラをオフ' : 'カメラをオン'
    })
  }

  if (cameraSelect) {
    cameraSelect.addEventListener('change', function () {
      var deviceId = cameraSelect.value
      if (!deviceId) return
      switchCamera(deviceId)
    })
  }

  if (endButton) {
    endButton.addEventListener('click', async function () {
      await leaveCall({ cancelRing: true })
      closeEl(dialog)
    })
  }

  if (acceptButton) {
    acceptButton.addEventListener('click', async function () {
      if (!activeIncoming) return
      var call = activeIncoming
      hideIncoming()
      var tokenUrl = '/messages/' + call.groupId + '/dm/' + call.fromUserId + '/call-token'
      // 別スレを見ている場合はDMへ移動してから応答
      var thread = document.getElementById('message-thread')
      var currentPeer = thread ? (thread.getAttribute('data-peer-id') || '') : ''
      var currentGroup = thread ? (thread.getAttribute('data-group-id') || '') : ''
      if (String(currentGroup) !== String(call.groupId) || String(currentPeer) !== String(call.fromUserId)) {
        sessionStorage.setItem('sa2_pending_call_accept', JSON.stringify(call))
        window.location.href = call.href
        return
      }
      await joinCall(tokenUrl, false)
    })
  }

  if (declineButton) {
    declineButton.addEventListener('click', async function () {
      hideIncoming()
      try { await postJson('/messages/call-decline') } catch (_) {}
    })
  }

  if (dialog) {
    dialog.addEventListener('close', function () {
      if (inCall) leaveCall({ cancelRing: true })
    })
  }

  if (incomingDialog) {
    incomingDialog.addEventListener('close', function () {
      stopRingtone()
    })
  }

  window.addEventListener('pagehide', function () {
    if (inCall) leaveCall({ cancelRing: true })
  })

  // DMを開いた直後に、保留中の応答を処理
  try {
    var pendingRaw = sessionStorage.getItem('sa2_pending_call_accept')
    if (pendingRaw) {
      sessionStorage.removeItem('sa2_pending_call_accept')
      var pending = JSON.parse(pendingRaw)
      var thread = document.getElementById('message-thread')
      var peer = thread ? (thread.getAttribute('data-peer-id') || '') : ''
      var group = thread ? (thread.getAttribute('data-group-id') || '') : ''
      if (pending && String(group) === String(pending.groupId) && String(peer) === String(pending.fromUserId)) {
        joinCall('/messages/' + pending.groupId + '/dm/' + pending.fromUserId + '/call-token', false)
      }
    }
  } catch (_) {}
})()
