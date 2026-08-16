@php
  $navSettingsSection = $settingsSection ?? '';
  // Web ヘッダーは headerNavItems（設定の Web 列）。スマホ下部は footerNavItems
  $headerNavItems = $headerNavItems ?? [];
@endphp
<header class="site-header">
  <div class="site-header-inner">
    <a href="/dashboard" class="site-logo">
      <img src="{{ asset('icons/app-icon.png') }}?v=9" alt="" class="site-logo-icon" width="36" height="36" />
      <span>{{ config('app.name', 'Sa2 Plus') }}</span>
    </a>
    <nav class="site-nav" aria-label="{{ __('メインメニュー') }}">
      @php $messagesUnreadCount = (int) ($messagesUnreadCount ?? 0); @endphp
      @forelse($headerNavItems as $navItem)
        @php
          $navKey = $navItem['activeKey'] ?? $navItem['key'] ?? '';
          $isMessagesNav = $navKey === 'messages' || ($navItem['href'] ?? '') === '/messages';
        @endphp
        <a
          href="{{ $navItem['href'] }}"
          class="{{ ($active ?? '') === $navKey ? 'active' : '' }}{{ $isMessagesNav && $messagesUnreadCount > 0 ? ' has-nav-badge' : '' }}"
          @if($isMessagesNav && $messagesUnreadCount > 0)
            data-nav-unread="messages"
            aria-label="{{ ($navItem['headerLabel'] ?? $navItem['label']).' '.__('未読 :n', ['n' => $messagesUnreadCount]) }}"
          @endif
        >
          {{ $navItem['headerLabel'] ?? $navItem['label'] }}
          @if($isMessagesNav && $messagesUnreadCount > 0)
            <span class="nav-unread-badge" data-messages-unread-badge>{{ $messagesUnreadCount > 99 ? '99+' : $messagesUnreadCount }}</span>
          @endif
        </a>
      @empty
        <a href="/dashboard" class="{{ ($active ?? '') === 'dashboard' ? 'active' : '' }}">{{ __('ダッシュボード') }}</a>
        <a href="/todos" class="{{ ($active ?? '') === 'todos' ? 'active' : '' }}">{{ __('Todo') }}</a>
        <a href="/notes" class="{{ ($active ?? '') === 'notes' ? 'active' : '' }}">{{ __('メモ') }}</a>
        <a href="/photos" class="{{ ($active ?? '') === 'photos' ? 'active' : '' }}">{{ __('Photos') }}</a>
      @endforelse
      @if(!empty($canSettings) || !empty($canAdminUsers))
        <div class="nav-dropdown {{ in_array($active ?? '', ['settings', 'admin', 'admin-groups']) ? 'is-active' : '' }}" id="settings-dropdown">
          <button type="button" class="nav-dropdown-toggle {{ in_array($active ?? '', ['settings', 'admin', 'admin-groups']) ? 'active' : '' }}" aria-haspopup="true" aria-expanded="false" id="settings-dropdown-toggle">
            {{ !empty($canSettings) ? __('設定') : __('管理') }}
            <span class="nav-dropdown-caret" aria-hidden="true">▾</span>
          </button>
          <div class="nav-dropdown-menu" role="menu">
            @if(!empty($canSettings))
              <a href="/settings?section=nav" class="{{ ($navSettingsSection ?? '') === 'nav' ? 'active' : '' }}" role="menuitem">{{ __('表示メニュー管理') }}</a>
              <a href="/settings?section=holidays" class="{{ ($navSettingsSection ?? '') === 'holidays' ? 'active' : '' }}" role="menuitem">{{ __('休日設定') }}</a>
              <a href="/settings?section=ai" class="{{ ($navSettingsSection ?? '') === 'ai' ? 'active' : '' }}" role="menuitem">{{ __('AI設定') }}</a>
              <a href="/settings?section=storage" class="{{ ($navSettingsSection ?? '') === 'storage' ? 'active' : '' }}" role="menuitem">{{ __('ストレージ設定') }}</a>
              <a href="/settings?section=enhance" class="{{ ($navSettingsSection ?? '') === 'enhance' ? 'active' : '' }}" role="menuitem">{{ __('API設定') }}</a>
              <a href="/settings?section=integration" class="{{ ($navSettingsSection ?? '') === 'integration' ? 'active' : '' }}" role="menuitem">{{ __('外部連携') }}</a>
              <a href="/settings?section=notifications" class="{{ ($navSettingsSection ?? '') === 'notifications' ? 'active' : '' }}" role="menuitem">{{ __('通知設定') }}</a>
              <a href="/settings?section=usage" class="{{ ($navSettingsSection ?? '') === 'usage' ? 'active' : '' }}" role="menuitem">{{ __('使用量') }}</a>
            @endif
            @if(!empty($canAdminUsers))
              <a href="/admin/users" class="{{ ($active ?? '') === 'admin' ? 'active' : '' }}" role="menuitem">{{ __('ユーザー管理') }}</a>
              <a href="/admin/groups" class="{{ ($active ?? '') === 'admin-groups' ? 'active' : '' }}" role="menuitem">{{ __('グループ管理') }}</a>
              <a href="/admin/mail-requests" class="{{ ($active ?? '') === 'admin-mail' ? 'active' : '' }}" role="menuitem">{{ __('メール申請') }}</a>
            @endif
          </div>
        </div>
      @endif
    </nav>

    <div class="header-actions">
      @if(!empty($currentUser))
        @php
          $currentContext = ($appContext ?? 'personal') === 'work' ? 'work' : 'personal';
          $nextContext = $currentContext === 'personal' ? 'work' : 'personal';
          $contextLabel = $currentContext === 'personal' ? __('個人') : __('仕事');
          $nextContextLabel = $nextContext === 'personal' ? __('個人') : __('仕事');
          $moreNav = $moreNavItems ?? [];
        @endphp
        <form method="post" action="{{ route('app-context.update') }}" class="lang-switch-form lang-toggle context-toggle">
          @csrf
          <input type="hidden" name="context" value="{{ $nextContext }}" />
          <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}" />
          <button
            type="submit"
            class="lang-switch-btn lang-toggle-btn context-toggle-btn is-active"
            title="{{ __('表示モードを:modeに切替', ['mode' => $nextContextLabel]) }}"
            aria-label="{{ __('表示モードを:modeに切替', ['mode' => $nextContextLabel]) }}"
          >{{ $contextLabel }}</button>
        </form>
      @endif
      @php
        $currentLocale = ($appLocale ?? app()->getLocale()) === 'en' ? 'en' : 'ja';
        $nextLocale = $currentLocale === 'ja' ? 'en' : 'ja';
        $localeLabel = $currentLocale === 'ja' ? 'JP' : 'EN';
        $nextLocaleLabel = $nextLocale === 'ja' ? 'JP' : 'EN';
      @endphp
      <form method="post" action="{{ route('locale.update') }}" class="lang-switch-form lang-toggle">
        @csrf
        <input type="hidden" name="locale" value="{{ $nextLocale }}" />
        <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}" />
        <button
          type="submit"
          class="lang-switch-btn lang-toggle-btn is-active"
          title="{{ __('言語を:langに切替', ['lang' => $nextLocaleLabel]) }}"
          aria-label="{{ __('言語を:langに切替', ['lang' => $nextLocaleLabel]) }}"
        >{{ $localeLabel }}</button>
      </form>

      @if(!empty($currentUser))
        @php
          $userDisplayName = (string) ($currentUser['displayName'] ?? '');
          $nameParts = preg_split('/\s+/u', trim($userDisplayName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
          if (count($nameParts) >= 2) {
            $userInitials = mb_substr($nameParts[0], 0, 1).mb_substr($nameParts[array_key_last($nameParts)], 0, 1);
          } elseif ($nameParts !== []) {
            $first = $nameParts[0];
            $userInitials = mb_substr($first, 0, preg_match('/^[A-Za-z]/u', $first) ? 2 : 1);
          } else {
            $userInitials = '?';
          }
        @endphp
        <div class="site-user-menu">
          <div class="nav-dropdown" id="user-dropdown">
            <button
              type="button"
              class="user-menu-toggle"
              id="user-menu-toggle"
              aria-haspopup="true"
              aria-expanded="false"
              aria-label="{{ $userDisplayName }}"
              title="{{ $userDisplayName }}"
            >
              <span class="user-menu-avatar" aria-hidden="true">{{ $userInitials }}</span>
            </button>
            <div class="nav-dropdown-menu user-dropdown-menu" role="menu">
              <div class="user-dropdown-meta">
                <strong>{{ $userDisplayName }}</strong>
                <span class="role-badge {{ $currentUser['role'] }}">{{ $currentUser['roleLabel'] }}</span>
              </div>
              <a href="/mypage" role="menuitem">{{ __('マイページ') }}</a>
              <a href="/help" role="menuitem">{{ __('ヘルプ') }}</a>
              <a href="/about" role="menuitem">{{ __('Sa2 Plus について') }}</a>
              @if(!empty($canGroups))
                <a href="/groups" role="menuitem">{{ __('グループ') }}</a>
              @endif
              <form method="post" action="/logout" class="logout-form">
                @csrf
                <button type="submit" role="menuitem">{{ __('ログアウト') }}</button>
              </form>
            </div>
          </div>
        </div>
        @if(count($moreNav) > 0 || !empty($canSettings) || !empty($canAdminUsers))
          <div class="nav-dropdown header-more-menu mobile-only-flex" id="more-nav-dropdown">
            <button
              type="button"
              class="header-more-toggle"
              id="more-nav-toggle"
              aria-haspopup="true"
              aria-expanded="false"
              aria-label="{{ __('その他のメニュー') }}"
              title="{{ __('その他のメニュー') }}"
            >
              <span class="header-more-glyph" aria-hidden="true">⋮</span>
            </button>
            <div class="nav-dropdown-menu header-more-dropdown" role="menu">
              @foreach($moreNav as $moreItem)
                @php
                  $moreKey = $moreItem['activeKey'] ?? $moreItem['key'] ?? '';
                  $isMessagesMore = $moreKey === 'messages' || ($moreItem['href'] ?? '') === '/messages';
                @endphp
                <a
                  href="{{ $moreItem['href'] }}"
                  class="{{ ($active ?? '') === $moreKey ? 'active' : '' }}"
                  role="menuitem"
                  @if($isMessagesMore && $messagesUnreadCount > 0)
                    data-nav-unread="messages"
                    aria-label="{{ $moreItem['label'].' '.__('未読 :n', ['n' => $messagesUnreadCount]) }}"
                  @endif
                >
                  <span class="header-more-icon" aria-hidden="true">{{ $moreItem['icon'] }}</span>
                  {{ $moreItem['label'] }}
                  @if($isMessagesMore && $messagesUnreadCount > 0)
                    <span class="nav-unread-badge" data-messages-unread-badge>{{ $messagesUnreadCount > 99 ? '99+' : $messagesUnreadCount }}</span>
                  @endif
                </a>
              @endforeach
              @if(!empty($canSettings) || !empty($canAdminUsers))
                @if(count($moreNav) > 0)
                  <div class="user-dropdown-divider" role="separator"></div>
                @endif
                <div class="header-more-submenu" id="more-settings-submenu">
                  <button
                    type="button"
                    class="header-more-submenu-toggle{{ in_array($active ?? '', ['settings', 'admin', 'admin-groups'], true) ? ' active' : '' }}"
                    id="more-settings-toggle"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="more-settings-panel"
                  >
                    <span class="header-more-icon" aria-hidden="true">⚙</span>
                    <span class="header-more-submenu-label">{{ !empty($canSettings) ? __('設定') : __('管理') }}</span>
                    <span class="nav-dropdown-caret" aria-hidden="true">▾</span>
                  </button>
                  <div
                    class="header-more-submenu-panel"
                    id="more-settings-panel"
                    role="menu"
                    hidden
                  >
                    @if(!empty($canSettings))
                      <a href="/settings?section=nav" class="{{ ($navSettingsSection ?? '') === 'nav' ? 'active' : '' }}" role="menuitem">{{ __('表示メニュー管理') }}</a>
                      <a href="/settings?section=holidays" class="{{ ($navSettingsSection ?? '') === 'holidays' ? 'active' : '' }}" role="menuitem">{{ __('休日設定') }}</a>
                      <a href="/settings?section=ai" class="{{ ($navSettingsSection ?? '') === 'ai' ? 'active' : '' }}" role="menuitem">{{ __('AI設定') }}</a>
                      <a href="/settings?section=storage" class="{{ ($navSettingsSection ?? '') === 'storage' ? 'active' : '' }}" role="menuitem">{{ __('ストレージ設定') }}</a>
                      <a href="/settings?section=enhance" class="{{ ($navSettingsSection ?? '') === 'enhance' ? 'active' : '' }}" role="menuitem">{{ __('API設定') }}</a>
                      <a href="/settings?section=integration" class="{{ ($navSettingsSection ?? '') === 'integration' ? 'active' : '' }}" role="menuitem">{{ __('外部連携') }}</a>
                      <a href="/settings?section=notifications" class="{{ ($navSettingsSection ?? '') === 'notifications' ? 'active' : '' }}" role="menuitem">{{ __('通知設定') }}</a>
                      <a href="/settings?section=usage" class="{{ ($navSettingsSection ?? '') === 'usage' ? 'active' : '' }}" role="menuitem">{{ __('使用量') }}</a>
                    @endif
                    @if(!empty($canAdminUsers))
                      <a href="/admin/users" class="{{ ($active ?? '') === 'admin' ? 'active' : '' }}" role="menuitem">{{ __('ユーザー管理') }}</a>
                      <a href="/admin/groups" class="{{ ($active ?? '') === 'admin-groups' ? 'active' : '' }}" role="menuitem">{{ __('グループ管理') }}</a>
                      <a href="/admin/mail-requests" class="{{ ($active ?? '') === 'admin-mail' ? 'active' : '' }}" role="menuitem">{{ __('メール申請') }}</a>
                    @endif
                  </div>
                </div>
              @endif
            </div>
          </div>
        @endif
      @endif
    </div>
  </div>
</header>
<script>
  (function () {
    const dropdown = document.getElementById('settings-dropdown')
    const toggle = document.getElementById('settings-dropdown-toggle')
    const moreDropdown = document.getElementById('more-nav-dropdown')
    const moreToggle = document.getElementById('more-nav-toggle')
    const userDropdown = document.getElementById('user-dropdown')
    const userToggle = document.getElementById('user-menu-toggle')
    const moreSettings = document.getElementById('more-settings-submenu')
    const moreSettingsToggle = document.getElementById('more-settings-toggle')
    const moreSettingsPanel = document.getElementById('more-settings-panel')

    function setMoreSettingsOpen(open) {
      if (!moreSettings || !moreSettingsToggle || !moreSettingsPanel) return
      moreSettings.classList.toggle('is-open', open)
      moreSettingsToggle.setAttribute('aria-expanded', open ? 'true' : 'false')
      moreSettingsPanel.hidden = !open
    }

    function closeAll(except) {
      if (except !== 'settings') {
        dropdown?.classList.remove('is-open')
        toggle?.setAttribute('aria-expanded', 'false')
      }
      if (except !== 'more') {
        moreDropdown?.classList.remove('is-open')
        moreToggle?.setAttribute('aria-expanded', 'false')
        setMoreSettingsOpen(false)
      }
      if (except !== 'user') {
        userDropdown?.classList.remove('is-open')
        userToggle?.setAttribute('aria-expanded', 'false')
      }
    }

    if (dropdown && toggle) {
      toggle.addEventListener('click', (e) => {
        e.preventDefault()
        e.stopPropagation()
        const open = dropdown.classList.toggle('is-open')
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false')
        if (open) closeAll('settings')
      })
    }

    if (moreDropdown && moreToggle) {
      moreToggle.addEventListener('click', (e) => {
        e.preventDefault()
        e.stopPropagation()
        const open = moreDropdown.classList.toggle('is-open')
        moreToggle.setAttribute('aria-expanded', open ? 'true' : 'false')
        // ⋮を開く／閉じるたびに設定サブは必ず閉じる（押したときだけ展開）
        setMoreSettingsOpen(false)
        if (open) closeAll('more')
      })
    }

    if (moreSettingsToggle && moreSettings) {
      moreSettingsToggle.addEventListener('click', (e) => {
        e.preventDefault()
        e.stopPropagation()
        const open = !moreSettings.classList.contains('is-open')
        setMoreSettingsOpen(open)
      })
      // サブメニュー内クリックで⋮全体が閉じないようにする
      moreSettings.addEventListener('click', (e) => e.stopPropagation())
    }

    if (userDropdown && userToggle) {
      userToggle.addEventListener('click', (e) => {
        e.preventDefault()
        e.stopPropagation()
        const open = userDropdown.classList.toggle('is-open')
        userToggle.setAttribute('aria-expanded', open ? 'true' : 'false')
        if (open) closeAll('user')
      })
    }

    document.addEventListener('click', () => closeAll())
  })()
</script>
@include('partials.mobile-nav', ['active' => $active ?? ''])
@if(!empty($currentUser) && !empty($canMessages))
  @include('partials.incoming-call')
@endif
