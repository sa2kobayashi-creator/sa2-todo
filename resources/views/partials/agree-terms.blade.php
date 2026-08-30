{{--
  利用規約・プライバシー同意（申請／招待登録共通）
  @var string $submitButtonId
  @var string $submitLabel
  @var string|null $readyHint  両方確認後の案内
--}}
@php
  $readyHint = $readyHint ?? __('内容を確認済みです。同意する場合はチェックを入れてください。');
@endphp
<div class="agree-terms-box" id="agree-terms-box" role="group" aria-labelledby="agree-terms-box-title">
  <p class="agree-terms-box-title" id="agree-terms-box-title">{{ __('同意の前に、規約の確認が必要です') }}</p>
  <p class="agree-terms-box-lead" id="agree-terms-hint">
    {{ __('チェックボックスは最初は押せません。「利用規約」「プライバシーポリシー」をそれぞれ開いて内容を確認すると、同意チェックが有効になります。') }}
  </p>
  <div class="agree-terms-actions">
    <a
      href="/terms"
      target="_blank"
      rel="noopener"
      class="agree-terms-open-btn"
      id="agree-link-terms"
      data-legal="terms"
    >
      <span class="agree-terms-open-label">{{ __('利用規約を開く') }}</span>
      <span class="agree-terms-status" id="agree-status-terms" data-status="pending">{{ __('未確認') }}</span>
    </a>
    <a
      href="/privacy"
      target="_blank"
      rel="noopener"
      class="agree-terms-open-btn"
      id="agree-link-privacy"
      data-legal="privacy"
    >
      <span class="agree-terms-open-label">{{ __('プライバシーポリシーを開く') }}</span>
      <span class="agree-terms-status" id="agree-status-privacy" data-status="pending">{{ __('未確認') }}</span>
    </a>
  </div>
  <div class="agree-terms-check-wrap" id="agree-terms-check-wrap">
    <label class="checkbox-inline agree-terms-row" for="agree-terms">
      <input
        type="checkbox"
        name="agreeTerms"
        id="agree-terms"
        value="1"
        @checked(old('agreeTerms'))
        required
        disabled
        aria-describedby="agree-terms-hint"
      />
      <span>{{ __('利用規約とプライバシーポリシーに同意します。') }}</span>
    </label>
    <p class="agree-terms-tap-hint" id="agree-terms-tap-hint" hidden>
      {{ __('まだ規約の確認が終わっていません。上のボタンから両方を開いてください。') }}
    </p>
  </div>
</div>
<button type="submit" class="auth-submit" id="{{ $submitButtonId }}" disabled>{{ $submitLabel }}</button>
<script>
  (() => {
    const TERMS_KEY = 'sa2_legal_terms_read'
    const PRIVACY_KEY = 'sa2_legal_privacy_read'
    const checkbox = document.getElementById('agree-terms')
    const submit = document.getElementById(@json($submitButtonId))
    const hint = document.getElementById('agree-terms-hint')
    const tapHint = document.getElementById('agree-terms-tap-hint')
    const wrap = document.getElementById('agree-terms-check-wrap')
    const linkTerms = document.getElementById('agree-link-terms')
    const linkPrivacy = document.getElementById('agree-link-privacy')
    const statusTerms = document.getElementById('agree-status-terms')
    const statusPrivacy = document.getElementById('agree-status-privacy')
    if (!checkbox || !submit) return

    const msgPending = @json(__('チェックボックスは最初は押せません。「利用規約」「プライバシーポリシー」をそれぞれ開いて内容を確認すると、同意チェックが有効になります。'))
    const msgReady = @json($readyHint)
    const msgTap = @json(__('まだ規約の確認が終わっていません。上のボタンから両方を開いてください。'))
    const labelUnread = @json(__('未確認'))
    const labelRead = @json(__('確認済み'))

    const has = (key) => {
      try { return localStorage.getItem(key) === '1' } catch (_) { return false }
    }

    const setStatus = (el, ok) => {
      if (!el) return
      el.textContent = ok ? labelRead : labelUnread
      el.dataset.status = ok ? 'done' : 'pending'
    }

    const refresh = () => {
      const termsOk = has(TERMS_KEY)
      const privacyOk = has(PRIVACY_KEY)
      linkTerms?.classList.toggle('is-read', termsOk)
      linkPrivacy?.classList.toggle('is-read', privacyOk)
      setStatus(statusTerms, termsOk)
      setStatus(statusPrivacy, privacyOk)
      const ready = termsOk && privacyOk
      checkbox.disabled = !ready
      if (!ready) checkbox.checked = false
      submit.disabled = !ready || !checkbox.checked
      wrap?.classList.toggle('is-locked', !ready)
      wrap?.classList.toggle('is-ready', ready)
      if (hint) hint.textContent = ready ? msgReady : msgPending
      if (tapHint && ready) {
        tapHint.hidden = true
      }
    }

    wrap?.addEventListener('click', (event) => {
      if (!checkbox.disabled) return
      if (event.target.closest('a')) return
      event.preventDefault()
      if (tapHint) {
        tapHint.hidden = false
        tapHint.textContent = msgTap
      }
      hint?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
    })

    checkbox.addEventListener('change', refresh)
    window.addEventListener('storage', (event) => {
      if (event.key === TERMS_KEY || event.key === PRIVACY_KEY) refresh()
    })
    window.addEventListener('focus', refresh)
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') refresh()
    })
    setInterval(refresh, 1500)
    refresh()
  })()
</script>
