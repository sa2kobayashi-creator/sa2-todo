<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('ユーザー管理') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'admin'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      @include('partials.settings-subnav', ['active' => 'admin'])

      <div class="panel">
        <h2>{{ __('ユーザー管理') }}</h2>
        <p class="hint">{{ __('権限の基本メニューに加え、ユーザー単位で追加メニューを指定できます。グループ権限も合算されます。') }}</p>
        <ul class="role-permission-summary">
          @foreach($roles as $role)
            <li>
              <span class="role-badge {{ $role->value }}">{{ __($role->label()) }}</span>
              <span>{{ __($role->description()) }}</span>
            </li>
          @endforeach
        </ul>
      </div>

      @if(!empty($canManageRegistration))
      <div class="panel">
        <h2>{{ __('新規登録（招待コード）') }}</h2>
        <p class="hint">
          {{ __('招待コードを入れると、そのコードを知っている人だけ自己登録できます。空にすると自己登録は閉じ、下の「ユーザーを追加」だけで増やします。スーパー管理者のみ変更できます。') }}
        </p>
        @if(!empty($registrationOpen))
          <p class="banner notice">{{ __('現在: 自己登録は開いています') }}</p>
        @else
          <p class="banner notice">{{ __('現在: 自己登録は閉じています') }}</p>
        @endif
        @if(empty($registrationConfiguredInDatabase) && $registrationInviteCode !== '')
          <p class="hint">{{ __('いまは .env の REGISTRATION_INVITE_CODE が使われています。ここで保存すると管理画面の設定が優先されます。') }}</p>
        @endif
        <form method="post" action="/admin/users/registration" class="stack-form">
          @csrf
          <label>{{ __('招待コード') }}
            <input
              type="text"
              name="inviteCode"
              value="{{ old('inviteCode', $registrationInviteCode ?? '') }}"
              maxlength="120"
              autocomplete="off"
              placeholder="{{ __('空欄＝自己登録を閉じる') }}"
            />
          </label>
          <div class="modal-actions" style="justify-content:flex-start;gap:8px;flex-wrap:wrap;">
            <button type="submit">{{ __('招待コードを保存') }}</button>
            <button type="submit" name="clearInviteCode" value="1" class="secondary">{{ __('空にして閉じる') }}</button>
          </div>
        </form>
      </div>
      @endif

      <div class="panel">
        <h2>{{ __('ユーザーを追加') }}</h2>
        <form method="post" action="/admin/users" class="admin-user-form" id="admin-user-create-form">
          @csrf
          <input type="hidden" name="menuFeaturesConfigured" value="1" />
          <label>{{ __('表示名') }}
            <input type="text" name="displayName" value="{{ old('displayName') }}" required maxlength="100" />
          </label>
          <label>{{ __('メールアドレス') }}
            <input type="email" name="email" value="{{ old('email') }}" required maxlength="255" />
          </label>
          <label>{{ __('パスワード') }}
            <input type="password" name="password" required minlength="8" />
          </label>
          <label>{{ __('権限') }}
            <select name="role" id="create-user-role" required>
              @foreach(($assignableRoles ?? $roles) as $role)
                <option value="{{ $role->value }}" @selected(old('role', 'light') === $role->value)>{{ __($role->label()) }}</option>
              @endforeach
            </select>
          </label>
          <fieldset class="menu-feature-fieldset" id="create-menu-features">
            <legend>{{ __('利用メニュー') }}</legend>
            <p class="hint">{{ __('チェックを外したメニューはヘッダーに出ません。ダッシュボード・Todo・メモ・Photos は常に表示されます。ここでの設定はグループ付与より優先されます。') }}</p>
            <div class="menu-feature-checks">
              @foreach($menuFeatures as $feature)
                <label class="menu-feature-check">
                  <input
                    type="checkbox"
                    name="menuFeatures[]"
                    value="{{ $feature->value }}"
                    @checked(in_array($feature->value, old('menuFeatures', $menuFeaturesByRole['light'] ?? []), true))
                  />
                  <span>{{ __($feature->label()) }}</span>
                </label>
              @endforeach
            </div>
          </fieldset>
          <button type="submit">{{ __('追加') }}</button>
        </form>
      </div>

      <div class="panel">
        <h2>{{ __('ユーザー一覧') }} ({{ count($users) }})</h2>
        @if(count($users) === 0)
          <p class="hint">{{ __('ユーザーがいません。') }}</p>
        @else
          <div class="admin-users-table-wrap">
            <table class="admin-users-table admin-users-table-readonly">
              <thead>
                <tr>
                  <th scope="col">{{ __('表示名') }}</th>
                  <th scope="col">{{ __('メールアドレス') }}</th>
                  <th scope="col">{{ __('権限') }}</th>
                  <th scope="col">{{ __('利用メニュー') }}</th>
                  <th scope="col">{{ __('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                @foreach($users as $user)
                  <tr>
                    <td>
                      <strong>{{ $user['displayName'] }}</strong>
                      @if(!empty($user['isSelf']))
                        <span class="hint admin-users-self-tag">{{ __('（自分）') }}</span>
                      @endif
                    </td>
                    <td>{{ $user['email'] }}</td>
                    <td>
                      <span class="role-badge {{ $user['role'] }}">{{ $user['roleLabel'] }}</span>
                    </td>
                    <td>
                      <div class="menu-feature-labels">
                        @forelse($user['menuFeatureLabels'] as $label)
                          <span class="menu-feature-label">{{ $label }}</span>
                        @empty
                          <span class="hint">{{ __('なし') }}</span>
                        @endforelse
                      </div>
                    </td>
                    <td class="admin-users-actions">
                      <a href="/admin/users/{{ $user['id'] }}" class="secondary mini-btn">{{ __('詳細') }}</a>
                      @if(!empty($user['canManageTarget']))
                        <a href="/admin/users/{{ $user['id'] }}/edit" class="secondary mini-btn">{{ __('編集') }}</a>
                        @if(empty($user['isSelf']))
                          <form method="post" action="/admin/users/{{ $user['id'] }}/delete" class="inline-form" onsubmit='return confirm(@json(__('このユーザーを削除しますか？')))'>
                            @csrf
                            <button type="submit" class="danger mini-btn">{{ __('削除') }}</button>
                          </form>
                        @endif
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </div>
    </main>
    <script>
      (function () {
        const byRole = @json($menuFeaturesByRole);
        const createRole = document.getElementById('create-user-role');
        const createFieldset = document.getElementById('create-menu-features');

        function applyRoleDefaults(container, role) {
          if (!container) return;
          const defaults = byRole[role] || [];
          container.querySelectorAll('input[type="checkbox"][name="menuFeatures[]"]').forEach((input) => {
            input.checked = defaults.includes(input.value);
            input.disabled = role === 'admin' || role === 'super_admin';
          });
          if (role === 'admin' || role === 'super_admin') {
            container.classList.add('is-admin-locked');
          } else {
            container.classList.remove('is-admin-locked');
          }
        }

        if (createRole && createFieldset) {
          createRole.addEventListener('change', () => applyRoleDefaults(createFieldset, createRole.value));
          applyRoleDefaults(createFieldset, createRole.value);
        }
      })();
    </script>
  </body>
</html>
