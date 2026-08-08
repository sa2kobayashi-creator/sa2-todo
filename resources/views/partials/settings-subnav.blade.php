@php
  $navSection = $settingsSection ?? $section ?? '';
  $navActive = $active ?? '';
@endphp
<nav class="settings-subnav" aria-label="{{ __('設定メニュー') }}">
  @if(!empty($canSettings))
    <a href="/settings?section=nav" @class(['active' => $navActive === 'settings' && $navSection === 'nav'])>{{ __('表示メニュー管理') }}</a>
    <a href="/settings?section=holidays" @class(['active' => $navActive === 'settings' && $navSection === 'holidays'])>{{ __('休日設定') }}</a>
    <a href="/settings?section=ai&tab=translation" @class(['active' => $navActive === 'settings' && $navSection === 'ai'])>{{ __('AI設定') }}</a>
    <a href="/settings?section=storage" @class(['active' => $navActive === 'settings' && $navSection === 'storage'])>{{ __('ストレージ設定') }}</a>
    <a href="/settings?section=enhance" @class(['active' => $navActive === 'settings' && $navSection === 'enhance'])>{{ __('API設定') }}</a>
    <a href="/settings?section=integration" @class(['active' => $navActive === 'settings' && $navSection === 'integration'])>{{ __('外部連携') }}</a>
    <a href="/settings?section=notifications" @class(['active' => $navActive === 'settings' && $navSection === 'notifications'])>{{ __('通知設定') }}</a>
  @endif
  @if(!empty($canAdminUsers))
    <a href="/admin/users" @class(['active' => $navActive === 'admin'])>{{ __('ユーザー管理') }}</a>
    <a href="/admin/groups" @class(['active' => $navActive === 'admin-groups'])>{{ __('グループ管理') }}</a>
  @endif
</nav>
