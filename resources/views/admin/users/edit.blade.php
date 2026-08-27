<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('ユーザー編集') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'admin'])
    <main class="page-main page-main-narrow">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="panel">
        <div class="admin-user-card-head">
          <h2>{{ __('ユーザー編集') }}</h2>
          <nav class="admin-page-actions">
            <a href="/admin/users/{{ $user['id'] }}" class="button-link secondary">{{ __('詳細') }}</a>
            <a href="/admin/users" class="button-link secondary admin-back-link">{{ __('一覧に戻る') }}</a>
          </nav>
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
              @foreach(($assignableRoles ?? $roles) as $role)
                <option value="{{ $role->value }}" @selected(old('role', $user['role']) === $role->value)>{{ __($role->label()) }}</option>
              @endforeach
              @if(!collect($assignableRoles ?? $roles)->contains(fn ($role) => $role->value === $user['role']))
                <option value="{{ $user['role'] }}" selected>{{ $user['roleLabel'] }}</option>
              @endif
            </select>
          </label>
          <fieldset class="menu-feature-fieldset" id="edit-menu-features">
            <legend>{{ __('利用メニュー') }}</legend>
            <p class="hint">{{ __('チェックを外したメニューはヘッダーに出ません。ダッシュボード・Todo・メモ・Photos は常に表示されます。ここでの設定はグループ付与より優先されます。') }}</p>
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
          @if(!empty($canAssignSpecialQuota) && !empty($user['canUseSpecialQuota']))
            <fieldset class="menu-feature-fieldset">
              <legend>{{ __('特別枠') }}</legend>
              <p class="hint">{{ __('制限管理の「特別枠」テンプレートを使います。自己登録の招待コードとは別です。テナント所属には使えません。') }}</p>
              <label class="menu-feature-check">
                <input type="checkbox" name="specialQuota" value="1" @checked(old('specialQuota', !empty($user['specialQuota']))) />
                <span>{{ __('特別枠にする') }}</span>
              </label>
            </fieldset>
          @elseif(!empty($canAssignSpecialQuota) && empty($user['canUseSpecialQuota']))
            <p class="hint">{{ __('テナント所属または運営者は特別枠を使いません。テナントは契約プール、運営者は上限なしです。') }}</p>
          @endif
          <fieldset class="menu-feature-fieldset">
            <legend>{{ __('契約・課金') }}</legend>
            <p class="hint">{{ __('Stripe 導入前の手動運用です。請求書で入金を確認したらここで有効にしてください。権限（ロール）とは別に保存されます。') }}</p>
            <label>{{ __('契約状態') }}
              <select name="subscriptionStatus">
                @foreach(($subscriptionStatuses ?? []) as $status)
                  <option value="{{ $status->value }}" @selected(old('subscriptionStatus', $user['subscriptionStatus'] ?? 'none') === $status->value)>{{ __($status->label()) }}</option>
                @endforeach
              </select>
            </label>
            <label>{{ __('お試し期限') }}
              <input type="date" name="trialEndsAt" value="{{ old('trialEndsAt', $user['trialEndsAt'] ?? '') }}" />
            </label>
            <label class="menu-feature-check">
              <input type="checkbox" name="storageOverageActive" value="1" @checked(old('storageOverageActive', !empty($user['storageOverageActive']))) />
              <span>{{ __('ストレージ有料超過を許可') }}</span>
            </label>
            <label class="menu-feature-check">
              <input type="checkbox" name="mailboxAddonActive" value="1" @checked(old('mailboxAddonActive', !empty($user['mailboxAddonActive']))) />
              <span>{{ __('メールボックス有料オプション') }}</span>
            </label>
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
        const form = document.getElementById('admin-user-edit-form');
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
            input.disabled = role === 'admin' || role === 'super_admin';
          });
          if (role === 'admin' || role === 'super_admin') {
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

        // disabled のチェックは送信されないため、送信直前に有効化する
        if (form && fieldset) {
          form.addEventListener('submit', () => {
            const role = roleSelect ? roleSelect.value : '';
            if (role === 'admin' || role === 'super_admin') return;
            fieldset.querySelectorAll('input[type="checkbox"][name="menuFeatures[]"]').forEach((input) => {
              input.disabled = false;
            });
            if (configuredInput) configuredInput.disabled = false;
          });
        }
      })();
    </script>
  </body>
</html>
