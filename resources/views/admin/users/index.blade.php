<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('ユーザー管理') }} - Sa2 ToDo</title>
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
        @forelse($users as $user)
          <div class="admin-user-card">
            <div class="admin-user-card-head">
              <strong>{{ $user['displayName'] }}</strong>
              <span class="role-badge {{ $user['role'] }}">{{ $user['roleLabel'] }}</span>
              @if(!empty($user['isSelf']))
                <span class="hint">{{ __('（自分）') }}</span>
              @endif
            </div>

            <form method="post" action="/admin/users/{{ $user['id'] }}/update" class="admin-user-form">
              @csrf
              <label>{{ __('表示名') }}
                <input type="text" name="displayName" value="{{ $user['displayName'] }}" required maxlength="100" />
              </label>
              <label>{{ __('メールアドレス') }}
                <input type="email" name="email" value="{{ $user['email'] }}" required maxlength="255" />
              </label>
              <label>{{ __('権限') }}
                <select name="role" required>
                  @foreach($roles as $role)
                    <option value="{{ $role->value }}" @selected($user['role'] === $role->value)>{{ __($role->label()) }}</option>
                  @endforeach
                </select>
              </label>
              <button type="submit" class="secondary">{{ __('更新') }}</button>
            </form>

            <form method="post" action="/admin/users/{{ $user['id'] }}/password" class="admin-user-password-form">
              @csrf
              <label>{{ __('新しいパスワード') }}
                <input type="password" name="password" required minlength="8" />
              </label>
              <label>{{ __('パスワード（確認）') }}
                <input type="password" name="password_confirmation" required minlength="8" />
              </label>
              <button type="submit" class="secondary">{{ __('パスワード変更') }}</button>
            </form>

            @if(empty($user['isSelf']))
              <form method="post" action="/admin/users/{{ $user['id'] }}/delete" class="admin-user-delete-form inline-form" onsubmit='return confirm(@json(__('このユーザーを削除しますか？')))'>
                @csrf
                <button type="submit" class="danger mini-btn">{{ __('削除') }}</button>
              </form>
            @endif
          </div>
        @empty
          <p class="hint">{{ __('ユーザーがいません。') }}</p>
        @endforelse
      </div>
    </main>
  </body>
</html>
