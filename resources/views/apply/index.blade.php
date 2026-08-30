<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('利用申請') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body class="auth-body">
    @include('partials.auth-lang-switcher')
    <main class="auth-shell auth-shell-wide">
      <a href="/" class="auth-brand">
        <img src="{{ asset('icons/app-icon.png') }}" alt="" class="site-logo-icon" width="40" height="40" />
        <span>{{ config('app.name') }}</span>
      </a>
      <div class="auth-card panel">
        <h1>{{ __('利用申請') }}</h1>
        @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
        @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
        @if(session('error'))<div class="banner error">{{ session('error') }}</div>@endif
        @if(session('notice'))<div class="banner notice">{{ session('notice') }}</div>@endif
        @include('partials.form-errors')

        <p class="hint">{{ __('必要事項を送ってください。ライト（お試し）は自動審査のうえ登録メールが届きます。スタンダード／テナントは運営確認後にメールします。') }}</p>

        <form method="post" action="/apply" class="auth-form" id="apply-form">
          @csrf
          <fieldset class="apply-plan-fieldset">
            <legend>{{ __('希望プラン') }}</legend>
            @foreach($plans as $plan)
              <label class="apply-plan-option">
                <input
                  type="radio"
                  name="plan"
                  value="{{ $plan->value }}"
                  @checked(old('plan', $selectedPlan->value) === $plan->value)
                  required
                />
                <span>
                  <strong>{{ __($plan->label()) }}</strong>
                  <span class="hint" style="display:block;">{{ __($plan->description()) }}</span>
                  @if($plan->value === 'standard')
                    <span class="hint" style="display:block;">{{ __('¥:yen／月（税込）・最初の:days日無料', ['yen' => number_format($standardMonthlyYen), 'days' => $standardTrialDays]) }}</span>
                  @elseif($plan->value === 'tenant')
                    <span class="hint" style="display:block;">{{ __('¥:yen／月（税込）〜', ['yen' => number_format($tenantMonthlyYen)]) }}</span>
                  @endif
                </span>
              </label>
            @endforeach
          </fieldset>

          <label>{{ __('表示名') }} <span class="req">*</span>
            <input type="text" name="display_name" value="{{ old('display_name') }}" required maxlength="100" autocomplete="name" />
          </label>
          <label>{{ __('ログイン用メールアドレス') }} <span class="req">*</span>
            <input type="email" name="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email" />
          </label>
          <label id="apply-org-row" {{ old('plan', $selectedPlan->value) === 'tenant' ? '' : 'hidden' }}>
            {{ __('組織・家族名') }} <span class="req">*</span>
            <input type="text" name="organization_name" value="{{ old('organization_name') }}" maxlength="120" autocomplete="organization" />
          </label>
          <label>{{ __('電話番号（任意）') }}
            <input type="text" name="phone" value="{{ old('phone') }}" maxlength="40" autocomplete="tel" />
          </label>
          <label>{{ __('利用目的') }} <span class="req">*</span>
            <textarea name="message" rows="3" maxlength="2000" required minlength="{{ app(\App\Services\RegistrationApplicationService::class)->purposeMinLength() }}" placeholder="{{ __('例: Todoと写真を試したい。家族の予定共有を検討中、など（:min文字以上）', ['min' => app(\App\Services\RegistrationApplicationService::class)->purposeMinLength()]) }}">{{ old('message') }}</textarea>
            <span class="hint">{{ __('空欄や極端に短い内容、一時メールは自動でお断りします。') }}</span>
          </label>

          @include('partials.agree-terms', [
            'submitButtonId' => 'apply-submit',
            'submitLabel' => __('申請する'),
            'readyHint' => __('内容を確認済みです。同意する場合はチェックを入れて申請してください。'),
          ])
        </form>

        <div class="auth-links">
          <a href="/login">{{ __('ログインへ') }}</a>
          <a href="/">{{ __('トップへ') }}</a>
        </div>
      </div>
    </main>
    @include('partials.csrf-keepalive')
    <script>
      (() => {
        const orgRow = document.getElementById('apply-org-row')
        const planInputs = document.querySelectorAll('input[name="plan"]')
        const syncOrg = () => {
          const plan = document.querySelector('input[name="plan"]:checked')?.value
          if (!orgRow) return
          if (plan === 'tenant') {
            orgRow.hidden = false
            orgRow.querySelector('input')?.setAttribute('required', 'required')
          } else {
            orgRow.hidden = true
            orgRow.querySelector('input')?.removeAttribute('required')
          }
        }
        planInputs.forEach((el) => el.addEventListener('change', syncOrg))
        syncOrg()
      })()
    </script>
  </body>
</html>
