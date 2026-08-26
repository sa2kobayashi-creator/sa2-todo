/**
 * Browser speech dictation for short fields (transit places, AI prompt).
 * window.Sa2SpeechDictation.bind(button, options)
 */
(function (global) {
  const DEFAULTS = {
    unsupported: 'このブラウザは音声認識に対応していません。',
    https: '音声入力は HTTPS（または localhost）でのみ利用できます。',
    startFailed: '音声認識を開始できませんでした。',
    micDenied: 'マイクの使用が許可されていません。',
  }

  function recognitionLang() {
    const lang = (document.documentElement.lang || 'ja').toLowerCase()
    return lang.indexOf('en') === 0 ? 'en-US' : 'ja-JP'
  }

  function isSecureContext() {
    const host = location.hostname
    return Boolean(window.isSecureContext) || host === 'localhost' || host === '127.0.0.1'
  }

  function bind(button, options) {
    options = options || {}
    if (!button) return null
    const messages = Object.assign({}, DEFAULTS, options.messages || {})
    const SpeechRecognition = global.SpeechRecognition || global.webkitSpeechRecognition
    let recognition = null
    let listening = false

    function notifyError(code) {
      const text = code === 'https' ? messages.https
        : (code === 'not-allowed' || code === 'service-not-allowed' ? messages.micDenied
          : (code === 'start' ? messages.startFailed : messages.unsupported))
      if (typeof options.onError === 'function') options.onError(text, code)
      else if (text) alert(text)
    }

    function setListening(on) {
      listening = on
      button.classList.toggle('is-listening', on)
      button.setAttribute('aria-pressed', on ? 'true' : 'false')
      if (typeof options.onListening === 'function') options.onListening(on)
    }

    button.addEventListener('click', function (event) {
      event.preventDefault()
      event.stopPropagation()
      if (button.disabled || button.getAttribute('aria-disabled') === 'true') return
      if (!isSecureContext()) {
        notifyError('https')
        return
      }
      if (!SpeechRecognition) {
        notifyError('unsupported')
        return
      }
      if (listening && recognition) {
        try { recognition.stop() } catch (_) {}
        return
      }

      const startValue = options.replace
        ? ''
        : String(typeof options.getValue === 'function' ? options.getValue() : '').trim()
      recognition = new SpeechRecognition()
      recognition.lang = options.lang || recognitionLang()
      recognition.interimResults = options.interimResults !== false
      recognition.continuous = !!options.continuous

      recognition.onstart = function () {
        setListening(true)
      }
      recognition.onend = function () {
        setListening(false)
      }
      recognition.onerror = function (ev) {
        setListening(false)
        const err = ev && ev.error
        if (err && err !== 'aborted' && err !== 'no-speech') notifyError(err)
      }
      recognition.onresult = function (event) {
        let finals = startValue ? startValue + ' ' : ''
        let interim = ''
        for (let i = 0; i < event.results.length; i++) {
          const chunk = event.results[i][0].transcript || ''
          if (event.results[i].isFinal) finals += chunk
          else interim += chunk
        }
        const text = (finals + interim).replace(/\s+/g, ' ').trim()
        if (typeof options.setValue === 'function') options.setValue(text)
      }

      try {
        recognition.start()
      } catch (_) {
        setListening(false)
        notifyError('start')
      }
    })

    return {
      stop: function () {
        if (recognition) {
          try { recognition.stop() } catch (_) {}
        }
      },
    }
  }

  global.Sa2SpeechDictation = {
    bind: bind,
    lang: recognitionLang,
  }
})(window)
