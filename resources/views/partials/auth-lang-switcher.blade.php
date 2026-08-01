<div class="lang-switcher auth-lang-switcher" role="group" aria-label="{{ __('言語') }}">
  <form method="post" action="{{ route('locale.update') }}" class="lang-switch-form">
    @csrf
    <input type="hidden" name="locale" value="ja" />
    <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}" />
    <button type="submit" class="lang-switch-btn{{ ($appLocale ?? app()->getLocale()) === 'ja' ? ' is-active' : '' }}">日本語</button>
  </form>
  <form method="post" action="{{ route('locale.update') }}" class="lang-switch-form">
    @csrf
    <input type="hidden" name="locale" value="en" />
    <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}" />
    <button type="submit" class="lang-switch-btn{{ ($appLocale ?? app()->getLocale()) === 'en' ? ' is-active' : '' }}">English</button>
  </form>
</div>
