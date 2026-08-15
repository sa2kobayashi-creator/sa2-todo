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
  var ringOsc = null

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
    if (ringOsc) {
      try { ringOsc.stop() } catch (_) {}
      ringOsc = null
    }
  }

  function beepOnce() {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)()
      if (audioCtx.state === 'suspended') audioCtx.resume()
      var osc = audioCtx.createOscillator()
      var gain = audioCtx.createGain()
      osc.type = 'sine'
      osc.frequency.value = 880
      gain.gain.value = 0.05
      osc.connect(gain)
      gain.connect(audioCtx.destination)
      osc.start()
      setTimeout(function () {
        try { osc.stop() } catch (_) {}
      }, 220)
    } catch (_) {}
  }

  function startRingtone() {
    stopRingtone()
    beepOnce()
    ringTimer = setInterval(beepOnce, 1800)
  }

  function attachTrack(track, container) {
    if (!track || !container) return
    var element = track.attach()
    if (element.tagName === 'VIDEO') {
      element.autoplay = true
      element.playsInline = true
    }
    container.appendChild(element)
  }

  function attachLocalVideoTracks() {
    if (!room || !localContainer) return
    clearTracks(localContainer)
    room.localParticipant.videoTrackPublications.forEach(function (publication) {
      if (publication.track) attachTrack(publication.track, localContainer)
    })
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
    clearTracks(localContainer)
    clearTracks(remoteContainer)
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

    try {
      var result = await postJson(tokenUrl, asCaller ? { ring: true } : {})
      var payload = result.payload
      if (!result.ok || !payload.ok) throw new Error(payload.message || '通話の準備に失敗しました。')

      var sdk = await loadSdk()
      room = new sdk.Room({
        adaptiveStream: true,
        dynacast: true,
      })
      room.on(sdk.RoomEvent.TrackSubscribed, function (track) {
        attachTrack(track, remoteContainer)
      })
      room.on(sdk.RoomEvent.TrackUnsubscribed, function (track) {
        track.detach().forEach(function (element) { element.remove() })
      })
      room.on(sdk.RoomEvent.ParticipantConnected, function () {
        stopRingtone()
        setStatus('接続中')
      })
      room.on(sdk.RoomEvent.Disconnected, function () {
        clearTracks(localContainer)
        clearTracks(remoteContainer)
        setStatus('通話を終了しました')
        inCall = false
        if (callButton) callButton.disabled = false
      })

      await room.connect(payload.serverUrl, payload.participantToken)
      await room.localParticipant.enableCameraAndMicrophone()
      attachLocalVideoTracks()
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

  if (callButton) {
    callButton.addEventListener('click', function () {
      var tokenUrl = callButton.getAttribute('data-token-url')
      if (!tokenUrl) return
      joinCall(tokenUrl, true)
    })
  }

  if (micButton) {
    micButton.addEventListener('click', async function () {
      if (!room) return
      micEnabled = !micEnabled
      await room.localParticipant.setMicrophoneEnabled(micEnabled)
      micButton.textContent = micEnabled ? 'マイクをオフ' : 'マイクをオン'
    })
  }

  if (cameraButton) {
    cameraButton.addEventListener('click', async function () {
      if (!room) return
      cameraEnabled = !cameraEnabled
      await room.localParticipant.setCameraEnabled(cameraEnabled)
      attachLocalVideoTracks()
      cameraButton.textContent = cameraEnabled ? 'カメラをオフ' : 'カメラをオン'
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
