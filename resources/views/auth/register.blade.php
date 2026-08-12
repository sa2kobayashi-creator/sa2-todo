<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('会員登録') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body class="auth-body">
    @include('partials.auth-lang-switcher')
    <main class="auth-shell">
      <a href="/" class="auth-brand">
        <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="40" height="40" />
        <span>{{ config('app.name') }}</span>
      </a>
      <div class="auth-card panel">
        <h1>{{ __('会員登録') }}</h1>
        @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
        @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
        @if(session('error'))<div class="banner error">{{ session('error') }}</div>@endif
        @include('partials.form-errors')
        @if(empty($registrationOpen))
          <p class="hint">{{ __('現在、新規登録は受け付けていません。管理者に依頼してください。') }}</p>
        @else
          <p class="hint">{{ __('新規登録は招待制です。管理者から招待コードを受け取ってください。登録するとライト権限で開始し、初期パスワードをメールでお送りします。') }}</p>
          <form method="post" action="/register" class="auth-form" id="register-form">
            @csrf
            <label>{{ __('メールアドレス') }}<input type="email" name="email" value="{{ old('email') }}" required autocomplete="username" /></label>
            <label>{{ __('表示名') }}<input type="text" name="displayName" value="{{ old('displayName') }}" maxlength="100" /></label>
            <label>{{ __('招待コード') }}<input type="text" name="inviteCode" value="{{ old('inviteCode') }}" required maxlength="120" autocomplete="off" /></label>
            <p class="agree-terms-hint" id="agree-terms-hint">{{ __('先に利用規約とプライバシーポリシーを開いて内容を確認してください。確認後に同意チェックが有効になります。') }}</p>
            <label class="checkbox-inline agree-terms-row" for="agree-terms">
              <input
                type="checkbox"
                name="agreeTerms"
                id="agree-terms"
                value="1"
                @checked(old('agreeTerms'))
                required
                disabled
                aria-describedby="agree-terms-hint"
              />
              <span>
                {!! __(':terms と :privacy に同意します。', [
                  'terms' => '<a href="/terms" target="_blank" rel="noopener" id="agree-link-terms" data-legal="terms">'.e(__('利用規約')).'</a>',
                  'privacy' => '<a href="/privacy" target="_blank" rel="noopener" id="agree-link-privacy" data-legal="privacy">'.e(__('プライバシーポリシー')).'</a>',
                ]) !!}
              </span>
            </label>
            <button type="submit" class="auth-submit" id="register-submit" disabled>{{ __('登録') }}</button>
          </form>
        @endif
        <div class="auth-links"><a href="/login">{{ __('ログインへ') }}</a></div>
      </div>
    </main>
    @include('partials.csrf-keepalive')
    @if(!empty($registrationOpen))
      <script>
        (() => {
          const TERMS_KEY = 'sa2_legal_terms_read';
          const PRIVACY_KEY = 'sa2_legal_privacy_read';
          const checkbox = document.getElementById('agree-terms');
          const submit = document.getElementById('register-submit');
          const hint = document.getElementById('agree-terms-hint');
          const linkTerms = document.getElementById('agree-link-terms');
          const linkPrivacy = document.getElementById('agree-link-privacy');
          if (!checkbox || !submit) return;

          const has = (key) => {
            try { return localStorage.getItem(key) === '1'; } catch (_) { return false; }
          };

          const refresh = () => {
            const termsOk = has(TERMS_KEY);
            const privacyOk = has(PRIVACY_KEY);
            linkTerms?.classList.toggle('is-read', termsOk);
            linkPrivacy?.classList.toggle('is-read', privacyOk);
            const ready = termsOk && privacyOk;
            checkbox.disabled = !ready;
            if (!ready) {
              checkbox.checked = false;
            }
            submit.disabled = !ready || !checkbox.checked;
            if (hint) {
              hint.textContent = ready
                ? @json(__('内容を確認済みです。同意する場合はチェックを入れて登録してください。'))
                : @json(__('先に利用規約とプライバシーポリシーを開いて内容を確認してください。確認後に同意チェックが有効になります。'));
            }
          };

          checkbox.addEventListener('change', refresh);
          // 別タブで /terms・/privacy を開いたとき（localStorage はタブ間で共有）
          window.addEventListener('storage', (event) => {
            if (event.key === TERMS_KEY || event.key === PRIVACY_KEY) {
              refresh();
            }
          });
          window.addEventListener('focus', refresh);
          document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') refresh();
          });
          // 戻ってきた直後の取りこぼし防止
          setInterval(refresh, 1500);
          refresh();
        })();
      </script>
    @endif
  </body>
</html>
