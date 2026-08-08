@php
  $footerItems = $footerNavItems ?? [];
  $activeKey = $active ?? '';
@endphp
<nav class="mobile-bottom-nav" aria-label="{{ __('メインメニュー') }}">
  @forelse($footerItems as $item)
    @php
      $itemActive = $activeKey === ($item['activeKey'] ?? $item['key']);
    @endphp
    <a href="{{ $item['href'] }}" class="mobile-nav-item {{ $itemActive ? 'active' : '' }}">
      <span class="mobile-nav-icon" aria-hidden="true">{{ $item['icon'] }}</span>
      <span class="mobile-nav-label">{{ $item['label'] }}</span>
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
</nav>
