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
  var maxAttachmentBytes = Number(cfg.maxAttachmentBytes || (20 * 1024 * 1024))
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

  var actIcons = {
    edit: '<path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34a.9959.9959 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>',
    delete: '<path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>',
    copy: '<path fill="currentColor" d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>',
    forward: '<path fill="currentColor" d="M12 8V4l8 8-8 8v-4H4V8h8z"/>',
    reply: '<path fill="currentColor" d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/>',
  }

  function actButton(act, messageId, label) {
    var path = actIcons[act]
    if (path) {
      return '<button type="button" class="msg-act msg-act-icon" data-act="' + act + '" data-id="' + messageId + '" title="' + escapeHtml(label) + '" aria-label="' + escapeHtml(label) + '">' +
        '<svg class="msg-act-svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">' + path + '</svg></button>'
    }
    return '<button type="button" class="msg-act" data-act="' + act + '" data-id="' + messageId + '">' + escapeHtml(label) + '</button>'
  }

  function actionButtons(message, mine) {
    var sticker = !!message.isSticker
    var actions
    if (mine) {
      actions = []
      if (!sticker) {
        actions.push(['edit', i18n.edit])
        actions.push(['delete', i18n.delete])
        actions.push(['copy', i18n.copy])
        actions.push(['forward', i18n.forward])
      } else {
        actions.push(['delete', i18n.delete])
      }
    } else {
      actions = [['reply', i18n.reply]]
      if (!sticker) {
        actions.push(['copy', i18n.copy])
        if (canTranslate) actions.push(['translate', i18n.translate])
      }
      actions.push(['delete', i18n.delete])
    }
    return actions.map(function (pair) {
      return actButton(pair[0], message.id, pair[1])
    }).join('')
  }

  function sheetActionList(mine, sticker) {
    var actions
    if (mine) {
      actions = []
      if (!sticker) {
        actions.push(['edit', i18n.edit])
        actions.push(['delete', i18n.delete])
        actions.push(['copy', i18n.copy])
        actions.push(['forward', i18n.forward])
      } else {
        actions.push(['delete', i18n.delete])
      }
    } else {
      actions = [['reply', i18n.reply]]
      if (!sticker) {
        actions.push(['copy', i18n.copy])
        if (canTranslate) actions.push(['translate', i18n.translate])
      }
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

  function footerHtml(message, mine) {
    return '<div class="msg-footer">' +
      reactionsHtml(message) +
      '<div class="msg-bubble-actions">' + actionButtons(message, mine) + '</div>' +
      '<button type="button" class="msg-menu-btn" data-msg-menu="' + message.id + '">' + escapeHtml(i18n.menu) + '</button>' +
      '</div>'
  }

  function messageTimeLabel(message) {
    var label = String(message.createdAt || '')
    if (message.editedAt) label += (label ? ' · ' : '') + (i18n.edited || '')
    return label
  }

  function renderMessage(message) {
    var mine = Number(message.userId) === meId
    var sticker = !!message.isSticker
    var unread = !mine && !!message.isUnread
    var entry = document.createElement('div')
    entry.className = 'msg-entry' + (mine ? ' is-mine' : '') + (sticker ? ' is-sticker' : '') + (unread ? ' is-unread' : '')
    entry.id = 'msg-' + message.id
    entry.setAttribute('data-id', String(message.id))
    entry.setAttribute('data-mine', mine ? '1' : '0')
    entry.setAttribute('data-sticker', sticker ? '1' : '0')
    entry.setAttribute('data-unread', unread ? '1' : '0')

    var html = ''
    if (!sticker) {
      var typeLabel = message.threadTypeLabel || (message.isDirect ? i18n.dmMsg : i18n.groupMsg)
      var typeClass = (message.isDirect || message.threadType === 'dm') ? 'msg-type-dm' : 'msg-type-channel'
      html += '<div class="msg-entry-aside">' +
        '<strong class="msg-entry-name">' + escapeHtml(message.userName) + '</strong>' +
        '<span class="msg-type-pill ' + typeClass + '">' + escapeHtml(typeLabel) + '</span>' +
        (unread ? '<span class="msg-unread-pill">' + escapeHtml(i18n.unread || 'Unread') + '</span>' : '') +
        '</div>'
    }

    var timeLabel = messageTimeLabel(message)
    if (unread) {
      timeLabel += (timeLabel ? ' · ' : '') + (i18n.unread || 'Unread')
    }
    html += '<article class="msg-bubble' + (mine ? ' is-mine' : '') + (sticker ? ' is-sticker' : '') + (unread ? ' is-unread' : '') + '"' +
      ' data-id="' + message.id + '" data-mine="' + (mine ? '1' : '0') + '" data-sticker="' + (sticker ? '1' : '0') + '"' +
      (timeLabel ? ' title="' + escapeHtml(timeLabel) + '"' : '') + '>'
    if (!sticker && timeLabel) {
      html += '<time class="msg-bubble-time">' + escapeHtml(timeLabel) + '</time>'
    }
    if (!sticker) {
      if (message.replyTo) {
        html += '<div class="msg-quote"><strong>' + escapeHtml(message.replyTo.userName) + '</strong> ' + escapeHtml(message.replyTo.body || '') + '</div>'
      }
      if (message.body) html += '<p class="msg-body-text">' + escapeHtml(message.body) + '</p>'
      if (message._translated) html += '<p class="msg-translated">' + escapeHtml(message._translated) + '</p>'
    } else if (message.body) {
      html += '<div class="msg-sticker-line"><p class="msg-sticker-emoji msg-body-text" title="' + escapeHtml(i18n.stickerHold || '') + '">' + escapeHtml(message.body) + '</p></div>'
    }
    if (Array.isArray(message.attachments) && message.attachments.length) {
      html += '<ul class="msg-files">'
      message.attachments.forEach(function (file) {
        var actions = '<div class="msg-file-actions">' +
          '<a class="msg-file-action" href="' + escapeHtml(file.downloadUrl) + '" download>' + escapeHtml(i18n.download || 'Download') + '</a>'
        if (file.canSaveToPhotos) {
          actions += '<button type="button" class="msg-file-action" data-save-to-photos="' + Number(file.id) + '" data-save-to-photos-url="' +
            escapeHtml(file.saveToPhotosUrl || ('/messages/attachments/' + file.id + '/to-photos')) + '">' +
            escapeHtml(i18n.saveToPhotos || 'Add to Photos') + '</button>'
        }
        actions += '</div>'
        if (file.isImage) {
          html += '<li class="msg-file-item is-image"><a class="msg-file-preview" href="' + escapeHtml(file.url) +
            '" target="_blank" rel="noopener"><img src="' + escapeHtml(file.url) + '" alt="' + escapeHtml(file.name) +
            '" /></a>' + actions + '</li>'
        } else {
          html += '<li class="msg-file-item"><a class="msg-file-link" href="' + escapeHtml(file.downloadUrl) + '">' +
            escapeHtml(file.name) + '</a>' + actions + '</li>'
        }
      })
      html += '</ul>'
    }
    html += footerHtml(message, mine)
    html += '</article>'
    entry.innerHTML = html
    return entry
  }

  function findEntry(id) {
    return thread.querySelector('.msg-entry[data-id="' + id + '"]')
  }

  function upsertMessage(message) {
    var empty = document.getElementById('message-empty')
    if (empty) empty.remove()
    var existing = findEntry(message.id)
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
      if (findEntry(message.id)) return
      upsertMessage(message)
    })
    thread.scrollTop = thread.scrollHeight
  }

  function applyEvents(events) {
    if (!Array.isArray(events)) return
    events.forEach(function (ev) {
      if (ev.type === 'deleted' && ev.id) {
        var el = findEntry(ev.id)
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
        // 未読表示中はプレビュー文言を上書きしない
        if (label && !el.classList.contains('is-unread')) {
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

  function updateMessagesUnreadBadges(count) {
    var n = Math.max(0, Number(count) || 0)
    var label = n > 99 ? '99+' : String(n)
    document.querySelectorAll('[data-messages-unread-badge]').forEach(function (el) {
      el.textContent = label
      el.hidden = n <= 0
    })
    document.querySelectorAll('[data-nav-unread="messages"]').forEach(function (el) {
      if (n > 0) el.classList.add('has-nav-badge')
      else el.classList.remove('has-nav-badge')
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
        if (!isDirect && data.wallpaper) applyServerWallpaper(data.wallpaper, false)
        if (data.serverTime) sinceIso = data.serverTime
        if (typeof data.unreadCount === 'number') updateMessagesUnreadBadges(data.unreadCount)
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

  function resizeComposeBody() {
    if (!body) return
    body.style.height = '0px'
    var computed = window.getComputedStyle(body)
    var max = parseInt(computed.maxHeight, 10)
    if (!max || isNaN(max)) max = 160
    var next = Math.max(body.scrollHeight, parseInt(computed.minHeight, 10) || 0)
    if (next > max) {
      body.style.height = max + 'px'
      body.style.overflowY = 'auto'
    } else {
      body.style.height = next + 'px'
      body.style.overflowY = 'hidden'
    }
  }

  function resetCompose() {
    if (body) {
      body.value = ''
      resizeComposeBody()
    }
    if (filesInput) filesInput.value = ''
    if (cameraInput) cameraInput.value = ''
    if (names) names.textContent = ''
    clearReply()
  }

  function fileTooLargeMessage() {
    return i18n.fileTooLarge || 'File too large'
  }

  function filesWithinLimit(fileList) {
    if (!fileList || !fileList.length) return true
    var i
    for (i = 0; i < fileList.length; i++) {
      if (fileList[i] && Number(fileList[i].size || 0) > maxAttachmentBytes) {
        return false
      }
    }
    return true
  }

  function formDataWithinLimit(fd) {
    if (!fd || typeof fd.getAll !== 'function') return true
    var files = fd.getAll('attachments[]')
    var i
    for (i = 0; i < files.length; i++) {
      var f = files[i]
      if (f && typeof f.size === 'number' && f.size > maxAttachmentBytes) {
        return false
      }
    }
    return true
  }

  function sendFormData(fd) {
    if (sending) return Promise.resolve()
    if (!formDataWithinLimit(fd)) {
      toast(fileTooLargeMessage())
      return Promise.resolve()
    }
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
      var entry = bubble.closest ? bubble.closest('.msg-entry') : null
      var strong = entry
        ? entry.querySelector('.msg-entry-name')
        : bubble.querySelector('.msg-bubble-meta strong')
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

    var saveBtn = t.closest('[data-save-to-photos]')
    if (saveBtn) {
      e.preventDefault()
      e.stopPropagation()
      if (saveBtn.disabled) return
      var saveUrl = saveBtn.getAttribute('data-save-to-photos-url') ||
        ('/messages/attachments/' + saveBtn.getAttribute('data-save-to-photos') + '/to-photos')
      saveBtn.disabled = true
      api(saveUrl, { method: 'POST', body: JSON.stringify({}) })
        .then(function (data) {
          if (data && data.ok) {
            toast(data.message || i18n.saveToPhotosOk || '')
            return
          }
          toast((data && data.message) || i18n.saveToPhotosFail || i18n.sendFail)
        })
        .catch(function () {
          toast(i18n.saveToPhotosFail || i18n.sendFail)
        })
        .finally(function () {
          saveBtn.disabled = false
        })
      return
    }

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
      if (!filesWithinLimit(filesInput.files)) {
        toast(fileTooLargeMessage())
        filesInput.value = ''
        if (names) names.textContent = ''
        return
      }
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
      if (Number(shot.size || 0) > maxAttachmentBytes) {
        toast(fileTooLargeMessage())
        cameraInput.value = ''
        return
      }
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
          resizeComposeBody()
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
  if (body) {
    body.addEventListener('input', resizeComposeBody)
    window.addEventListener('resize', resizeComposeBody)
    resizeComposeBody()
  }
  setInterval(poll, 4000)
  poll()

  // Chat wallpaper: group = shared server / DM = device-local
  var isDirect = !!cfg.isDirect || thread.getAttribute('data-thread-type') === 'dm'
  var BG_KEY = 'sa2.msg.wallpaper.dm.' + groupId + '.' + (peerId || '0')
  var bgDialog = document.getElementById('message-bg-dialog')
  var bgBtn = document.getElementById('message-bg-btn')
  var bgFile = document.getElementById('message-bg-file')
  var bgClear = document.getElementById('message-bg-clear')
  var bgPresets = document.getElementById('message-bg-presets')
  var wallpaperUpdatedAt = null

  function readDmBgPref() {
    try {
      var raw = window.localStorage.getItem(BG_KEY)
      if (!raw) return { type: 'theme', value: 'default' }
      var parsed = JSON.parse(raw)
      if (parsed && parsed.type === 'image' && parsed.dataUrl) {
        return { type: 'image', dataUrl: parsed.dataUrl }
      }
      if (parsed && parsed.type === 'theme' && parsed.value) {
        return { type: 'theme', value: String(parsed.value) }
      }
    } catch (e) {}
    return { type: 'theme', value: 'default' }
  }

  function writeDmBgPref(pref) {
    try {
      window.localStorage.setItem(BG_KEY, JSON.stringify(pref))
    } catch (e) {
      toast(i18n.bgFail || i18n.sendFail)
    }
  }

  function markActivePreset(value) {
    if (!bgPresets) return
    var buttons = bgPresets.querySelectorAll('[data-bg]')
    var i
    for (i = 0; i < buttons.length; i++) {
      var btn = buttons[i]
      if (value && btn.getAttribute('data-bg') === value) btn.classList.add('is-active')
      else btn.classList.remove('is-active')
    }
  }

  function applyWallpaper(pref) {
    if (!thread) return
    if (pref && pref.type === 'image' && (pref.dataUrl || pref.url)) {
      var src = pref.dataUrl || pref.url
      thread.setAttribute('data-bg', 'image')
      thread.style.setProperty('--msg-bg-image', 'linear-gradient(rgba(15,23,42,0.28), rgba(15,23,42,0.28)), url("' + src + '")')
      markActivePreset('')
      return
    }
    var theme = (pref && pref.value) ? pref.value : 'default'
    if (theme === 'default') thread.removeAttribute('data-bg')
    else thread.setAttribute('data-bg', theme)
    thread.style.removeProperty('--msg-bg-image')
    markActivePreset(theme)
  }

  function applyServerWallpaper(wallpaper, force) {
    if (!wallpaper || isDirect) return
    if (!force && wallpaper.updatedAt && wallpaperUpdatedAt && wallpaper.updatedAt === wallpaperUpdatedAt) {
      return
    }
    wallpaperUpdatedAt = wallpaper.updatedAt || wallpaperUpdatedAt
    if (wallpaper.type === 'image' && wallpaper.url) {
      applyWallpaper({ type: 'image', url: wallpaper.url })
      return
    }
    applyWallpaper({ type: 'theme', value: wallpaper.value || 'default' })
  }

  function saveGroupTheme(theme) {
    return api('/messages/' + groupId + '/wallpaper', {
      method: 'POST',
      body: JSON.stringify({ theme: theme }),
    }).then(function (data) {
      if (!data || !data.ok) {
        toast((data && data.message) || i18n.bgFail || i18n.sendFail)
        return
      }
      applyServerWallpaper(data.wallpaper, true)
      toast(i18n.bgShared || i18n.bgSaved || '')
      closeDialog(bgDialog)
    }).catch(function () {
      toast(i18n.bgFail || i18n.sendFail)
    })
  }

  function saveGroupClear() {
    var fd = new FormData()
    fd.set('clear', '1')
    return api('/messages/' + groupId + '/wallpaper', {
      method: 'POST',
      body: fd,
    }).then(function (data) {
      if (!data || !data.ok) {
        toast((data && data.message) || i18n.bgFail || i18n.sendFail)
        return
      }
      applyServerWallpaper(data.wallpaper, true)
      toast(i18n.bgShared || i18n.bgSaved || '')
      closeDialog(bgDialog)
    }).catch(function () {
      toast(i18n.bgFail || i18n.sendFail)
    })
  }

  function saveGroupImage(file) {
    var fd = new FormData()
    fd.append('wallpaper', file, file.name || ('wallpaper-' + Date.now() + '.jpg'))
    return api('/messages/' + groupId + '/wallpaper', {
      method: 'POST',
      body: fd,
    }).then(function (data) {
      if (!data || !data.ok) {
        toast((data && data.message) || i18n.bgFail || i18n.sendFail)
        return
      }
      applyServerWallpaper(data.wallpaper, true)
      toast(i18n.bgShared || i18n.bgSaved || '')
      closeDialog(bgDialog)
    }).catch(function () {
      toast(i18n.bgFail || i18n.sendFail)
    })
  }

  function compressImageFile(file, done) {
    if (!file || !file.type || file.type.indexOf('image/') !== 0) {
      done(null)
      return
    }
    if (file.size > 8 * 1024 * 1024) {
      toast(i18n.bgTooLarge || i18n.fileTooLarge)
      done(null)
      return
    }
    var reader = new FileReader()
    reader.onerror = function () {
      toast(i18n.bgFail || i18n.sendFail)
      done(null)
    }
    reader.onload = function () {
      var img = new Image()
      img.onerror = function () {
        toast(i18n.bgFail || i18n.sendFail)
        done(null)
      }
      img.onload = function () {
        var maxSide = 1600
        var w = img.width || 1
        var h = img.height || 1
        var scale = Math.min(1, maxSide / Math.max(w, h))
        var canvas = document.createElement('canvas')
        canvas.width = Math.max(1, Math.round(w * scale))
        canvas.height = Math.max(1, Math.round(h * scale))
        var ctx = canvas.getContext('2d')
        if (!ctx) {
          done(null)
          return
        }
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
        canvas.toBlob(function (blob) {
          if (!blob) {
            done(null)
            return
          }
          if (blob.size > 2.5 * 1024 * 1024) {
            canvas.toBlob(function (blob2) {
              if (!blob2 || blob2.size > 3 * 1024 * 1024) {
                toast(i18n.bgTooLarge || i18n.fileTooLarge)
                done(null)
                return
              }
              done(blob2)
            }, 'image/jpeg', 0.65)
            return
          }
          done(blob)
        }, 'image/jpeg', 0.82)
      }
      img.src = String(reader.result || '')
    }
    reader.readAsDataURL(file)
  }

  if (isDirect) {
    applyWallpaper(readDmBgPref())
  } else if (cfg.wallpaper) {
    applyServerWallpaper(cfg.wallpaper, true)
  } else {
    applyWallpaper({ type: 'theme', value: 'default' })
  }

  if (bgBtn && bgDialog) {
    bgBtn.addEventListener('click', function (e) {
      e.preventDefault()
      openDialog(bgDialog)
    })
  }
  if (bgPresets) {
    bgPresets.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-bg]') : null
      if (!btn) return
      e.preventDefault()
      var theme = btn.getAttribute('data-bg') || 'default'
      if (isDirect) {
        var pref = { type: 'theme', value: theme }
        writeDmBgPref(pref)
        applyWallpaper(pref)
        toast(i18n.bgSaved || '')
        closeDialog(bgDialog)
        return
      }
      saveGroupTheme(theme)
    })
  }
  if (bgFile) {
    bgFile.addEventListener('change', function () {
      var file = bgFile.files && bgFile.files[0]
      if (!file) return
      compressImageFile(file, function (blob) {
        bgFile.value = ''
        if (!blob) return
        if (isDirect) {
          var reader = new FileReader()
          reader.onload = function () {
            var pref = { type: 'image', dataUrl: String(reader.result || '') }
            writeDmBgPref(pref)
            applyWallpaper(pref)
            toast(i18n.bgSaved || '')
            closeDialog(bgDialog)
          }
          reader.onerror = function () {
            toast(i18n.bgFail || i18n.sendFail)
          }
          reader.readAsDataURL(blob)
          return
        }
        var upload = new File([blob], 'wallpaper.jpg', { type: 'image/jpeg' })
        saveGroupImage(upload)
      })
    })
  }
  if (bgClear) {
    bgClear.addEventListener('click', function (e) {
      e.preventDefault()
      if (isDirect) {
        var pref = { type: 'theme', value: 'default' }
        writeDmBgPref(pref)
        applyWallpaper(pref)
        toast(i18n.bgSaved || '')
        closeDialog(bgDialog)
        return
      }
      saveGroupClear()
    })
  }

  var backBtn = document.getElementById('message-back-btn')
  var timeHideTimer = 0

  function setInboxOpen(open) {
    document.body.classList.toggle('msg-inbox-open', !!open)
    if (open) {
      var rail = document.getElementById('message-rail')
      if (rail) rail.scrollTop = 0
    } else {
      thread.scrollTop = thread.scrollHeight
    }
  }

  function flashBubbleTime(bubble) {
    if (!bubble) return
    thread.querySelectorAll('.msg-bubble.is-time-visible').forEach(function (el) {
      if (el !== bubble) el.classList.remove('is-time-visible')
    })
    bubble.classList.add('is-time-visible')
    clearTimeout(timeHideTimer)
    timeHideTimer = setTimeout(function () {
      bubble.classList.remove('is-time-visible')
    }, 2200)
  }

  if (backBtn) {
    backBtn.addEventListener('click', function (e) {
      e.preventDefault()
      if (isMobileUi()) {
        window.location.href = '/messages'
        return
      }
      setInboxOpen(true)
    })
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return
    if (!isMobileUi() || !document.body.classList.contains('msg-mobile-chat')) return
    if (document.body.classList.contains('msg-inbox-open')) return
    window.location.href = '/messages'
  })

  var railEl = document.getElementById('message-rail')
  if (railEl) {
    railEl.addEventListener('click', function (e) {
      if (!isMobileUi() || !document.body.classList.contains('msg-inbox-open')) return
      var link = e.target.closest ? e.target.closest('a.msg-rail-item') : null
      if (!link || !link.classList.contains('is-active')) return
      e.preventDefault()
      setInboxOpen(false)
    })
  }

  thread.addEventListener('click', function (e) {
    var t = e.target
    if (!t || !t.closest) return
    if (t.closest('a,button,input,textarea,label,.msg-file-actions')) return
    var bubble = t.closest('.msg-bubble')
    if (!bubble || bubble.classList.contains('is-sticker')) return
    flashBubbleTime(bubble)
  })

  ;(function scrollToHashMessage() {
    var hash = window.location.hash || ''
    if (!/^#msg-\d+$/.test(hash)) return
    var target = document.getElementById(hash.slice(1))
    if (!target) return
    requestAnimationFrame(function () {
      target.scrollIntoView({ block: 'center', behavior: 'smooth' })
      target.classList.add('is-hash-target')
      setTimeout(function () {
        target.classList.remove('is-hash-target')
      }, 1800)
    })
  })()
})()
