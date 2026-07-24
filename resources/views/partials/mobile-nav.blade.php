<nav class="mobile-bottom-nav" aria-label="{{ __('メインメニュー') }}">
  <a href="/dashboard" class="mobile-nav-item {{ ($active ?? '') === 'dashboard' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">📅</span>
    <span class="mobile-nav-label">{{ __('ホーム') }}</span>
  </a>
  <a href="/todos" class="mobile-nav-item {{ ($active ?? '') === 'todos' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">✓</span>
    <span class="mobile-nav-label">{{ __('Todo') }}</span>
  </a>
  <a href="/notes" class="mobile-nav-item {{ ($active ?? '') === 'notes' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">📝</span>
    <span class="mobile-nav-label">{{ __('メモ') }}</span>
  </a>
  <a href="/photos" class="mobile-nav-item {{ ($active ?? '') === 'photos' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">🖼</span>
    <span class="mobile-nav-label">{{ __('Photos') }}</span>
  </a>
  @if(!empty($canFinance))
    <a href="/finance" class="mobile-nav-item {{ ($active ?? '') === 'finance' ? 'active' : '' }}">
      <span class="mobile-nav-icon" aria-hidden="true">💰</span>
      <span class="mobile-nav-label">{{ __('入出金') }}</span>
    </a>
  @endif
  @if(!empty($canTransit))
    <a href="/transit" class="mobile-nav-item {{ ($active ?? '') === 'transit' ? 'active' : '' }}">
      <span class="mobile-nav-icon" aria-hidden="true">🚌</span>
      <span class="mobile-nav-label">{{ __('路線') }}</span>
    </a>
  @endif
  @if(!empty($canMap))
    <a href="/map" class="mobile-nav-item {{ ($active ?? '') === 'map' ? 'active' : '' }}">
      <span class="mobile-nav-icon" aria-hidden="true">🗺</span>
      <span class="mobile-nav-label">{{ __('マップ') }}</span>
    </a>
  @endif
  @if(!empty($canMusic))
    <a href="/music" class="mobile-nav-item {{ ($active ?? '') === 'music' ? 'active' : '' }}">
      <span class="mobile-nav-icon" aria-hidden="true">♪</span>
      <span class="mobile-nav-label">{{ __('音楽') }}</span>
    </a>
  @endif
  @if(!empty($canVideo))
    <a href="/video" class="mobile-nav-item {{ ($active ?? '') === 'video' ? 'active' : '' }}">
      <span class="mobile-nav-icon" aria-hidden="true">▶</span>
      <span class="mobile-nav-label">{{ __('動画') }}</span>
    </a>
  @endif
  @if(!empty($canSettings))
    <a href="/settings" class="mobile-nav-item {{ ($active ?? '') === 'settings' ? 'active' : '' }}">
      <span class="mobile-nav-icon" aria-hidden="true">⚙</span>
      <span class="mobile-nav-label">{{ __('設定') }}</span>
    </a>
  @endif
  @if(!empty($canAdminUsers))
    <a href="/admin/users" class="mobile-nav-item {{ ($active ?? '') === 'admin' ? 'active' : '' }}">
      <span class="mobile-nav-icon" aria-hidden="true">🛡</span>
      <span class="mobile-nav-label">{{ __('管理') }}</span>
    </a>
  @endif
</nav>
