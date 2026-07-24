@php $navSettingsSection = $settingsSection ?? ''; @endphp
<header class="site-header">
  <div class="site-header-inner">
    <a href="/dashboard" class="site-logo">
      <img src="{{ asset('icons/app-icon.png') }}?v=8" alt="" class="site-logo-icon" width="36" height="36" />
      <span>{{ config('app.name', 'Sa2 Studio') }}</span>
    </a>
    <nav class="site-nav">
      <a href="/dashboard" class="{{ ($active ?? '') === 'dashboard' ? 'active' : '' }}">{{ __('ダッシュボード') }}</a>
      <a href="/todos" class="{{ ($active ?? '') === 'todos' ? 'active' : '' }}">{{ __('Todo') }}</a>
      <a href="/notes" class="{{ ($active ?? '') === 'notes' ? 'active' : '' }}">{{ __('メモ') }}</a>
      <a href="/photos" class="{{ ($active ?? '') === 'photos' ? 'active' : '' }}">{{ __('Photos') }}</a>
      @if(!empty($canFinance))
        <a href="/finance" class="{{ ($active ?? '') === 'finance' ? 'active' : '' }}">{{ __('入出金経費') }}</a>
      @endif
      @if(!empty($canTransit))
        <a href="/transit" class="{{ ($active ?? '') === 'transit' ? 'active' : '' }}">{{ __('路線検索') }}</a>
      @endif
      @if(!empty($canMap))
        <a href="/map" class="{{ ($active ?? '') === 'map' ? 'active' : '' }}">{{ __('マップ') }}</a>
      @endif
      @if(!empty($canMusic))
        <a href="/music" class="{{ ($active ?? '') === 'music' ? 'active' : '' }}">{{ __('音楽') }}</a>
      @endif
      @if(!empty($canVideo))
        <a href="/video" class="{{ ($active ?? '') === 'video' ? 'active' : '' }}">{{ __('動画') }}</a>
      @endif
      @if(!empty($canSettings) || !empty($canAdminUsers))
        <div class="nav-dropdown {{ in_array($active ?? '', ['settings', 'admin', 'admin-groups']) ? 'is-active' : '' }}" id="settings-dropdown">
          <button type="button" class="nav-dropdown-toggle {{ in_array($active ?? '', ['settings', 'admin', 'admin-groups']) ? 'active' : '' }}" aria-haspopup="true" aria-expanded="false" id="settings-dropdown-toggle">
            {{ !empty($canSettings) ? __('設定') : __('管理') }}
            <span class="nav-dropdown-caret" aria-hidden="true">▾</span>
          </button>
          <div class="nav-dropdown-menu" role="menu">
            @if(!empty($canSettings))
              <a href="/settings?section=holidays" class="{{ ($navSettingsSection ?? '') === 'holidays' ? 'active' : '' }}" role="menuitem">{{ __('休日設定') }}</a>
              <a href="/settings?section=ai" class="{{ ($navSettingsSection ?? '') === 'ai' ? 'active' : '' }}" role="menuitem">{{ __('AI設定') }}</a>
              <a href="/settings?section=storage" class="{{ ($navSettingsSection ?? '') === 'storage' ? 'active' : '' }}" role="menuitem">{{ __('ストレージ設定') }}</a>
              <a href="/settings?section=integration" class="{{ ($navSettingsSection ?? '') === 'integration' ? 'active' : '' }}" role="menuitem">{{ __('LINE連携') }}</a>
              <a href="/settings?section=notifications" class="{{ ($navSettingsSection ?? '') === 'notifications' ? 'active' : '' }}" role="menuitem">{{ __('通知設定') }}</a>
            @endif
            @if(!empty($canAdminUsers))
              <a href="/admin/users" class="{{ ($active ?? '') === 'admin' ? 'active' : '' }}" role="menuitem">{{ __('ユーザー管理') }}</a>
              <a href="/admin/groups" class="{{ ($active ?? '') === 'admin-groups' ? 'active' : '' }}" role="menuitem">{{ __('グループ管理') }}</a>
            @endif
          </div>
        </div>
      @endif
      <div class="lang-switcher" role="group" aria-label="{{ __('言語') }}">
        <form method="post" action="{{ route('locale.update') }}" class="lang-switch-form">
          @csrf
          <input type="hidden" name="locale" value="ja" />
          <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}" />
          <button type="submit" class="lang-switch-btn{{ ($appLocale ?? app()->getLocale()) === 'ja' ? ' is-active' : '' }}" aria-pressed="{{ ($appLocale ?? app()->getLocale()) === 'ja' ? 'true' : 'false' }}">{{ __('日本語') }}</button>
        </form>
        <form method="post" action="{{ route('locale.update') }}" class="lang-switch-form">
          @csrf
          <input type="hidden" name="locale" value="en" />
          <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}" />
          <button type="submit" class="lang-switch-btn{{ ($appLocale ?? app()->getLocale()) === 'en' ? ' is-active' : '' }}" aria-pressed="{{ ($appLocale ?? app()->getLocale()) === 'en' ? 'true' : 'false' }}">English</button>
        </form>
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
            <a href="/mypage" role="menuitem">{{ __('マイページ') }}</a>
            <a href="/groups" role="menuitem">{{ __('グループ') }}</a>
            @if(!empty($canAdminUsers))
              <a href="/admin/users" role="menuitem">{{ __('ユーザー管理') }}</a>
              <a href="/admin/groups" role="menuitem">{{ __('グループ管理') }}</a>
            @endif
            <form method="post" action="/logout" class="logout-form">
              @csrf
              <button type="submit" role="menuitem">{{ __('ログアウト') }}</button>
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
