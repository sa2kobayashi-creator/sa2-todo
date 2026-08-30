@php
  use App\Support\AppInstall;
  $androidApkUrl = AppInstall::androidApkUrl();
@endphp
<details
  class="app-accordion dash-app-install"
  id="dash-app-install"
  data-accordion-key="dash-app-install"
  hidden
>
  <summary class="app-accordion-summary">
    <h2>{{ __('アプリをインストール') }}</h2>
    <span class="app-accordion-caret" aria-hidden="true">▾</span>
  </summary>
  <div class="app-accordion-body">
    <p class="dash-app-install-desc">{{ __('Sa2 Plus は、Todo・メモ・Photos・メッセージ・メール・マップなどをひとつにまとめた Web アプリです。スマホに入れておくと、ブラウザを開かずにホーム画面からすぐ使えます。') }}</p>
    <p class="dash-app-install-lead">{{ __('Android は APK（アプリ本体）と PWA（ホーム画面追加）のどちらでも利用できます。iPhone は PWA のみです。') }}</p>
    <div class="dash-app-install-actions">
      @if($androidApkUrl)
        <a class="dash-app-install-btn" id="dash-app-install-android-apk" href="{{ $androidApkUrl }}" download>{{ __('Android：APK') }}</a>
      @else
        <span class="dash-app-install-btn is-disabled" id="dash-app-install-android-apk" title="{{ __('Android用APKは準備中です。公開後にここからダウンロードできます。') }}">{{ __('Android：準備中') }}</span>
      @endif
      <button type="button" class="dash-app-install-btn secondary" id="dash-app-install-android-pwa" data-pwa-guide="android">{{ __('Android：PWA') }}</button>
      <button type="button" class="dash-app-install-btn secondary" id="dash-app-install-ios-pwa" data-pwa-guide="ios">{{ __('iPhone：PWA') }}</button>
    </div>
    <p class="hint dash-app-install-note">{{ __('PCではスマホ向けの見た目になりません。インストールは各端末で行ってください。') }}</p>
  </div>
</details>

<div class="modal modal-centered" id="dash-pwa-guide-modal" hidden>
  <div class="modal-backdrop" data-close-dash-pwa></div>
  <div class="modal-dialog" role="dialog" aria-labelledby="dash-pwa-guide-title">
    <div class="modal-header">
      <h2 id="dash-pwa-guide-title">{{ __('ホーム画面に追加') }}</h2>
      <button type="button" class="modal-close" data-close-dash-pwa aria-label="{{ __('閉じる') }}">×</button>
    </div>
    <div class="dash-pwa-guide-body">
      <p class="dash-pwa-guide-lead" id="dash-pwa-guide-lead"></p>
      <ol class="dash-pwa-guide-steps" id="dash-pwa-guide-steps"></ol>
      <p class="hint" id="dash-pwa-guide-note"></p>
      <p class="dash-pwa-guide-install-wrap" id="dash-pwa-guide-install-wrap" hidden>
        <button type="button" class="dash-app-install-btn" id="dash-pwa-guide-install-btn">{{ __('アプリをインストール') }}</button>
      </p>
    </div>
    <div class="modal-actions">
      <button type="button" class="button-link" data-close-dash-pwa>{{ __('閉じる') }}</button>
    </div>
  </div>
</div>

<script>
(() => {
  const root = document.getElementById('dash-app-install')
  if (!root) return

  const copy = {
    androidLead: @json(__('Android Chrome でこのサイトを開き、ホーム画面に追加（PWA）してください。')),
    iosLead: @json(__('Safari でこのサイトを開き、共有メニューからホーム画面に追加してください。')),
    androidSteps: [
      @json(__('Chrome で sa2-plus.com を開く')),
      @json(__('アドレスバー右のインストールアイコン、またはメニュー「アプリをインストール」を選ぶ')),
      @json(__('確認画面で追加する')),
    ],
    iosSteps: [
      @json(__('Safari で sa2-plus.com を開く（Chrome アプリ内では追加できないことがあります）')),
      @json(__('画面下（または上）の共有ボタンをタップ')),
      @json(__('「ホーム画面に追加」→「追加」')),
    ],
    androidNote: @json(__('追加後はホーム画面のアイコンから、アプリのように全画面で使えます。APK とは別の入れ方です。')),
    iosNote: @json(__('追加後はホーム画面のアイコンから、アプリのように全画面で使えます。')),
    already: @json(__('すでにホーム画面アプリとして開いています。')),
  }

  const isInstalled = () => {
    if (window.navigator.standalone === true) return true
    if (window.matchMedia('(display-mode: standalone)').matches) return true
    if (window.matchMedia('(display-mode: fullscreen)').matches) return true
    if (window.matchMedia('(display-mode: minimal-ui)').matches) return true
    if (document.referrer && document.referrer.indexOf('android-app://') === 0) return true
    return false
  }

  if (isInstalled()) {
    root.hidden = true
    root.remove()
    return
  }

  root.hidden = false

  const modal = document.getElementById('dash-pwa-guide-modal')
  const lead = document.getElementById('dash-pwa-guide-lead')
  const steps = document.getElementById('dash-pwa-guide-steps')
  const note = document.getElementById('dash-pwa-guide-note')
  const installWrap = document.getElementById('dash-pwa-guide-install-wrap')
  const installBtn = document.getElementById('dash-pwa-guide-install-btn')
  let deferredPrompt = null

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    deferredPrompt = event
    if (installWrap) installWrap.hidden = false
  })

  const fillGuide = (kind) => {
    const android = kind === 'android'
    if (lead) lead.textContent = android ? copy.androidLead : copy.iosLead
    if (steps) {
      const list = android ? copy.androidSteps : copy.iosSteps
      steps.innerHTML = list.map((step) => '<li>' + step + '</li>').join('')
    }
    if (note) note.textContent = android ? copy.androidNote : copy.iosNote
    if (installWrap) installWrap.hidden = !(android && deferredPrompt)
  }

  const openModal = (kind) => {
    fillGuide(kind || 'android')
    if (modal) modal.hidden = false
  }
  const closeModal = () => { if (modal) modal.hidden = true }

  document.getElementById('dash-app-install-android-pwa')?.addEventListener('click', () => openModal('android'))
  document.getElementById('dash-app-install-ios-pwa')?.addEventListener('click', () => openModal('ios'))
  modal?.querySelectorAll('[data-close-dash-pwa]').forEach((el) => {
    el.addEventListener('click', closeModal)
  })

  installBtn?.addEventListener('click', async () => {
    if (!deferredPrompt) return
    deferredPrompt.prompt()
    try { await deferredPrompt.userChoice } catch (_) {}
    deferredPrompt = null
    if (installWrap) installWrap.hidden = true
    closeModal()
  })
})()
</script>
