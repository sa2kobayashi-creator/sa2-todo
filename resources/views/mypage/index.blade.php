<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('マイページ') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'mypage'])
    <main class="page-main page-main-narrow">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif
      @include('partials.form-errors')

      <div class="panel">
        <h2>{{ __('マイページ') }}</h2>
        <p class="hint">{{ __('アカウント情報の確認と編集ができます。') }}</p>

        <dl class="profile-dl">
          <dt>{{ __('表示名') }}</dt>
          <dd>{{ $user['displayName'] }}</dd>
          <dt>{{ __('メールアドレス') }}</dt>
          <dd>
            {{ $user['email'] }}
            @if(!empty($hasPendingEmail))
              <span class="hint" style="display:block;margin-top:6px;">
                {{ __(':emailへの変更を確認待ちです。', ['email' => $user['pendingEmail']]) }}
                <a href="/mypage/email/verify">{{ __('確認画面を開く') }}</a>
              </span>
            @endif
          </dd>
          <dt>{{ __('権限') }}</dt>
          <dd>
            <span class="role-badge {{ $user['role'] }}">{{ $user['roleLabel'] }}</span>
            <span class="hint" style="display:block;margin-top:6px;">{{ $user['roleDescription'] }}</span>
          </dd>
          <dt>{{ __('所属グループ') }}</dt>
          <dd>
            @if(empty($groups))
              <span class="hint">{{ __('所属しているグループはありません。') }}</span>
            @else
              <ul class="mypage-group-list">
                @foreach($groups as $group)
                  <li>
                    <span class="mypage-group-name">{{ $group['name'] }}</span>
                    <span class="group-status-badge {{ $group['status'] }}">{{ $group['statusLabel'] }}</span>
                    <span class="hint">{{ __(':count人', ['count' => $group['memberCount']]) }}</span>
                  </li>
                @endforeach
              </ul>
            @endif
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
            'music' => '音楽',
            'video' => '動画',
            'groups' => 'グループ',
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
          @error('displayName')<p class="field-error">{{ $message }}</p>@enderror
          <label>{{ __('メールアドレス') }}
            <input type="email" name="email" value="{{ old('email', $user['email']) }}" required maxlength="255" autocomplete="email" />
          </label>
          @error('email')<p class="field-error">{{ $message }}</p>@enderror
          <p class="hint">{{ __('メールアドレスを変えると、新しいアドレスに6桁の確認コードを送ります。コードを入力するまで、ログインIDは今のままです。') }}</p>
          <button type="submit">{{ __('保存') }}</button>
        </form>
      </div>

      <div class="panel">
        <h2>{{ __('パスワードの変更') }}</h2>
        <p class="hint">
          {{ __('登録メールアドレスに6桁の確認コードをお送りします。次の画面でコードと新しいパスワードを入力してください。') }}
        </p>
        <form method="post" action="/mypage/password/request-code" class="stack-form">
          @csrf
          <button type="submit">{{ __('確認コードを送信') }}</button>
        </form>
      </div>

      @include('settings.partials.google-calendar')
      @include('mypage.partials.line-link')
      @include('mypage.partials.messenger-link')

      <div class="panel" id="account-delete" style="border-color:#f3c1c1;">
        <h2>{{ __('退会（アカウント削除）') }}</h2>
        <p class="hint">{{ __('アカウントと、写真・メモ・音楽などのデータを削除します。この操作は取り消せません。') }}</p>
        <form method="post" action="/mypage/delete" class="stack-form" onsubmit="return confirm(@json(__('本当に退会しますか？データは復元できません。')))">
          @csrf
          <label>{{ __('現在のパスワード') }}
            <input type="password" name="password" required autocomplete="current-password" />
          </label>
          <label>{{ __('確認（「退会」または DELETE と入力）') }}
            <input type="text" name="confirm" required autocomplete="off" placeholder="退会" />
          </label>
          <button type="submit" class="danger">{{ __('退会する') }}</button>
        </form>
      </div>
    </main>
  </body>
</html>
