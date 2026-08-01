<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('ユーザー編集') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'admin'])
    <main class="page-main page-main-narrow">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      @include('partials.settings-subnav', ['active' => 'admin'])

      <div class="panel">
        <div class="admin-user-card-head">
          <h2>{{ __('ユーザー編集') }}</h2>
          <div class="admin-users-actions">
            <a href="/admin/users/{{ $user['id'] }}" class="secondary mini-btn">{{ __('詳細') }}</a>
            <a href="/admin/users" class="secondary mini-btn">{{ __('一覧に戻る') }}</a>
          </div>
        </div>

        <form method="post" action="/admin/users/{{ $user['id'] }}/update" class="admin-user-form" id="admin-user-edit-form">
          @csrf
          <input type="hidden" name="menuFeaturesConfigured" value="1" id="edit-menu-configured" />
          <label>{{ __('表示名') }}
            <input type="text" name="displayName" value="{{ old('displayName', $user['displayName']) }}" required maxlength="100" />
          </label>
          <label>{{ __('メールアドレス') }}
            <input type="email" name="email" value="{{ old('email', $user['email']) }}" required maxlength="255" />
          </label>
          <label>{{ __('権限') }}
            <select name="role" id="edit-user-role" required>
              @foreach($roles as $role)
                <option value="{{ $role->value }}" @selected(old('role', $user['role']) === $role->value)>{{ __($role->label()) }}</option>
              @endforeach
            </select>
          </label>
          <fieldset class="menu-feature-fieldset" id="edit-menu-features">
            <legend>{{ __('利用メニュー') }}</legend>
            <p class="hint">{{ __('管理者はすべてのメニューを利用できます。') }}</p>
            <div class="menu-feature-checks">
              @foreach($menuFeatures as $feature)
                <label class="menu-feature-check">
                  <input
                    type="checkbox"
                    name="menuFeatures[]"
                    value="{{ $feature->value }}"
                    @checked(in_array($feature->value, old('menuFeatures', $user['menuFeatures'] ?? []), true))
                  />
                  <span>{{ __($feature->label()) }}</span>
                </label>
              @endforeach
            </div>
          </fieldset>
          <button type="submit">{{ __('保存') }}</button>
        </form>
      </div>

      <div class="panel">
        <h2>{{ __('パスワード') }}</h2>
        <p class="hint">{{ __('管理者がパスワードを設定することはできません。ご本人にログイン画面の「パスワードをお忘れですか？」から再設定していただいてください。') }}</p>
      </div>
    </main>
    <script>
      (function () {
        const byRole = @json($menuFeaturesByRole);
        const roleSelect = document.getElementById('edit-user-role');
        const fieldset = document.getElementById('edit-menu-features');
        const configuredInput = document.getElementById('edit-menu-configured');

        function applyRoleDefaults(role, useDefaults) {
          if (!fieldset) return;
          const defaults = byRole[role] || [];
          fieldset.querySelectorAll('input[type="checkbox"][name="menuFeatures[]"]').forEach((input) => {
            if (useDefaults) {
              input.checked = defaults.includes(input.value);
            }
            input.disabled = role === 'admin';
          });
          if (role === 'admin') {
            fieldset.classList.add('is-admin-locked');
            if (configuredInput) configuredInput.disabled = true;
          } else {
            fieldset.classList.remove('is-admin-locked');
            if (configuredInput) configuredInput.disabled = false;
          }
        }

        if (roleSelect && fieldset) {
          roleSelect.addEventListener('change', () => applyRoleDefaults(roleSelect.value, true));
          applyRoleDefaults(roleSelect.value, false);
        }
      })();
    </script>
  </body>
</html>
