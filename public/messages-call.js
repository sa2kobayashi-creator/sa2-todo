/**
 * LiveKit のDM通話試作。LiveKit Cloud と自前 LiveKit のどちらにも接続できる。
 * API シークレットはサーバー側の call-token エンドポイントだけで使用する。
 */
(function () {
  var callButton = document.getElementById('message-call-btn')
  var dialog = document.getElementById('message-call-dialog')
  if (!callButton || !dialog) return

  var status = document.getElementById('message-call-status')
  var localContainer = document.getElementById('message-call-local-video')
  var remoteContainer = document.getElementById('message-call-remote-videos')
  var micButton = document.getElementById('message-call-mic')
  var cameraButton = document.getElementById('message-call-camera')
  var endButton = document.getElementById('message-call-end')
  var csrfMeta = document.querySelector('meta[name="csrf-token"]')
  var csrf = csrfMeta ? csrfMeta.getAttribute('content') || '' : ''
  var room = null
  var livekit = null
  var micEnabled = true
  var cameraEnabled = true

  function setStatus(text) {
    if (status) status.textContent = text
  }

  function clearTracks(container) {
    if (container) container.replaceChildren()
  }

  function openDialog() {
    try {
      if (typeof dialog.showModal === 'function' && !dialog.open) {
        dialog.showModal()
        return
      }
    } catch (_) {}
    dialog.setAttribute('open', 'open')
    dialog.classList.add('is-open')
  }

  function closeDialog() {
    try {
      if (typeof dialog.close === 'function' && dialog.open) dialog.close()
    } catch (_) {}
    dialog.removeAttribute('open')
    dialog.classList.remove('is-open')
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

  async function leaveCall() {
    if (room) {
      room.disconnect()
      room = null
    }
    clearTracks(localContainer)
    clearTracks(remoteContainer)
    setStatus('接続前')
    callButton.disabled = false
  }

  async function loadSdk() {
    if (livekit) return livekit
    livekit = await import('https://cdn.jsdelivr.net/npm/livekit-client@2.20.2/+esm')
    return livekit
  }

  async function joinCall() {
    callButton.disabled = true
    setStatus('接続準備中…')

    try {
      var response = await fetch(callButton.getAttribute('data-token-url'), {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
      })
      var payload = await response.json()
      if (!response.ok || !payload.ok) throw new Error(payload.message || '通話の準備に失敗しました。')

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
      room.on(sdk.RoomEvent.Disconnected, function () {
        clearTracks(localContainer)
        clearTracks(remoteContainer)
        setStatus('通話を終了しました')
        callButton.disabled = false
      })

      await room.connect(payload.serverUrl, payload.participantToken)
      await room.localParticipant.enableCameraAndMicrophone()
      attachLocalVideoTracks()
      micEnabled = true
      cameraEnabled = true
      micButton.textContent = 'マイクをオフ'
      cameraButton.textContent = 'カメラをオフ'
      setStatus('接続中')
      openDialog()
    } catch (error) {
      await leaveCall()
      window.alert(error && error.message ? error.message : '通話に接続できませんでした。')
    }
  }

  callButton.addEventListener('click', joinCall)

  micButton.addEventListener('click', async function () {
    if (!room) return
    micEnabled = !micEnabled
    await room.localParticipant.setMicrophoneEnabled(micEnabled)
    micButton.textContent = micEnabled ? 'マイクをオフ' : 'マイクをオン'
  })

  cameraButton.addEventListener('click', async function () {
    if (!room) return
    cameraEnabled = !cameraEnabled
    await room.localParticipant.setCameraEnabled(cameraEnabled)
    attachLocalVideoTracks()
    cameraButton.textContent = cameraEnabled ? 'カメラをオフ' : 'カメラをオン'
  })

  endButton.addEventListener('click', async function () {
    await leaveCall()
    closeDialog()
  })

  dialog.addEventListener('close', leaveCall)
  window.addEventListener('pagehide', leaveCall)
})()
