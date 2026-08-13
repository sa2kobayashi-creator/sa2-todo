/**
 * Messages workspace interactions (Android WebView / TWA 互換: ?. ?? 不使用)
 */
(function () {
  var cfg = window.__MSG_CFG__ || {}
  var thread = document.getElementById('message-thread')
  var form = document.getElementById('message-compose')
  if (!thread || !form) return

  var body = document.getElementById('message-body')
  var filesInput = document.getElementById('message-attachments')
  var cameraInput = document.getElementById('message-camera')
  var names = document.getElementById('message-attach-names')
  var replyBar = document.getElementById('message-reply-bar')
  var replyToInput = document.getElementById('message-reply-to')
  var replyName = document.getElementById('message-reply-name')
  var replyPreview = document.getElementById('message-reply-preview')
  var csrfMeta = document.querySelector('meta[name="csrf-token"]')
  var csrf = csrfMeta ? csrfMeta.getAttribute('content') || '' : ''
  var meId = Number(cfg.meId || 0)
  var reactionEmojis = cfg.reactionEmojis || []
  var i18n = cfg.i18n || {}
  var groupId = thread.getAttribute('data-group-id')
  var peerId = thread.getAttribute('data-peer-id') || ''
  var canTranslate = thread.getAttribute('data-can-translate') === '1'
  var emojiDialog = document.getElementById('message-emoji-dialog')
  var reactDialog = document.getElementById('message-react-dialog')
  var actionSheet = document.getElementById('message-action-sheet')
  var sheetActions = document.getElementById('message-sheet-actions')
  var toastEl = document.getElementById('message-toast')
  var promptDialog = document.getElementById('message-prompt-dialog')
  var promptInput = document.getElementById('message-prompt-input')
  var promptTitle = document.getElementById('message-prompt-title')
  var promptOk = document.getElementById('message-prompt-ok')
  var promptCancel = document.getElementById('message-prompt-cancel')
  var confirmDialog = document.getElementById('message-confirm-dialog')
  var confirmText = document.getElementById('message-confirm-text')
  var confirmOk = document.getElementById('message-confirm-ok')
  var confirmCancel = document.getElementById('message-confirm-cancel')
  var stickerPop = document.getElementById('message-sticker-pop')
  var stickerPopEmoji = document.getElementById('message-sticker-pop-emoji')

  var lastId = Number(thread.getAttribute('data-last-id') || 0)
  var sinceIso = new Date().toISOString()
  var sending = false
  var recognition = null
  var listening = false
  var sheetMessageId = 0
  var longPressTimer = null
  var promptResolver = null
  var confirmResolver = null
  var stickerPressTarget = null
  var stickerPressMoved = false

  function isMobileUi() {
    return window.matchMedia('(max-width: 860px)').matches
  }

  function openDialog(el) {
    if (!el) return false
    try {
      if (typeof el.showModal === 'function') {
        if (!el.open) el.showModal()
        return true
      }
    } catch (e) {}
    el.setAttribute('open', 'open')
    el.classList.add('is-open')
    return true
  }

  function closeDialog(el) {
    if (!el) return
    try {
      if (typeof el.close === 'function') el.close()
    } catch (e) {}
    el.removeAttribute('open')
    el.classList.remove('is-open')
  }

  function toast(msg) {
    if (!toastEl) {
      window.alert(msg)
      return
    }
    toastEl.textContent = String(msg || '')
    toastEl.hidden = false
    clearTimeout(toastEl._timer)
    toastEl._timer = setTimeout(function () {
      toastEl.hidden = true
    }, 2200)
  }

  function askPrompt(title, initial) {
    return new Promise(function (resolve) {
      if (!promptDialog || !promptInput) {
        resolve(window.prompt(title, initial || ''))
        return
      }
      promptResolver = resolve
      if (promptTitle) promptTitle.textContent = title || ''
      promptInput.value = initial || ''
      openDialog(promptDialog)
      setTimeout(function () {
        promptInput.focus()
        promptInput.select()
      }, 50)
    })
  }

  function askConfirm(message) {
    return new Promise(function (resolve) {
      if (!confirmDialog || !confirmText) {
        resolve(window.confirm(message))
        return
      }
      confirmResolver = resolve
      confirmText.textContent = message || ''
      openDialog(confirmDialog)
    })
  }

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
  }

  function actionButtons(message, mine) {
    var sticker = !!message.isSticker
    var actions
    if (mine) {
      actions = []
      if (!sticker) actions.push(['edit', i18n.edit])
      actions = actions.concat([['delete', i18n.delete], ['copy', i18n.copy], ['forward', i18n.forward]])
    } else {
      actions = [['reply', i18n.reply], ['copy', i18n.copy]]
      if (canTranslate && !sticker) actions.push(['translate', i18n.translate])
      actions.push(['delete', i18n.delete])
    }
    return actions.map(function (pair) {
      return '<button type="button" class="msg-act" data-act="' + pair[0] + '" data-id="' + message.id + '">' + escapeHtml(pair[1]) + '</button>'
    }).join('')
  }

  function sheetActionList(mine, sticker) {
    var actions
    if (mine) {
      actions = []
      if (!sticker) actions.push(['edit', i18n.edit])
      actions = actions.concat([['delete', i18n.delete], ['copy', i18n.copy], ['forward', i18n.forward]])
    } else {
      actions = [['reply', i18n.reply], ['copy', i18n.copy]]
      if (canTranslate && !sticker) actions.push(['translate', i18n.translate])
      actions.push(['delete', i18n.delete])
    }
    return actions.map(function (pair) {
      return '<button type="button" class="msg-sheet-act" data-act="' + pair[0] + '">' + escapeHtml(pair[1]) + '</button>'
    }).join('')
  }

  function reactionsHtml(message) {
    var list = Array.isArray(message.reactions) ? message.reactions : []
    var chips = list.map(function (r) {
      return '<button type="button" class="msg-react-chip' + (r.reactedByMe ? ' is-mine' : '') + '" data-react="' + escapeHtml(r.emoji) + '" data-id="' + message.id + '">' + escapeHtml(r.emoji) + ' <span>' + r.count + '</span></button>'
    }).join('')
    return '<div class="msg-reacts">' + chips +
      '<button type="button" class="msg-react-open" data-react-open="' + message.id + '" aria-label="' + escapeHtml(i18n.react) + '" title="' + escapeHtml(i18n.react) + '">😊</button></div>'
  }

  function renderMessage(message) {
    var mine = Number(message.userId) === meId
    var sticker = !!message.isSticker
    var article = document.createElement('article')
    article.className = 'msg-bubble' + (mine ? ' is-mine' : '') + (sticker ? ' is-sticker' : '')
    article.setAttribute('data-id', String(message.id))
    article.setAttribute('data-mine', mine ? '1' : '0')
    article.setAttribute('data-sticker', sticker ? '1' : '0')
    var html = ''
    if (!sticker) {
      var typeLabel = message.threadTypeLabel || (message.isDirect ? i18n.dmMsg : i18n.groupMsg)
      var typeClass = (message.isDirect || message.threadType === 'dm') ? 'msg-type-dm' : 'msg-type-channel'
      html += '<div class="msg-bubble-meta"><strong>' + escapeHtml(message.userName) + '</strong>' +
        '<span class="msg-type-pill ' + typeClass + '">' + escapeHtml(typeLabel) + '</span>' +
        '<time>' + escapeHtml(message.createdAt) + (message.editedAt ? ' · ' + escapeHtml(i18n.edited) : '') + '</time></div>'
      if (message.replyTo) {
        html += '<div class="msg-quote"><strong>' + escapeHtml(message.replyTo.userName) + '</strong> ' + escapeHtml(message.replyTo.body || '') + '</div>'
      }
      if (message.body) html += '<p class="msg-body-text">' + escapeHtml(message.body) + '</p>'
      if (message._translated) html += '<p class="msg-translated">' + escapeHtml(message._translated) + '</p>'
    }
    if (Array.isArray(message.attachments) && message.attachments.length) {
      html += '<ul class="msg-files">'
      message.attachments.forEach(function (file) {
        if (file.isImage) {
          html += '<li><a href="' + escapeHtml(file.url) + '" target="_blank" rel="noopener"><img src="' + escapeHtml(file.url) + '" alt="' + escapeHtml(file.name) + '" /></a></li>'
        } else {
          html += '<li><a class="msg-file-link" href="' + escapeHtml(file.downloadUrl) + '">' + escapeHtml(file.name) + '</a></li>'
        }
      })
      html += '</ul>'
    }
    if (sticker) {
      html += '<div class="msg-sticker-line">'
      if (message.body) {
        html += '<p class="msg-sticker-emoji msg-body-text" title="' + escapeHtml(i18n.stickerHold || '') + '">' + escapeHtml(message.body) + '</p>'
      }
      html += '<div class="msg-bubble-actions">' + actionButtons(message, mine) + '</div>'
      html += '<button type="button" class="msg-menu-btn" data-msg-menu="' + message.id + '">' + escapeHtml(i18n.menu) + '</button>'
      html += '</div>'
    } else {
      html += '<div class="msg-bubble-actions">' + actionButtons(message, mine) + '</div>'
      html += '<button type="button" class="msg-menu-btn" data-msg-menu="' + message.id + '">' + escapeHtml(i18n.menu) + '</button>'
    }
    html += reactionsHtml(message)
    article.innerHTML = html
    return article
  }

  function upsertMessage(message) {
    var empty = document.getElementById('message-empty')
    if (empty) empty.remove()
    var existing = thread.querySelector('[data-id="' + message.id + '"]')
    var translated = existing && existing.querySelector('.msg-translated')
      ? existing.querySelector('.msg-translated').textContent
      : null
    if (translated) message._translated = translated
    var node = renderMessage(message)
    if (existing) existing.parentNode.replaceChild(node, existing)
    else {
      thread.appendChild(node)
      lastId = Math.max(lastId, Number(message.id) || 0)
      thread.setAttribute('data-last-id', String(lastId))
    }
  }

  function appendMessages(list) {
    if (!list || !list.length) return
    list.forEach(function (message) {
      if (thread.querySelector('[data-id="' + message.id + '"]')) return
      upsertMessage(message)
    })
    thread.scrollTop = thread.scrollHeight
  }

  function applyEvents(events) {
    if (!Array.isArray(events)) return
    events.forEach(function (ev) {
      if (ev.type === 'deleted' && ev.id) {
        var el = thread.querySelector('[data-id="' + ev.id + '"]')
        if (el) el.remove()
        return
      }
      if ((ev.type === 'edited' || ev.type === 'reacted') && ev.message) {
        upsertMessage(ev.message)
      }
    })
  }

  function applyPresence(presence) {
    if (!presence || typeof presence !== 'object') return
    Object.keys(presence).forEach(function (uid) {
      var info = presence[uid] || {}
      var online = !!info.online
      document.querySelectorAll('[data-presence-user="' + uid + '"]').forEach(function (el) {
        var dot = el.querySelector('.msg-presence')
        var label = el.querySelector('[data-presence-label]')
        if (dot) {
          dot.classList.toggle('is-online', online)
          dot.classList.toggle('is-offline', !online)
          dot.title = online ? i18n.online : i18n.offline
        }
        if (label) {
          var parts = label.textContent.split('·').map(function (s) { return s.trim() }).filter(Boolean)
          var msgPreview = parts.length > 1 ? parts[parts.length - 1] : ''
          if (online) {
            label.textContent = (msgPreview && msgPreview !== i18n.online && msgPreview !== i18n.offline)
              ? (i18n.online + ' · ' + msgPreview)
              : i18n.online
          } else if (info.lastSeenAt) {
            label.textContent = msgPreview
              ? (i18n.offline + ' · ' + info.lastSeenAt + ' · ' + msgPreview)
              : (i18n.offline + ' · ' + info.lastSeenAt)
          } else {
            label.textContent = msgPreview ? (i18n.offline + ' · ' + msgPreview) : i18n.offline
          }
        }
      })
      if (peerId && String(uid) === String(peerId)) {
        var stageDot = document.querySelector('[data-stage-presence]')
        var stageLabel = document.querySelector('[data-stage-presence-label]')
        if (stageDot) {
          stageDot.classList.toggle('is-online', online)
          stageDot.classList.toggle('is-offline', !online)
        }
        if (stageLabel) stageLabel.textContent = online ? i18n.online : i18n.offline
      }
    })
  }

  function api(url, options) {
    options = options || {}
    var headers = {
      Accept: 'application/json',
      'X-CSRF-TOKEN': csrf,
      'X-Requested-With': 'XMLHttpRequest',
    }
    if (options.body && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json'
    }
    var finalHeaders = {}
    var k
    for (k in headers) finalHeaders[k] = headers[k]
    if (options.headers) {
      for (k in options.headers) finalHeaders[k] = options.headers[k]
    }
    var fetchOpts = {
      credentials: 'same-origin',
      method: options.method || 'GET',
      headers: finalHeaders,
    }
    if (options.body !== undefined) fetchOpts.body = options.body
    return fetch(url, fetchOpts).then(function (res) {
      return res.json().catch(function () {
        throw new Error('invalid json')
      })
    })
  }

  function poll() {
    var q = new URLSearchParams({ after: String(lastId), since: sinceIso })
    if (peerId) q.set('peer', peerId)
    api('/messages/' + groupId + '/poll?' + q.toString(), { method: 'GET' })
      .then(function (data) {
        if (!data || !data.ok) return
        appendMessages(data.messages || [])
        applyEvents(data.events || [])
        applyPresence(data.presence || {})
        if (data.serverTime) sinceIso = data.serverTime
      })
      .catch(function () {})
  }

  function clearReply() {
    if (replyToInput) replyToInput.value = ''
    if (replyBar) replyBar.hidden = true
  }

  function setReply(message) {
    if (!replyToInput || !replyBar) return
    replyToInput.value = String(message.id)
    if (replyName) replyName.textContent = message.userName || ''
    if (replyPreview) replyPreview.textContent = message.body || ''
    replyBar.hidden = false
    if (body) body.focus()
  }

  function resetCompose() {
    if (body) body.value = ''
    if (filesInput) filesInput.value = ''
    if (cameraInput) cameraInput.value = ''
    if (names) names.textContent = ''
    clearReply()
  }

  function sendFormData(fd) {
    if (sending) return Promise.resolve()
    sending = true
    return api(form.action, { method: 'POST', body: fd })
      .then(function (data) {
        if (!data || !data.ok) {
          toast((data && data.message) || i18n.sendFail)
          return
        }
        resetCompose()
        appendMessages([data.message])
      })
      .catch(function () {
        toast(i18n.sendFail)
      })
      .then(function () {
        sending = false
      })
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text || '').then(function () {
        toast(i18n.copyOk)
      }).catch(function () {
        toast(i18n.copyFail)
      })
    }
    try {
      var ta = document.createElement('textarea')
      ta.value = text || ''
      document.body.appendChild(ta)
      ta.select()
      document.execCommand('copy')
      document.body.removeChild(ta)
      toast(i18n.copyOk)
    } catch (e) {
      toast(i18n.copyFail)
    }
    return Promise.resolve()
  }

  function handleAction(act, id, bubble) {
    var bodyEl = bubble.querySelector('.msg-body-text')
    var text = bodyEl ? bodyEl.textContent || '' : ''

    if (act === 'copy') return copyText(text)

    if (act === 'reply') {
      var strong = bubble.querySelector('.msg-bubble-meta strong')
      setReply({
        id: id,
        userName: strong ? strong.textContent : '',
        body: text,
      })
      return Promise.resolve()
    }

    if (act === 'edit') {
      return askPrompt(i18n.editPrompt, text).then(function (next) {
        if (next === null) return
        return api('/messages/items/' + id + '/update', {
          method: 'POST',
          body: JSON.stringify({ body: next }),
        }).then(function (data) {
          if (!data || !data.ok) {
            toast((data && data.message) || i18n.sendFail)
            return
          }
          upsertMessage(data.message)
        }).catch(function () {
          toast(i18n.sendFail)
        })
      })
    }

    if (act === 'delete') {
      return askConfirm(i18n.deleteConfirm).then(function (ok) {
        if (!ok) return
        return api('/messages/items/' + id + '/delete', {
          method: 'POST',
          body: JSON.stringify({}),
        }).then(function (data) {
          if (!data || !data.ok) {
            toast((data && data.message) || i18n.sendFail)
            return
          }
          bubble.remove()
        }).catch(function () {
          toast(i18n.sendFail)
        })
      })
    }

    if (act === 'forward') {
      var dialog = document.getElementById('message-forward-dialog')
      if (!dialog) return Promise.resolve()
      dialog.setAttribute('data-forward-id', String(id))
      openDialog(dialog)
      return Promise.resolve()
    }

    if (act === 'translate') {
      return api('/messages/items/' + id + '/translate', {
        method: 'POST',
        body: JSON.stringify({}),
      }).then(function (data) {
        if (!data || !data.ok) {
          toast((data && data.message) || i18n.translateFail)
          return
        }
        var t = bubble.querySelector('.msg-translated')
        if (!t) {
          t = document.createElement('p')
          t.className = 'msg-translated'
          if (bodyEl && bodyEl.parentNode) bodyEl.parentNode.insertBefore(t, bodyEl.nextSibling)
          else bubble.appendChild(t)
        }
        t.textContent = data.text
      }).catch(function () {
        toast(i18n.translateFail)
      })
    }

    return Promise.resolve()
  }

  function openActionSheet(bubble) {
    if (!actionSheet || !sheetActions || !bubble) return
    sheetMessageId = Number(bubble.getAttribute('data-id') || 0)
    var mine = bubble.getAttribute('data-mine') === '1'
    var sticker = bubble.getAttribute('data-sticker') === '1'
    sheetActions.innerHTML = sheetActionList(mine, sticker)
    openDialog(actionSheet)
  }

  function applyReaction(emoji) {
    if (!sheetMessageId || !emoji) return Promise.resolve()
    return api('/messages/items/' + sheetMessageId + '/react', {
      method: 'POST',
      body: JSON.stringify({ emoji: emoji }),
    }).then(function (data) {
      closeDialog(reactDialog)
      closeDialog(actionSheet)
      if (data && data.ok && data.message) upsertMessage(data.message)
      else if (data && data.message) toast(data.message)
    }).catch(function () {
      toast(i18n.sendFail)
    })
  }

  function clearLongPress() {
    if (longPressTimer) {
      clearTimeout(longPressTimer)
      longPressTimer = null
    }
  }

  function openStickerPop(emoji) {
    if (!stickerPop || !stickerPopEmoji || !emoji) return
    stickerPopEmoji.textContent = emoji
    openDialog(stickerPop)
  }

  function closeStickerPop() {
    if (stickerPop) closeDialog(stickerPop)
  }

  function startStickerLongPress(stickerEl) {
    if (!stickerEl) return
    stickerPressTarget = stickerEl
    stickerPressMoved = false
    clearLongPress()
    longPressTimer = setTimeout(function () {
      longPressTimer = null
      var text = (stickerEl.textContent || '').trim()
      if (text) openStickerPop(text)
    }, 380)
  }

  // Prompt / confirm dialogs
  if (promptOk) {
    promptOk.addEventListener('click', function (e) {
      e.preventDefault()
      var val = promptInput ? promptInput.value : ''
      closeDialog(promptDialog)
      if (promptResolver) {
        var r = promptResolver
        promptResolver = null
        r(val)
      }
    })
  }
  if (promptCancel) {
    promptCancel.addEventListener('click', function (e) {
      e.preventDefault()
      closeDialog(promptDialog)
      if (promptResolver) {
        var r = promptResolver
        promptResolver = null
        r(null)
      }
    })
  }
  if (confirmOk) {
    confirmOk.addEventListener('click', function (e) {
      e.preventDefault()
      closeDialog(confirmDialog)
      if (confirmResolver) {
        var r = confirmResolver
        confirmResolver = null
        r(true)
      }
    })
  }
  if (confirmCancel) {
    confirmCancel.addEventListener('click', function (e) {
      e.preventDefault()
      closeDialog(confirmDialog)
      if (confirmResolver) {
        var r = confirmResolver
        confirmResolver = null
        r(false)
      }
    })
  }

  thread.addEventListener('click', function (e) {
    var t = e.target
    if (!t || !t.closest) return

    var menuBtn = t.closest('[data-msg-menu]')
    if (menuBtn) {
      e.preventDefault()
      e.stopPropagation()
      openActionSheet(menuBtn.closest('.msg-bubble'))
      return
    }

    var openBtn = t.closest('[data-react-open]')
    if (openBtn) {
      e.preventDefault()
      e.stopPropagation()
      sheetMessageId = Number(openBtn.getAttribute('data-react-open') || 0)
      openDialog(reactDialog)
      return
    }

    var actBtn = t.closest('.msg-act[data-act]')
    if (actBtn) {
      e.preventDefault()
      e.stopPropagation()
      var bubble = actBtn.closest('.msg-bubble')
      if (!bubble) return
      handleAction(actBtn.getAttribute('data-act'), Number(actBtn.getAttribute('data-id')), bubble)
      return
    }

    var reactBtn = t.closest('[data-react]')
    if (reactBtn) {
      e.preventDefault()
      api('/messages/items/' + reactBtn.getAttribute('data-id') + '/react', {
        method: 'POST',
        body: JSON.stringify({ emoji: reactBtn.getAttribute('data-react') }),
      }).then(function (data) {
        if (data && data.ok && data.message) upsertMessage(data.message)
      }).catch(function () {
        toast(i18n.sendFail)
      })
    }
  })

  thread.addEventListener('touchstart', function (e) {
    var sticker = e.target.closest ? e.target.closest('.msg-sticker-emoji') : null
    if (sticker) {
      startStickerLongPress(sticker)
      return
    }
    if (!isMobileUi()) return
    var bubble = e.target.closest ? e.target.closest('.msg-bubble') : null
    if (!bubble || (e.target.closest && e.target.closest('a,button,input,textarea,label'))) return
    clearLongPress()
    longPressTimer = setTimeout(function () {
      openActionSheet(bubble)
    }, 480)
  }, { passive: true })

  thread.addEventListener('touchmove', function () {
    stickerPressMoved = true
    clearLongPress()
  }, { passive: true })
  thread.addEventListener('touchend', function () {
    clearLongPress()
    stickerPressTarget = null
  })
  thread.addEventListener('touchcancel', function () {
    clearLongPress()
    stickerPressTarget = null
  })
  thread.addEventListener('contextmenu', function (e) {
    var sticker = e.target.closest ? e.target.closest('.msg-sticker-emoji') : null
    if (sticker) {
      e.preventDefault()
      openStickerPop((sticker.textContent || '').trim())
      return
    }
    if (!isMobileUi()) return
    var bubble = e.target.closest ? e.target.closest('.msg-bubble') : null
    if (!bubble) return
    e.preventDefault()
    openActionSheet(bubble)
  })

  // デスクトップ: スタンプ長押しで拡大
  thread.addEventListener('mousedown', function (e) {
    if (e.button !== 0) return
    var sticker = e.target.closest ? e.target.closest('.msg-sticker-emoji') : null
    if (!sticker) return
    startStickerLongPress(sticker)
  })
  thread.addEventListener('mousemove', function () {
    if (stickerPressTarget) {
      stickerPressMoved = true
      clearLongPress()
    }
  })
  thread.addEventListener('mouseup', function () {
    clearLongPress()
    stickerPressTarget = null
  })
  thread.addEventListener('mouseleave', function () {
    clearLongPress()
    stickerPressTarget = null
  })

  if (stickerPop) {
    stickerPop.addEventListener('click', function () {
      closeStickerPop()
    })
  }

  if (sheetActions) {
    sheetActions.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-act]') : null
      if (!btn || !sheetMessageId) return
      var bubble = thread.querySelector('.msg-bubble[data-id="' + sheetMessageId + '"]')
      if (!bubble) return
      closeDialog(actionSheet)
      handleAction(btn.getAttribute('data-act'), sheetMessageId, bubble)
    })
  }

  function bindSheetReact(rootId) {
    var root = document.getElementById(rootId)
    if (!root) return
    root.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-sheet-react]') : null
      if (!btn) return
      e.preventDefault()
      applyReaction(btn.getAttribute('data-sheet-react'))
    })
  }
  bindSheetReact('message-sheet-reacts')
  bindSheetReact('message-react-grid')

  var replyClear = document.getElementById('message-reply-clear')
  if (replyClear) replyClear.addEventListener('click', clearReply)

  var forwardList = document.getElementById('message-forward-list')
  if (forwardList) {
    forwardList.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.msg-forward-item') : null
      if (!btn) return
      var dialog = document.getElementById('message-forward-dialog')
      var id = Number(dialog ? dialog.getAttribute('data-forward-id') || 0 : 0)
      if (!id) return
      var payload = { group_id: Number(btn.getAttribute('data-group-id')) }
      var peer = btn.getAttribute('data-peer-id')
      if (peer) payload.peer_user_id = Number(peer)
      api('/messages/items/' + id + '/forward', {
        method: 'POST',
        body: JSON.stringify(payload),
      }).then(function (data) {
        closeDialog(dialog)
        if (!data || !data.ok) {
          toast((data && data.message) || i18n.sendFail)
          return
        }
        if (Number(payload.group_id) === Number(groupId) &&
          String(payload.peer_user_id || '') === String(peerId || '')) {
          appendMessages([data.message])
        } else {
          toast(i18n.copyOk)
        }
      }).catch(function () {
        toast(i18n.sendFail)
      })
    })
  }

  if (filesInput) {
    filesInput.addEventListener('change', function () {
      var list = []
      var i
      for (i = 0; i < (filesInput.files ? filesInput.files.length : 0); i++) {
        list.push(filesInput.files[i].name)
      }
      if (names) names.textContent = list.join(', ')
    })
  }

  if (cameraInput) {
    cameraInput.addEventListener('change', function () {
      var shot = cameraInput.files && cameraInput.files[0]
      if (!shot) return
      var fd = new FormData(form)
      fd.delete('attachments[]')
      fd.set('body', '')
      fd.append('attachments[]', shot, shot.name || ('camera-' + Date.now() + '.jpg'))
      if (names) names.textContent = i18n.sending || '...'
      sendFormData(fd)
    })
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault()
    sendFormData(new FormData(form))
  })

  var quickOk = document.getElementById('message-quick-ok')
  if (quickOk) {
    quickOk.addEventListener('click', function (e) {
      e.preventDefault()
      e.stopPropagation()
      var fd = new FormData(form)
      fd.set('body', '👍')
      fd.delete('attachments[]')
      sendFormData(fd)
    })
  }

  var emojiBtn = document.getElementById('message-emoji-btn')
  if (emojiBtn) {
    emojiBtn.addEventListener('click', function (e) {
      e.preventDefault()
      e.stopPropagation()
      openDialog(emojiDialog)
    })
  }

  var emojiGrid = document.getElementById('message-emoji-grid')
  if (emojiGrid) {
    emojiGrid.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-emoji]') : null
      if (!btn) return
      e.preventDefault()
      var em = btn.getAttribute('data-emoji')
      if (!em) return
      closeDialog(emojiDialog)
      // Messenger風: 絵文字単体はスタンプとしてすぐ送信
      var fd = new FormData(form)
      fd.set('body', em)
      fd.delete('attachments[]')
      sendFormData(fd)
    })
  }

  var micBtn = document.getElementById('message-mic')
  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
  if (micBtn) {
    micBtn.addEventListener('click', function (e) {
      e.preventDefault()
      e.stopPropagation()
      var host = location.hostname
      if (!window.isSecureContext && host !== 'localhost' && host !== '127.0.0.1') {
        toast(i18n.voiceHttps || i18n.voiceUnsupported)
        return
      }
      if (!SpeechRecognition) {
        toast(i18n.voiceUnsupported)
        return
      }
      if (listening && recognition) {
        try { recognition.stop() } catch (err) {}
        return
      }
      recognition = new SpeechRecognition()
      recognition.lang = document.documentElement.lang === 'en' ? 'en-US' : 'ja-JP'
      recognition.interimResults = true
      recognition.continuous = false
      recognition.onstart = function () {
        listening = true
        micBtn.classList.add('is-listening')
        micBtn.setAttribute('aria-pressed', 'true')
        if (names) names.textContent = i18n.voiceListening
      }
      recognition.onend = function () {
        listening = false
        micBtn.classList.remove('is-listening')
        micBtn.setAttribute('aria-pressed', 'false')
        if (names && names.textContent === i18n.voiceListening) names.textContent = ''
      }
      recognition.onerror = function (ev) {
        listening = false
        micBtn.classList.remove('is-listening')
        micBtn.setAttribute('aria-pressed', 'false')
        if (names) names.textContent = ''
        var err = ev && ev.error
        if (err && err !== 'aborted' && err !== 'no-speech') toast(i18n.voiceUnsupported)
      }
      recognition.onresult = function (event) {
        var transcript = ''
        var i
        for (i = event.resultIndex; i < event.results.length; i++) {
          transcript += event.results[i][0].transcript
        }
        if (!body || !transcript) return
        var sep = body.value && !/\s$/.test(body.value) ? ' ' : ''
        if (event.results[event.results.length - 1].isFinal) {
          body.value = (body.value || '') + sep + transcript
        }
      }
      try {
        recognition.start()
      } catch (err) {
        toast(i18n.voiceUnsupported)
      }
    })
  }

  thread.scrollTop = thread.scrollHeight
  setInterval(poll, 4000)
  poll()
})()
