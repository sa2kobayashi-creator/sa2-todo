@php
  $setupSection = $settingsSection ?? $section ?? 'holidays';
@endphp
<nav class="settings-setup-tabs" role="tablist" aria-label="{{ __('初期設定') }}">
  @foreach(\App\Support\SettingsNav::setupTabs(auth()->user()) as $setupKey => $setupLabel)
    <a
      href="{{ \App\Support\SettingsNav::tabHref($setupKey) }}"
      role="tab"
      aria-selected="{{ $setupSection === $setupKey ? 'true' : 'false' }}"
      @class(['is-active' => $setupSection === $setupKey])
    >{{ __($setupLabel) }}</a>
  @endforeach
</nav>
