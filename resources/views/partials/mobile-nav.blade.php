<nav class="mobile-bottom-nav" aria-label="メインメニュー">
  <a href="/dashboard" class="mobile-nav-item {{ ($active ?? '') === 'dashboard' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">📅</span>
    <span class="mobile-nav-label">ホーム</span>
  </a>
  <a href="/todos" class="mobile-nav-item {{ ($active ?? '') === 'todos' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">✓</span>
    <span class="mobile-nav-label">Todo</span>
  </a>
  <a href="/notes" class="mobile-nav-item {{ ($active ?? '') === 'notes' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">📝</span>
    <span class="mobile-nav-label">メモ</span>
  </a>
  <a href="/transit" class="mobile-nav-item {{ ($active ?? '') === 'transit' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">🚌</span>
    <span class="mobile-nav-label">路線</span>
  </a>
  <a href="/map" class="mobile-nav-item {{ ($active ?? '') === 'map' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">🗺</span>
    <span class="mobile-nav-label">マップ</span>
  </a>
  <a href="/photos" class="mobile-nav-item {{ ($active ?? '') === 'photos' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">🖼</span>
    <span class="mobile-nav-label">Photos</span>
  </a>
  <a href="/finance" class="mobile-nav-item {{ ($active ?? '') === 'finance' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">💰</span>
    <span class="mobile-nav-label">入出金</span>
  </a>
  <a href="/settings" class="mobile-nav-item {{ ($active ?? '') === 'settings' ? 'active' : '' }}">
    <span class="mobile-nav-icon" aria-hidden="true">⚙</span>
    <span class="mobile-nav-label">設定</span>
  </a>
  @if(!empty($isAdmin))
    <a href="/admin/users" class="mobile-nav-item {{ ($active ?? '') === 'admin' ? 'active' : '' }}">
      <span class="mobile-nav-icon" aria-hidden="true">🛡</span>
      <span class="mobile-nav-label">管理</span>
    </a>
  @endif
</nav>
