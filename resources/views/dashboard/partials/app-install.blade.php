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
    <p class="dash-app-install-lead">{{ __('スマホのホーム画面から、いつでも Sa2 Plus を開けます。') }}</p>
    <div class="dash-app-install-actions">
      @if($androidApkUrl)
        <a class="dash-app-install-btn" href="{{ $androidApkUrl }}" download>{{ __('Android：APK') }}</a>
      @else
        <span class="dash-app-install-btn is-disabled" title="{{ __('Android用APKは準備中です。公開後にここからダウンロードできます。') }}">{{ __('Android：準備中') }}</span>
      @endif
      <button type="button" class="dash-app-install-btn secondary" id="dash-app-install-ios">{{ __('iPhone：PWA') }}</button>
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
      <p class="dash-pwa-guide-lead">{{ __('Safari でこのサイトを開き、共有メニューからホーム画面に追加してください。') }}</p>
      <ol class="dash-pwa-guide-steps">
        <li>{{ __('Safari で sa2-plus.com を開く（Chrome アプリ内では追加できないことがあります）') }}</li>
        <li>{{ __('画面下（または上）の共有ボタンをタップ') }}</li>
        <li>{{ __('「ホーム画面に追加」→「追加」') }}</li>
      </ol>
      <p class="hint">{{ __('追加後はホーム画面のアイコンから、アプリのように全画面で使えます。') }}</p>
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
  const openModal = () => { if (modal) modal.hidden = false }
  const closeModal = () => { if (modal) modal.hidden = true }

  document.getElementById('dash-app-install-ios')?.addEventListener('click', openModal)
  modal?.querySelectorAll('[data-close-dash-pwa]').forEach((el) => {
    el.addEventListener('click', closeModal)
  })
})()
</script>
