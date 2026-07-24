/**
 * Shared Web Speech + LLM parse helper for Todo / Notes / Finance.
 * window.Sa2VoiceEntry.init(options)
 */
(function (global) {
  const STRINGS = {
    speak: '話す',
    stop: '停止',
    empty: '音声テキストを入力するか、マイクで話してください。',
    notReady: 'AI（ChatGPT / Gemini）が未設定です。設定画面で有効化してください。',
    parsing: 'AIで解析中…',
    parsed: '解析結果を確認して登録してください。',
    parseFailed: '音声の解析に失敗しました。',
    unsupported: 'このブラウザは音声認識に対応していません。テキスト入力をご利用ください。',
    listening: '聞いています…',
    transcribed: '文字起こし完了。解析します…',
    micDenied: 'マイクの使用が許可されていません。',
    recognizeFailed: '音声認識に失敗しました。',
    startFailed: '音声認識を開始できませんでした。',
  }

  function init(options) {
    const cfg = options || {}
    const prefix = cfg.prefix || 'voice'
    const parseUrl = cfg.parseUrl
    const voiceReady = Boolean(cfg.ready)
    const csrfToken = cfg.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || ''
    const strings = Object.assign({}, STRINGS, cfg.strings || {})
    const onParsed = typeof cfg.onParsed === 'function' ? cfg.onParsed : function () {}

    const micBtn = document.getElementById(`${prefix}-voice-mic-btn`)
    const transcriptInput = document.getElementById(`${prefix}-voice-transcript`)
    const parseBtn = document.getElementById(`${prefix}-voice-parse-btn`)
    const statusEl = document.getElementById(`${prefix}-voice-status`)
    if (!transcriptInput || !parseBtn) return null

    const SpeechRecognition = global.SpeechRecognition || global.webkitSpeechRecognition
    let recognition = null
    let listening = false

    function setStatus(message, isError) {
      if (!statusEl) return
      statusEl.textContent = message || ''
      statusEl.classList.toggle('is-error', Boolean(isError && message))
    }

    async function parseTranscript() {
      const transcript = String(transcriptInput.value || '').trim()
      if (!transcript) {
        setStatus(strings.empty, true)
        return
      }
      if (!voiceReady) {
        setStatus(strings.notReady, true)
        return
      }
      parseBtn.disabled = true
      if (micBtn) micBtn.disabled = true
      setStatus(strings.parsing)
      try {
        const res = await fetch(parseUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ transcript }),
        })
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.ok) {
          throw new Error(data.message || strings.parseFailed)
        }
        setStatus(strings.parsed)
        onParsed(data.parsed || {}, transcript, data)
      } catch (err) {
        setStatus((err && err.message) || strings.parseFailed, true)
      } finally {
        parseBtn.disabled = !voiceReady
        if (micBtn) micBtn.disabled = !voiceReady
      }
    }

    function stopListening() {
      listening = false
      micBtn?.classList.remove('is-listening')
      micBtn?.setAttribute('aria-pressed', 'false')
      if (micBtn) micBtn.textContent = strings.speak
      try { recognition?.stop() } catch (_) {}
    }

    function startListening() {
      if (!SpeechRecognition) {
        setStatus(strings.unsupported, true)
        return
      }
      if (!voiceReady) {
        setStatus(strings.notReady, true)
        return
      }
      if (!recognition) {
        recognition = new SpeechRecognition()
        recognition.lang = 'ja-JP'
        recognition.interimResults = true
        recognition.continuous = false
        recognition.onresult = (event) => {
          let text = ''
          for (let i = 0; i < event.results.length; i += 1) {
            text += event.results[i][0].transcript
          }
          transcriptInput.value = text.trim()
          if (event.results[event.results.length - 1]?.isFinal) {
            setStatus(strings.transcribed)
            stopListening()
            parseTranscript()
          }
        }
        recognition.onerror = (event) => {
          stopListening()
          const err = event?.error || ''
          if (err === 'not-allowed') setStatus(strings.micDenied, true)
          else if (err !== 'aborted') setStatus(strings.recognizeFailed + (err ? ` (${err})` : ''), true)
        }
        recognition.onend = () => {
          if (listening) stopListening()
        }
      }
      listening = true
      micBtn?.classList.add('is-listening')
      micBtn?.setAttribute('aria-pressed', 'true')
      if (micBtn) micBtn.textContent = strings.stop
      setStatus(strings.listening)
      try {
        recognition.start()
      } catch (_) {
        stopListening()
        setStatus(strings.startFailed, true)
      }
    }

    parseBtn.addEventListener('click', parseTranscript)
    transcriptInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault()
        parseTranscript()
      }
    })
    micBtn?.addEventListener('click', () => {
      if (listening) stopListening()
      else startListening()
    })

    return { parseTranscript, setStatus, stopListening }
  }

  global.Sa2VoiceEntry = { init }
})(window)
