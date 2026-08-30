(function () {
  const bootEl = document.getElementById('translate-page-boot')
  const i18nEl = document.getElementById('translate-i18n')
  const langEl = document.getElementById('translate-lang-labels')
  let i18n = {}
  let langLabels = {}
  try { i18n = JSON.parse(i18nEl ? i18nEl.textContent : '{}') } catch (e) { i18n = {} }
  try { langLabels = JSON.parse(langEl ? langEl.textContent : '{}') } catch (e) { langLabels = {} }
  const configured = bootEl && bootEl.dataset.configured === '1'

  function boot() {
    const input = document.getElementById('translate-input')
    const output = document.getElementById('translate-output')
    const source = document.getElementById('translate-source')
    const target = document.getElementById('translate-target')
    const sourceLabel = document.getElementById('gt-source-label')
    const targetLabel = document.getElementById('gt-target-label')
    const swapBtn = document.getElementById('translate-swap')
    const submitBtn = document.getElementById('translate-submit')
    const micBtn = document.getElementById('translate-mic')
    const status = document.getElementById('translate-live-status')
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
    let mode = 'text'

    if (!input || !output || !source || !target || !swapBtn) return

    function labelOf(code) {
      return langLabels[code] || code
    }

    function syncChips(side) {
      const select = side === 'source' ? source : target
      const root = document.getElementById(side === 'source' ? 'gt-source-chips' : 'gt-target-chips')
      if (!root) return
      root.querySelectorAll('.gt-chip').forEach((chip) => {
        chip.classList.toggle('is-active', chip.dataset.lang === select.value)
      })
      if (side === 'source' && sourceLabel) sourceLabel.textContent = labelOf(select.value)
      if (side === 'target' && targetLabel) targetLabel.textContent = labelOf(select.value)
    }

    function setLang(side, code) {
      const select = side === 'source' ? source : target
      if (side === 'target' && code === 'AUTO') return
      if (![...select.options].some((o) => o.value === code)) {
        const opt = document.createElement('option')
        opt.value = code
        opt.textContent = labelOf(code)
        select.appendChild(opt)
      }
      select.value = code
      syncChips(side)
    }

    function refreshAllLabels() {
      syncChips('source')
      syncChips('target')
    }

    function showStatus(text) {
      if (!status) return
      status.hidden = !text
      status.textContent = text || ''
    }

    async function translateText(text, options) {
      const live = !!(options && options.live)
      const currentMode = (options && options.mode) || mode
      const title = options && options.title
      const sourceUrl = options && options.source_url
      if (!configured || !String(text || '').trim()) return null
      showStatus(live ? (i18n.translating || '') : (i18n.translating || ''))
      try {
        const res = await fetch('/translate', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            text,
            source: source.value,
            target: target.value,
            mode: currentMode,
            title: title || null,
            source_url: sourceUrl || null,
            save_history: true,
          }),
        })
        const data = await res.json()
        if (!data || !data.ok) {
          showStatus((data && data.message) || i18n.failed || '')
          return null
        }
        if (data.detected_source && source.value === 'AUTO') {
          // keep AUTO selected; show detected in status
          showStatus((i18n.detect || '') + ': ' + labelOf(data.detected_source))
        } else {
          showStatus('')
        }
        return data
      } catch (err) {
        showStatus(i18n.failed || '')
        return null
      }
    }

    function swapLikeGoogle(e) {
      if (e) {
        e.preventDefault()
        e.stopPropagation()
      }
      const prevSource = source.value
      const prevTarget = target.value
      const prevInput = input.value
      const prevOutput = output.value

      // AUTO は入れ替え先の原文側へ
      setLang('source', prevTarget === 'AUTO' ? 'EN' : prevTarget)
      setLang('target', prevSource === 'AUTO' ? 'EN' : prevSource)

      if (String(prevOutput || '').trim()) {
        input.value = prevOutput
        output.value = prevInput
      } else {
        output.value = ''
      }

      swapBtn.classList.remove('is-spin')
      void swapBtn.offsetWidth
      swapBtn.classList.add('is-spin')
      showStatus((i18n.swapped || '') + ': ' + labelOf(source.value) + ' → ' + labelOf(target.value))

      if (String(input.value || '').trim()) {
        translateText(input.value.trim(), { live: true, mode: 'text' }).then((data) => {
          if (data) output.value = data.translated || ''
        })
      }
    }

    window.sa2TranslateSwap = swapLikeGoogle
    refreshAllLabels()

    document.querySelectorAll('#gt-source-chips .gt-chip').forEach((chip) => {
      chip.addEventListener('click', () => setLang('source', chip.dataset.lang))
    })
    document.querySelectorAll('#gt-target-chips .gt-chip').forEach((chip) => {
      chip.addEventListener('click', () => setLang('target', chip.dataset.lang))
    })
    source.addEventListener('change', () => syncChips('source'))
    target.addEventListener('change', () => syncChips('target'))
    swapBtn.addEventListener('click', swapLikeGoogle)

    if (submitBtn) {
      submitBtn.addEventListener('click', async () => {
        const data = await translateText(input.value, { mode: 'text' })
        if (data) output.value = data.translated || ''
      })
    }

    const clearBtn = document.getElementById('translate-clear')
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        input.value = ''
        output.value = ''
        showStatus('')
        input.focus()
      })
    }

    const copyBtn = document.getElementById('translate-copy')
    if (copyBtn) {
      copyBtn.addEventListener('click', async () => {
        const text = String(output.value || '').trim()
        if (!text) return
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text)
          } else {
            output.focus()
            output.select()
            document.execCommand('copy')
          }
          copyBtn.classList.add('is-ok')
          showStatus(i18n.copied || '')
          setTimeout(() => {
            copyBtn.classList.remove('is-ok')
            if (status && status.textContent === (i18n.copied || '')) showStatus('')
          }, 1500)
        } catch (_) {
          showStatus(i18n.copyFailed || '')
        }
      })
    }

    // Modes
    document.querySelectorAll('.gt-mode').forEach((btn) => {
      btn.addEventListener('click', () => {
        mode = btn.dataset.mode
        document.querySelectorAll('.gt-mode').forEach((b) => {
          const on = b === btn
          b.classList.toggle('is-active', on)
          b.setAttribute('aria-selected', on ? 'true' : 'false')
        })
        document.querySelectorAll('.gt-board').forEach((panel) => {
          panel.hidden = panel.dataset.panel !== mode
        })
      })
    })

    // Mic — モバイルで continuous + 累積追記すると同じ文が二重になるため、
    // セッション開始時の原文を固定し、results 全体から都度組み立てる。
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
    if (SpeechRecognition && micBtn && configured) {
      micBtn.hidden = false
      let recognition = null
      let listening = false
      let debounceTimer = 0
      let sessionBase = ''

      function collapseRepeatedPhrase(text) {
        const raw = String(text || '').replace(/\s+/g, ' ').trim()
        if (!raw) return ''
        const spaced = raw.match(/^(.{2,}?)\s+\1(?:\s+\1)*$/u)
        if (spaced) return spaced[1].trim()
        const compact = raw.replace(/\s+/g, '')
        if (compact.length >= 4 && compact.length % 2 === 0) {
          const half = compact.slice(0, compact.length / 2)
          if (half === compact.slice(compact.length / 2) && half.length >= 2) {
            return half
          }
        }
        return raw
      }

      function speechLang() {
        return source.value === 'JA' ? 'ja-JP'
          : (source.value === 'KO' ? 'ko-KR'
            : (source.value === 'TL' ? 'fil-PH'
              : (source.value === 'ZH' ? 'zh-CN' : 'en-US')))
      }

      micBtn.addEventListener('click', () => {
        if (listening && recognition) {
          try { recognition.stop() } catch (_) {}
          return
        }
        recognition = new SpeechRecognition()
        recognition.lang = speechLang()
        // continuous はスマホブラウザで同一発話が複数 final になるためオフ
        recognition.continuous = false
        recognition.interimResults = true
        sessionBase = String(input.value || '').trim()

        recognition.onstart = () => {
          listening = true
          micBtn.textContent = i18n.stop || 'Stop'
          micBtn.classList.add('is-live')
          showStatus(i18n.listening || '')
        }
        recognition.onend = () => {
          listening = false
          micBtn.textContent = i18n.mic || 'Mic'
          micBtn.classList.remove('is-live')
        }
        recognition.onerror = (ev) => {
          listening = false
          micBtn.textContent = i18n.mic || 'Mic'
          micBtn.classList.remove('is-live')
          const err = ev && ev.error
          if (err && err !== 'aborted' && err !== 'no-speech') {
            showStatus(i18n.failed || '')
          }
        }
        recognition.onresult = (event) => {
          let finals = ''
          let interim = ''
          for (let i = 0; i < event.results.length; i += 1) {
            const chunk = (event.results[i][0] && event.results[i][0].transcript) || ''
            if (event.results[i].isFinal) finals += chunk
            else interim += chunk
          }
          const spoken = collapseRepeatedPhrase((finals + interim).replace(/\s+/g, ' ').trim())
          input.value = [sessionBase, spoken].filter(Boolean).join(' ')

          const last = event.results[event.results.length - 1]
          if (!last || !last.isFinal) return
          clearTimeout(debounceTimer)
          debounceTimer = setTimeout(async () => {
            const spokenFinal = collapseRepeatedPhrase(finals.replace(/\s+/g, ' ').trim())
            const text = [sessionBase, spokenFinal].filter(Boolean).join(' ')
            input.value = text
            if (!text) return
            const data = await translateText(text, { live: true, mode: 'text' })
            if (data) output.value = data.translated || ''
          }, 400)
        }
        try {
          recognition.start()
        } catch (_) {
          listening = false
          micBtn.textContent = i18n.mic || 'Mic'
          micBtn.classList.remove('is-live')
          showStatus(i18n.failed || '')
        }
      })
    }

    // Image OCR
    const imageInput = document.getElementById('gt-image-input')
    const imageDrop = document.getElementById('gt-image-drop')
    const imagePreview = document.getElementById('gt-image-preview')
    const imageResult = document.getElementById('gt-image-result')
    const imageSource = document.getElementById('gt-image-source')
    const imageOutput = document.getElementById('gt-image-output')

    async function handleImageFile(file) {
      if (!file || !configured) return
      if (imagePreview) {
        imagePreview.src = URL.createObjectURL(file)
        imagePreview.hidden = false
      }
      if (!window.Tesseract) {
        showStatus(i18n.failed || 'OCR unavailable')
        return
      }
      showStatus(i18n.ocr || '')
      const langMap = { JA: 'jpn', EN: 'eng', KO: 'kor', ZH: 'chi_sim', TL: 'eng', AUTO: 'eng+jpn' }
      try {
        const result = await window.Tesseract.recognize(file, langMap[source.value] || 'eng')
        const text = (result && result.data && result.data.text) ? result.data.text.trim() : ''
        if (imageSource) imageSource.value = text
        if (imageResult) imageResult.hidden = false
        if (!text) {
          showStatus(i18n.failed || '')
          return
        }
        const data = await translateText(text, { mode: 'image', title: file.name })
        if (data && imageOutput) imageOutput.value = data.translated || ''
      } catch (err) {
        showStatus(i18n.failed || '')
      }
    }

    if (imageInput) imageInput.addEventListener('change', () => handleImageFile(imageInput.files && imageInput.files[0]))
    bindDrop(imageDrop, handleImageFile)

    // Document
    const docInput = document.getElementById('gt-doc-input')
    const docDrop = document.getElementById('gt-doc-drop')
    const docResult = document.getElementById('gt-doc-result')
    const docSource = document.getElementById('gt-doc-source')
    const docOutput = document.getElementById('gt-doc-output')

    async function handleDocFile(file) {
      if (!file || !configured) return
      showStatus(i18n.translating || '')
      const fd = new FormData()
      fd.append('file', file)
      fd.append('source', source.value)
      fd.append('target', target.value)
      try {
        const res = await fetch('/translate/document', {
          method: 'POST',
          headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
          credentials: 'same-origin',
          body: fd,
        })
        const data = await res.json()
        if (!data || !data.ok) {
          showStatus((data && data.message) || i18n.failed || '')
          return
        }
        // re-fetch extracted text isn't returned; use translated only + request text via separate field
        // Controller returns translated; also include source in response - update controller to return source_text
        if (docSource) docSource.value = data.source_text || ''
        if (docOutput) docOutput.value = data.translated || ''
        if (docResult) docResult.hidden = false
        showStatus('')
      } catch (err) {
        showStatus(i18n.failed || '')
      }
    }

    if (docInput) docInput.addEventListener('change', () => handleDocFile(docInput.files && docInput.files[0]))
    bindDrop(docDrop, handleDocFile)

    // Website
    const webUrl = document.getElementById('gt-web-url')
    const webGo = document.getElementById('gt-web-go')
    const webResult = document.getElementById('gt-web-result')
    const webSource = document.getElementById('gt-web-source')
    const webOutput = document.getElementById('gt-web-output')
    const webTitle = document.getElementById('gt-web-title')

    if (webGo) {
      webGo.addEventListener('click', async () => {
        if (!configured || !webUrl || !webUrl.value.trim()) return
        showStatus(i18n.translating || '')
        try {
          const res = await fetch('/translate/website', {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
            },
            credentials: 'same-origin',
            body: JSON.stringify({
              url: webUrl.value.trim(),
              source: source.value,
              target: target.value,
            }),
          })
          const data = await res.json()
          if (!data || !data.ok) {
            showStatus((data && data.message) || i18n.failed || '')
            return
          }
          if (webSource) webSource.value = data.source_text || ''
          if (webOutput) webOutput.value = data.translated || ''
          if (webTitle) webTitle.textContent = data.title || labelOf(source.value)
          if (webResult) webResult.hidden = false
          showStatus('')
        } catch (err) {
          showStatus(i18n.failed || '')
        }
      })
    }

    function bindDrop(el, handler) {
      if (!el) return
      el.addEventListener('dragover', (e) => {
        e.preventDefault()
        el.classList.add('is-drag')
      })
      el.addEventListener('dragleave', () => el.classList.remove('is-drag'))
      el.addEventListener('drop', (e) => {
        e.preventDefault()
        el.classList.remove('is-drag')
        const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]
        if (file) handler(file)
      })
    }

    // History drawer
    const drawer = document.getElementById('gt-drawer')
    const backdrop = document.getElementById('gt-drawer-backdrop')
    const drawerBody = document.getElementById('gt-drawer-body')
    const drawerTitle = document.getElementById('gt-drawer-title')
    const closeBtn = document.getElementById('gt-drawer-close')

    async function openHistory(savedOnly) {
      if (!drawer || !drawerBody) return
      drawerTitle.textContent = savedOnly ? (i18n.saved || 'Saved') : (i18n.history || 'History')
      drawer.hidden = false
      if (backdrop) backdrop.hidden = false
      drawerBody.innerHTML = '<p class="gt-hint">…</p>'
      try {
        const res = await fetch('/translate/history' + (savedOnly ? '?saved=1' : ''), {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        })
        const data = await res.json()
        const items = (data && data.items) || []
        if (!items.length) {
          drawerBody.innerHTML = '<p class="gt-hint">' + (i18n.emptyHistory || '') + '</p>'
          return
        }
        drawerBody.innerHTML = items.map((item) => {
          const preview = (item.source_text || '').slice(0, 120)
          return (
            '<article class="gt-hist" data-id="' + item.id + '">' +
              '<div class="gt-hist-meta">' +
                '<span>' + escapeHtml(item.source_lang) + ' → ' + escapeHtml(item.target_lang) + '</span>' +
                '<span>' + escapeHtml(item.mode) + '</span>' +
              '</div>' +
              '<p class="gt-hist-title">' + escapeHtml(item.title || preview) + '</p>' +
              '<div class="gt-hist-actions">' +
                '<button type="button" data-act="open">開く</button>' +
                '<button type="button" data-act="save">' + (item.is_saved ? '★' : '☆') + '</button>' +
                '<button type="button" data-act="del">×</button>' +
              '</div>' +
            '</article>'
          )
        }).join('')

        drawerBody.querySelectorAll('.gt-hist').forEach((card, idx) => {
          const item = items[idx]
          card.querySelector('[data-act="open"]').addEventListener('click', () => {
            mode = 'text'
            document.querySelectorAll('.gt-mode').forEach((b) => {
              const on = b.dataset.mode === 'text'
              b.classList.toggle('is-active', on)
            })
            document.querySelectorAll('.gt-board').forEach((panel) => {
              panel.hidden = panel.dataset.panel !== 'text'
            })
            setLang('source', item.source_lang === 'AUTO' ? 'AUTO' : item.source_lang)
            setLang('target', item.target_lang)
            input.value = item.source_text || ''
            output.value = item.translated_text || ''
            closeDrawer()
          })
          card.querySelector('[data-act="save"]').addEventListener('click', async () => {
            await fetch('/translate/history/' + item.id + '/saved', {
              method: 'POST',
              headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
              credentials: 'same-origin',
            })
            openHistory(savedOnly)
          })
          card.querySelector('[data-act="del"]').addEventListener('click', async () => {
            await fetch('/translate/history/' + item.id, {
              method: 'DELETE',
              headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
              credentials: 'same-origin',
            })
            openHistory(savedOnly)
          })
        })
      } catch (err) {
        drawerBody.innerHTML = '<p class="gt-hint">' + (i18n.failed || '') + '</p>'
      }
    }

    function closeDrawer() {
      if (drawer) drawer.hidden = true
      if (backdrop) backdrop.hidden = true
    }

    function escapeHtml(s) {
      return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
    }

    document.getElementById('gt-history-open')?.addEventListener('click', () => openHistory(false))
    document.getElementById('gt-saved-open')?.addEventListener('click', () => openHistory(true))
    closeBtn?.addEventListener('click', closeDrawer)
    backdrop?.addEventListener('click', closeDrawer)
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot)
  } else {
    boot()
  }
})()
