<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('ユーザー詳細') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'admin'])
    <main class="page-main page-main-narrow">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="panel">
        <div class="admin-user-card-head">
          <h2>{{ __('ユーザー詳細') }}</h2>
          <nav class="admin-page-actions">
            @if(!empty($user['canManageTarget']))
              <a href="/admin/users/{{ $user['id'] }}/edit" class="button-link">{{ __('編集') }}</a>
            @endif
            <a href="/admin/users" class="button-link secondary admin-back-link">{{ __('一覧に戻る') }}</a>
          </nav>
        </div>

        <dl class="admin-user-detail">
          <div>
            <dt>{{ __('表示名') }}</dt>
            <dd>
              {{ $user['displayName'] }}
              @if(!empty($user['isSelf']))
                <span class="hint admin-users-self-tag">{{ __('（自分）') }}</span>
              @endif
            </dd>
          </div>
          <div>
            <dt>{{ __('メールアドレス') }}</dt>
            <dd>{{ $user['email'] }}</dd>
          </div>
          <div>
            <dt>{{ __('権限') }}</dt>
            <dd><span class="role-badge {{ $user['role'] }}">{{ $user['roleLabel'] }}</span></dd>
          </div>
          <div>
            <dt>{{ __('契約状態') }}</dt>
            <dd>{{ $user['subscriptionStatusLabel'] ?? __('未契約') }}</dd>
          </div>
          <div>
            <dt>{{ __('お試し期限') }}</dt>
            <dd>{{ $user['trialEndsAt'] ?? '—' }}</dd>
          </div>
          <div>
            <dt>{{ __('ストレージ有料超過') }}</dt>
            <dd>{{ !empty($user['storageOverageActive']) ? __('許可') : __('なし') }}</dd>
          </div>
          <div>
            <dt>{{ __('メールボックスオプション') }}</dt>
            <dd>{{ !empty($user['mailboxAddonActive']) ? __('有効') : __('なし') }}</dd>
          </div>
          <div>
            <dt>{{ __('特別枠') }}</dt>
            <dd>{{ !empty($user['specialQuota']) ? __('あり') : __('なし') }}</dd>
          </div>
          <div>
            <dt>{{ __('利用メニュー') }}</dt>
            <dd>
              <div class="menu-feature-labels">
                @forelse($user['menuFeatureLabels'] as $label)
                  <span class="menu-feature-label">{{ $label }}</span>
                @empty
                  <span class="hint">{{ __('なし') }}</span>
                @endforelse
              </div>
            </dd>
          </div>
          <div>
            <dt>{{ __('作成日時') }}</dt>
            <dd>{{ $user['createdAt'] ?? '—' }}</dd>
          </div>
          <div>
            <dt>{{ __('更新日時') }}</dt>
            <dd>{{ $user['updatedAt'] ?? '—' }}</dd>
          </div>
        </dl>

        @if(empty($user['isSelf']) && !empty($user['canManageTarget']))
          <form method="post" action="/admin/users/{{ $user['id'] }}/delete" class="admin-user-delete-form" onsubmit='return confirm(@json(__('このユーザーを削除しますか？')))'>
            @csrf
            <button type="submit" class="danger">{{ __('削除') }}</button>
          </form>
        @endif
      </div>
    </main>
  </body>
</html>
