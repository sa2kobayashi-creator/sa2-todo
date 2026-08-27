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
  aria-expanded="false"
  data-nav-count="{{ $navCount }}"
  data-expanded-rows="{{ $expandedRows }}"
  data-reorder-url="{{ url('/mypage/footer-nav') }}"
  @if($canExpand) data-expandable="1" @endif
>
  <div class="mobile-nav-chrome">
    <button type="button" class="mobile-nav-done" hidden>{{ __('完了') }}</button>
  </div>
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
        data-nav-key="{{ $item['key'] ?? $item['activeKey'] ?? '' }}"
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
      <a href="/dashboard" class="mobile-nav-item {{ $activeKey === 'dashboard' ? 'active' : '' }}" data-nav-key="dashboard">
        <span class="mobile-nav-icon" aria-hidden="true">📅</span>
        <span class="mobile-nav-label">{{ __('ホーム') }}</span>
      </a>
      <a href="/todos" class="mobile-nav-item {{ $activeKey === 'todos' ? 'active' : '' }}" data-nav-key="todos">
        <span class="mobile-nav-icon" aria-hidden="true">✓</span>
        <span class="mobile-nav-label">{{ __('Todo') }}</span>
      </a>
      <a href="/notes" class="mobile-nav-item {{ $activeKey === 'notes' ? 'active' : '' }}" data-nav-key="notes">
        <span class="mobile-nav-icon" aria-hidden="true">📝</span>
        <span class="mobile-nav-label">{{ __('メモ') }}</span>
      </a>
      <a href="/photos" class="mobile-nav-item {{ $activeKey === 'photos' ? 'active' : '' }}" data-nav-key="photos">
        <span class="mobile-nav-icon" aria-hidden="true">🖼</span>
        <span class="mobile-nav-label">{{ __('Photos') }}</span>
      </a>
    @endforelse
  </div>
</nav>
<script src="{{ asset('mobile-nav.js') }}?v={{ @filemtime(public_path('mobile-nav.js')) ?: time() }}"></script>
