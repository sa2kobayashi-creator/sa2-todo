<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('マイページ') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'mypage'])
    <main class="page-main page-main-narrow">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="panel">
        <h2>{{ __('マイページ') }}</h2>
        <p class="hint">{{ __('アカウント情報の確認と編集ができます。') }}</p>

        <dl class="profile-dl">
          <dt>{{ __('表示名') }}</dt>
          <dd>{{ $user['displayName'] }}</dd>
          <dt>{{ __('メールアドレス') }}</dt>
          <dd>{{ $user['email'] }}</dd>
          <dt>{{ __('権限') }}</dt>
          <dd>
            <span class="role-badge {{ $user['role'] }}">{{ $user['roleLabel'] }}</span>
            <span class="hint" style="display:block;margin-top:6px;">{{ $user['roleDescription'] }}</span>
          </dd>
          <dt>{{ __('登録日') }}</dt>
          <dd>{{ $user['createdAt'] ?? '—' }}</dd>
          <dt>{{ __('最終更新') }}</dt>
          <dd>{{ $user['updatedAt'] ?? '—' }}</dd>
        </dl>
      </div>

      <div class="panel">
        <h2>{{ __('利用可能な機能') }}</h2>
        <ul class="feature-access-list">
          @foreach([
            'dashboard' => 'ダッシュボード',
            'todos' => 'Todo',
            'notes' => 'メモ',
            'photos' => 'Photos',
            'finance' => '入出金経費',
            'transit' => '路線検索',
            'map' => 'マップ',
            'settings' => '設定',
            'admin' => 'ユーザー管理',
          ] as $key => $label)
            <li class="{{ in_array($key, $features, true) ? 'is-allowed' : 'is-denied' }}">
              <span>{{ __($label) }}</span>
              <strong>{{ in_array($key, $features, true) ? __('利用可') : __('利用不可') }}</strong>
            </li>
          @endforeach
        </ul>
      </div>

      <div class="panel">
        <h2>{{ __('プロフィール編集') }}</h2>
        <form method="post" action="/mypage" class="stack-form">
          @csrf
          <label>{{ __('表示名') }}
            <input type="text" name="displayName" value="{{ old('displayName', $user['displayName']) }}" required maxlength="100" />
          </label>
          <label>{{ __('メールアドレス') }}
            <input type="email" name="email" value="{{ old('email', $user['email']) }}" required maxlength="255" />
          </label>
          <label>{{ __('新しいパスワード（変更する場合のみ）') }}
            <input type="password" name="password" minlength="8" autocomplete="new-password" />
          </label>
          <label>{{ __('新しいパスワード（確認）') }}
            <input type="password" name="password_confirmation" minlength="8" autocomplete="new-password" />
          </label>
          <button type="submit">{{ __('保存') }}</button>
        </form>
      </div>
    </main>
  </body>
</html>
