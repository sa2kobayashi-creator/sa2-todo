@php
  use App\Support\FooterNav;
  $footerItems = $footerNavItems ?? [];
  $activeKey = $active ?? '';
  $navCount = count($footerItems);
  $expandedRows = FooterNav::footerExpandedRows($navCount);
  $canExpand = $navCount > FooterNav::FOOTER_PER_ROW;
@endphp
<nav
  class="mobile-bottom-nav"
  aria-label="{{ __('メインメニュー') }}"
  data-nav-count="{{ $navCount }}"
  data-expanded-rows="{{ $expandedRows }}"
  @if($canExpand) data-expandable="1" @endif
>
  @if($canExpand)
    <button
      type="button"
      class="mobile-nav-handle"
      aria-expanded="false"
      aria-controls="mobile-nav-grid"
      aria-label="{{ __('メニューを広げる') }}"
    >
      <span class="mobile-nav-handle-bar" aria-hidden="true"></span>
    </button>
  @endif
  <div class="mobile-nav-grid" id="mobile-nav-grid">
    @php $messagesUnreadCount = (int) ($messagesUnreadCount ?? 0); @endphp
    @forelse($footerItems as $item)
      @php
        $itemActive = $activeKey === ($item['activeKey'] ?? $item['key']);
        $isMessagesNav = ($item['activeKey'] ?? $item['key'] ?? '') === 'messages' || ($item['href'] ?? '') === '/messages';
      @endphp
      <a
        href="{{ $item['href'] }}"
        class="mobile-nav-item {{ $itemActive ? 'active' : '' }}{{ $isMessagesNav && $messagesUnreadCount > 0 ? ' has-nav-badge' : '' }}"
        @if($isMessagesNav && $messagesUnreadCount > 0)
          data-nav-unread="messages"
          aria-label="{{ $item['label'].' '.__('未読 :n', ['n' => $messagesUnreadCount]) }}"
        @endif
      >
        <span class="mobile-nav-icon" aria-hidden="true">{{ $item['icon'] }}</span>
        <span class="mobile-nav-label">{{ $item['label'] }}</span>
        @if($isMessagesNav && $messagesUnreadCount > 0)
          <span class="nav-unread-badge mobile-nav-unread" data-messages-unread-badge>{{ $messagesUnreadCount > 99 ? '99+' : $messagesUnreadCount }}</span>
        @endif
      </a>
    @empty
      <a href="/dashboard" class="mobile-nav-item {{ $activeKey === 'dashboard' ? 'active' : '' }}">
        <span class="mobile-nav-icon" aria-hidden="true">📅</span>
        <span class="mobile-nav-label">{{ __('ホーム') }}</span>
      </a>
      <a href="/todos" class="mobile-nav-item {{ $activeKey === 'todos' ? 'active' : '' }}">
        <span class="mobile-nav-icon" aria-hidden="true">✓</span>
        <span class="mobile-nav-label">{{ __('Todo') }}</span>
      </a>
      <a href="/notes" class="mobile-nav-item {{ $activeKey === 'notes' ? 'active' : '' }}">
        <span class="mobile-nav-icon" aria-hidden="true">📝</span>
        <span class="mobile-nav-label">{{ __('メモ') }}</span>
      </a>
      <a href="/photos" class="mobile-nav-item {{ $activeKey === 'photos' ? 'active' : '' }}">
        <span class="mobile-nav-icon" aria-hidden="true">🖼</span>
        <span class="mobile-nav-label">{{ __('Photos') }}</span>
      </a>
    @endforelse
  </div>
</nav>
<script>
  (function () {
    if (window.__sa2MobileNavInit) return
    window.__sa2MobileNavInit = true
    const nav = document.querySelector('.mobile-bottom-nav')
    if (!nav) return
    const expandable = nav.hasAttribute('data-expandable')
    const handle = nav.querySelector('.mobile-nav-handle')
    const expandedRows = Math.max(1, parseInt(nav.getAttribute('data-expanded-rows') || '1', 10) || 1)
    const labels = {
      open: @json(__('メニューを広げる')),
      close: @json(__('メニューをしまう')),
    }
    let expanded = false
    let startY = 0
    let tracking = false
    let swiped = false

    function setExpanded(next) {
      expanded = Boolean(next)
      document.documentElement.style.setProperty('--mobile-nav-visible-rows', String(expanded ? expandedRows : 1))
      nav.classList.toggle('is-expanded', expanded)
      if (handle) {
        handle.setAttribute('aria-expanded', expanded ? 'true' : 'false')
        handle.setAttribute('aria-label', expanded ? labels.close : labels.open)
      }
    }

    document.documentElement.style.setProperty('--mobile-nav-handle-height', expandable ? '14px' : '0px')
    setExpanded(false)

    if (!expandable) return

    handle?.addEventListener('click', function () {
      setExpanded(!expanded)
    })

    nav.addEventListener('touchstart', function (event) {
      const touch = event.touches[0]
      if (!touch) return
      startY = touch.clientY
      tracking = true
      swiped = false
    }, { passive: true })

    nav.addEventListener('touchmove', function (event) {
      if (!tracking) return
      const touch = event.touches[0]
      if (!touch) return
      if (Math.abs(touch.clientY - startY) > 12) swiped = true
    }, { passive: true })

    nav.addEventListener('touchend', function (event) {
      if (!tracking) return
      tracking = false
      const touch = event.changedTouches[0]
      if (!touch) return
      const dy = touch.clientY - startY
      if (dy < -36) setExpanded(true)
      else if (dy > 36) setExpanded(false)
    }, { passive: true })

    nav.addEventListener('click', function (event) {
      if (!swiped) return
      event.preventDefault()
      event.stopPropagation()
      swiped = false
    }, true)
  })()
</script>
