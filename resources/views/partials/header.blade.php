@php $navSettingsSection = $settingsSection ?? ''; @endphp
<header class="site-header">
  <div class="site-header-inner">
    <a href="/dashboard" class="site-logo">Sa2 ToDo</a>
    <nav class="site-nav">
      <a href="/dashboard" class="{{ ($active ?? '') === 'dashboard' ? 'active' : '' }}">ダッシュボード</a>
      <a href="/todos" class="{{ ($active ?? '') === 'todos' ? 'active' : '' }}">Todo</a>
      <a href="/notes" class="{{ ($active ?? '') === 'notes' ? 'active' : '' }}">メモ</a>
      <a href="/finance" class="{{ ($active ?? '') === 'finance' ? 'active' : '' }}">入出金経費</a>
      <a href="/transit" class="{{ ($active ?? '') === 'transit' ? 'active' : '' }}">路線検索</a>
      <a href="/map" class="{{ ($active ?? '') === 'map' ? 'active' : '' }}">マップ</a>
      <div class="nav-dropdown {{ in_array($active ?? '', ['settings', 'admin']) ? 'is-active' : '' }}" id="settings-dropdown">
        <button type="button" class="nav-dropdown-toggle {{ in_array($active ?? '', ['settings', 'admin']) ? 'active' : '' }}" aria-haspopup="true" aria-expanded="false" id="settings-dropdown-toggle">
          設定
          <span class="nav-dropdown-caret" aria-hidden="true">▾</span>
        </button>
        <div class="nav-dropdown-menu" role="menu">
          <a href="/settings?section=holidays" class="{{ ($navSettingsSection ?? '') === 'holidays' ? 'active' : '' }}" role="menuitem">休日設定</a>
          <a href="/settings?section=integration" class="{{ ($navSettingsSection ?? '') === 'integration' ? 'active' : '' }}" role="menuitem">LINE連携</a>
          <a href="/settings?section=notifications" class="{{ ($navSettingsSection ?? '') === 'notifications' ? 'active' : '' }}" role="menuitem">通知設定</a>
          @if(!empty($isAdmin))
            <a href="/admin/users" class="{{ ($active ?? '') === 'admin' ? 'active' : '' }}" role="menuitem">ユーザー管理</a>
          @endif
        </div>
      </div>
    </nav>
    @if(!empty($currentUser))
      <div class="site-user-menu">
        <div class="nav-dropdown" id="user-dropdown">
          <button type="button" class="user-menu-toggle" id="user-menu-toggle" aria-haspopup="true" aria-expanded="false">
            <span class="user-menu-name">{{ $currentUser['displayName'] }}</span>
            <span class="nav-dropdown-caret" aria-hidden="true">▾</span>
          </button>
          <div class="nav-dropdown-menu user-dropdown-menu" role="menu">
            <div class="user-dropdown-meta">
              <strong>{{ $currentUser['displayName'] }}</strong>
              <span class="role-badge {{ $currentUser['role'] }}">{{ $currentUser['roleLabel'] }}</span>
            </div>
            <a href="/mypage" role="menuitem">マイページ</a>
            @if(!empty($isAdmin))
              <a href="/admin/users" role="menuitem">ユーザー管理</a>
            @endif
            <form method="post" action="/logout" class="logout-form">
              @csrf
              <button type="submit" role="menuitem">ログアウト</button>
            </form>
          </div>
        </div>
      </div>
    @endif
  </div>
</header>
<script>
  (function () {
    const dropdown = document.getElementById('settings-dropdown')
    const toggle = document.getElementById('settings-dropdown-toggle')
    if (dropdown && toggle) {
      toggle.addEventListener('click', (e) => {
        e.stopPropagation()
        const open = dropdown.classList.toggle('is-open')
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false')
      })
    }

    const userDropdown = document.getElementById('user-dropdown')
    const userToggle = document.getElementById('user-menu-toggle')
    if (userDropdown && userToggle) {
      userToggle.addEventListener('click', (e) => {
        e.stopPropagation()
        const open = userDropdown.classList.toggle('is-open')
        userToggle.setAttribute('aria-expanded', open ? 'true' : 'false')
      })
    }

    document.addEventListener('click', () => {
      dropdown?.classList.remove('is-open')
      toggle?.setAttribute('aria-expanded', 'false')
      userDropdown?.classList.remove('is-open')
      userToggle?.setAttribute('aria-expanded', 'false')
    })
  })()
</script>
@include('partials.mobile-nav', ['active' => $active ?? ''])
