@php
  $navSection = $settingsSection ?? $section ?? '';
  $navActive = $active ?? '';
@endphp
<nav class="settings-subnav" aria-label="{{ __('設定メニュー') }}">
  @if(!empty($canSettings))
    <a href="/settings?section=nav" @class(['active' => $navActive === 'settings' && $navSection === 'nav'])>{{ __('表示メニュー管理') }}</a>
    <a href="{{ \App\Support\SettingsNav::setupMenuHref($navSection) }}" @class(['active' => $navActive === 'settings' && \App\Support\SettingsNav::isSetupSection($navSection)])>{{ __('初期設定') }}</a>
    <a href="/settings?section=usage" @class(['active' => $navActive === 'settings' && $navSection === 'usage'])>{{ __('使用量') }}</a>
    <a href="/help" @class(['active' => $navActive === 'help'])>{{ __('ヘルプ') }}</a>
    <a href="/help/overview" @class(['active' => $navActive === 'help-overview'])>{{ __('このアプリの概要') }}</a>
    <a href="/help/guide" @class(['active' => $navActive === 'help-guide'])>{{ __('このアプリの使用方法') }}</a>
    <a href="/contact" @class(['active' => $navActive === 'contact'])>{{ __('問い合わせ') }}</a>
  @endif
  @if(!empty($canAdminUsers))
    <a href="/admin/users" @class(['active' => $navActive === 'admin'])>{{ __('ユーザー管理') }}</a>
    <a href="/admin/groups" @class(['active' => $navActive === 'admin-groups'])>{{ __('グループ管理') }}</a>
    @if(!empty($canManagePlatformOps))
      <a href="/admin/mail-requests" @class(['active' => $navActive === 'admin-mail'])>{{ __('メール申請') }}</a>
      <a href="/admin/storage-archive" @class(['active' => $navActive === 'admin-storage'])>{{ __('ストレージ監視') }}</a>
    @endif
  @endif
  @if(!empty($canManageTenants))
    <a href="/admin/tenants" @class(['active' => $navActive === 'admin-tenants'])>{{ __('テナント契約') }}</a>
  @endif
  @if(!empty($canSuperAdmin))
    <a href="/admin/sales/estimate" @class(['active' => $navActive === 'admin-sales'])>{{ __('見積（専用）') }}</a>
  @endif
</nav>
