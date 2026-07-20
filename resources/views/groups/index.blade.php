<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('グループ') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'groups'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      <div class="panel">
        <h2>{{ __('グループを申請') }}</h2>
        <p class="hint">{{ __('作成したグループは管理者の承認後に共有先として利用できます。') }}</p>
        <form method="post" action="/groups" class="stack-form">
          @csrf
          <label>{{ __('グループ名') }}
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="120" />
          </label>
          <label>{{ __('説明（任意）') }}
            <input type="text" name="description" value="{{ old('description') }}" maxlength="500" />
          </label>
          <button type="submit">{{ __('申請する') }}</button>
        </form>
      </div>

      <div class="panel">
        <h2>{{ __('参加中のグループ') }} ({{ count($groups) }})</h2>
        @forelse($groups as $group)
          <div class="admin-user-card">
            <div class="admin-user-card-head">
              <strong>{{ $group['name'] }}</strong>
              <span class="role-badge">{{ $group['statusLabel'] }}</span>
              <span class="hint">{{ __('メンバー') }}: {{ $group['memberCount'] }}</span>
            </div>
            @if(!empty($group['description']))
              <p class="hint">{{ $group['description'] }}</p>
            @endif
            <p class="hint">{{ __('オーナー') }}: {{ $group['ownerName'] ?? '—' }}</p>

            @if(($group['ownerUserId'] ?? null) === ($currentUser['id'] ?? null) && ($group['status'] ?? '') === 'approved')
              <form method="post" action="/groups/{{ $group['id'] }}/members" class="stack-form" style="margin-top:12px;">
                @csrf
                <label>{{ __('メンバーを追加') }}
                  <select name="user_id" required>
                    <option value="">{{ __('ユーザーを選択') }}</option>
                    @foreach($users as $user)
                      <option value="{{ $user['id'] }}">{{ $user['displayName'] }} ({{ $user['email'] }})</option>
                    @endforeach
                  </select>
                </label>
                <button type="submit" class="secondary">{{ __('追加') }}</button>
              </form>

              @if(!empty($memberDetails[$group['id']]))
                <ul class="feature-access-list" style="margin-top:12px;">
                  @foreach($memberDetails[$group['id']] as $member)
                    <li>
                      <span>{{ $member['displayName'] }} <span class="hint">({{ $member['role'] }})</span></span>
                      @if(($member['role'] ?? '') !== 'owner')
                        <form method="post" action="/groups/{{ $group['id'] }}/members/remove" class="inline-form" onsubmit='return confirm(@json(__('このメンバーを削除しますか？')))'>
                          @csrf
                          <input type="hidden" name="user_id" value="{{ $member['userId'] }}" />
                          <button type="submit" class="danger mini-btn">{{ __('削除') }}</button>
                        </form>
                      @endif
                    </li>
                  @endforeach
                </ul>
              @endif
            @endif
          </div>
        @empty
          <p class="hint">{{ __('参加中のグループはありません。') }}</p>
        @endforelse
      </div>
    </main>
  </body>
</html>
