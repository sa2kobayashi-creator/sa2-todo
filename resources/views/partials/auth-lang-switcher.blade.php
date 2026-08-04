@php
  $currentLocale = ($appLocale ?? app()->getLocale()) === 'en' ? 'en' : 'ja';
  $nextLocale = $currentLocale === 'ja' ? 'en' : 'ja';
  $localeLabel = $currentLocale === 'ja' ? 'JP' : 'EN';
  $nextLocaleLabel = $nextLocale === 'ja' ? 'JP' : 'EN';
@endphp
<form method="post" action="{{ route('locale.update') }}" class="lang-switch-form lang-toggle auth-lang-switcher">
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
