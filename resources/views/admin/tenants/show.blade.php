<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $tenant['name'] }} - {{ __('テナント契約') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'admin-tenants'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="panel">
        <p class="hint"><a href="/admin/tenants">{{ __('契約一覧に戻る') }}</a></p>
        <h2>{{ $tenant['name'] }}</h2>
        <p class="hint">{{ __('管理者は代表1名です。追加ユーザーはスタンダードまたはライト。メールは各1アドレス込み。上限を超える人数は運営が変更します。') }}</p>
        <form method="post" action="/admin/tenants/{{ $tenant['id'] }}" class="stack-form">
          @csrf
          <label>{{ __('契約名') }}
            <input type="text" name="name" value="{{ old('name', $tenant['name']) }}" required maxlength="120" />
          </label>
          <label>{{ __('ユーザー上限') }}
            <input type="number" name="max_users" value="{{ old('max_users', $tenant['maxUsers']) }}" min="1" max="200" required />
          </label>
          <label>{{ __('状態') }}
            <select name="status" required>
              <option value="active" @selected(old('status', $tenant['status']) === 'active')>{{ __('利用中') }}</option>
              <option value="suspended" @selected(old('status', $tenant['status']) === 'suspended')>{{ __('停止中') }}</option>
            </select>
          </label>
          <label class="menu-feature-check">
            <input type="hidden" name="allow_own_keys" value="0" />
            <input type="checkbox" name="allow_own_keys" value="1" @checked(old('allow_own_keys', $tenant['allowOwnKeys'] ? '1' : '0') === '1') />
            <span>{{ __('契約代表が R2／B2／AI／API キーを自分で設定できる') }}</span>
          </label>
          <label>{{ __('メモ（運営用）') }}
            <textarea name="notes" rows="3" maxlength="2000">{{ old('notes', $tenant['notes']) }}</textarea>
          </label>
          <button type="submit">{{ __('保存') }}</button>
        </form>
      </div>

      <div class="panel">
        <h2>{{ __('所属ユーザー') }} ({{ count($members) }})</h2>
        @forelse($members as $member)
          <p>
            <a href="/admin/users/{{ $member['id'] }}">{{ $member['displayName'] }}</a>
            <span class="role-badge {{ $member['role'] }}">{{ $member['roleLabel'] }}</span>
            <span class="hint">{{ $member['email'] }}</span>
          </p>
        @empty
          <p class="hint">{{ __('ユーザーがいません。') }}</p>
        @endforelse
      </div>
    </main>
  </body>
</html>
