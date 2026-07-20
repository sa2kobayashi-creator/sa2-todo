<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('ユーザー管理') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'admin'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="panel">
        <h2>{{ __('ユーザー管理') }}</h2>
        <p class="hint">{{ __('権限ごとに表示・編集できるメニューが変わります。') }}</p>
        <ul class="role-permission-summary">
          @foreach($roles as $role)
            <li>
              <span class="role-badge {{ $role->value }}">{{ __($role->label()) }}</span>
              <span>{{ __($role->description()) }}</span>
            </li>
          @endforeach
        </ul>
      </div>

      <div class="panel">
        <h2>{{ __('ユーザーを追加') }}</h2>
        <form method="post" action="/admin/users" class="admin-user-form">
          @csrf
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
            <select name="role" required>
              @foreach($roles as $role)
                <option value="{{ $role->value }}" @selected(old('role', 'standard') === $role->value)>{{ __($role->label()) }}</option>
              @endforeach
            </select>
          </label>
          <button type="submit">{{ __('追加') }}</button>
        </form>
      </div>

      <div class="panel">
        <h2>{{ __('ユーザー一覧') }} ({{ count($users) }})</h2>
        @if(count($users) === 0)
          <p class="hint">{{ __('ユーザーがいません。') }}</p>
        @else
          <div class="admin-users-table-wrap">
            <table class="admin-users-table">
              <thead>
                <tr>
                  <th scope="col">{{ __('表示名') }}</th>
                  <th scope="col">{{ __('メールアドレス') }}</th>
                  <th scope="col">{{ __('権限') }}</th>
                  <th scope="col">{{ __('パスワード') }}</th>
                  <th scope="col">{{ __('操作') }}</th>
                </tr>
              </thead>
              <tbody>
                @foreach($users as $user)
                  <tr>
                    <td>
                      <form method="post" action="/admin/users/{{ $user['id'] }}/update" class="admin-users-row-form" id="user-update-{{ $user['id'] }}">
                        @csrf
                        <input type="text" name="displayName" value="{{ $user['displayName'] }}" required maxlength="100" aria-label="{{ __('表示名') }}" />
                        @if(!empty($user['isSelf']))
                          <span class="hint admin-users-self-tag">{{ __('（自分）') }}</span>
                        @endif
                      </form>
                    </td>
                    <td>
                      <input form="user-update-{{ $user['id'] }}" type="email" name="email" value="{{ $user['email'] }}" required maxlength="255" aria-label="{{ __('メールアドレス') }}" />
                    </td>
                    <td>
                      <select form="user-update-{{ $user['id'] }}" name="role" required aria-label="{{ __('権限') }}">
                        @foreach($roles as $role)
                          <option value="{{ $role->value }}" @selected($user['role'] === $role->value)>{{ __($role->label()) }}</option>
                        @endforeach
                      </select>
                    </td>
                    <td>
                      <form method="post" action="/admin/users/{{ $user['id'] }}/password" class="admin-users-password-row">
                        @csrf
                        <input type="password" name="password" required minlength="8" placeholder="{{ __('新しいパスワード') }}" aria-label="{{ __('新しいパスワード') }}" />
                        <input type="password" name="password_confirmation" required minlength="8" placeholder="{{ __('確認') }}" aria-label="{{ __('パスワード（確認）') }}" />
                        <button type="submit" class="secondary mini-btn">{{ __('変更') }}</button>
                      </form>
                    </td>
                    <td class="admin-users-actions">
                      <button type="submit" form="user-update-{{ $user['id'] }}" class="secondary mini-btn">{{ __('更新') }}</button>
                      @if(empty($user['isSelf']))
                        <form method="post" action="/admin/users/{{ $user['id'] }}/delete" class="inline-form" onsubmit='return confirm(@json(__('このユーザーを削除しますか？')))'>
                          @csrf
                          <button type="submit" class="danger mini-btn">{{ __('削除') }}</button>
                        </form>
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
  </body>
</html>
