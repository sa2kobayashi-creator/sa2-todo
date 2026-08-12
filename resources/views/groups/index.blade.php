<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('グループ') }} - {{ config('app.name') }}</title>
    @include('partials.app-css')
  </head>
  <body>
    @include('partials.header', ['active' => 'groups'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      @include('partials.group-invitations', ['pendingGroupInvitations' => $pendingInvitations ?? []])

      <div class="panel">
        <h2>{{ !empty($isAdmin) ? __('グループを作成') : __('グループを申請') }}</h2>
        <p class="hint">
          {{ !empty($isAdmin)
            ? __('管理者が作成したグループは承認済みで登録され、すぐに共有先として使えます。')
            : __('作成したグループは管理者の承認後に共有先として利用できます。') }}
        </p>
        @include('partials.form-errors')
        <form method="post" action="/groups" class="stack-form">
          @csrf
          <label>{{ __('グループ名') }}
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="120" />
          </label>
          <label>{{ __('説明（任意）') }}
            <input type="text" name="description" value="{{ old('description') }}" maxlength="500" />
          </label>
          <button type="submit">{{ !empty($isAdmin) ? __('作成する') : __('申請する') }}</button>
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

            @if(($group['ownerUserId'] ?? null) === ($currentUser['id'] ?? null))
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                <form method="post" action="/groups/{{ $group['id'] }}/delete" class="inline-form" onsubmit='return confirm(@json(__('このグループを削除しますか？共有設定は解除されます。')))'>
                  @csrf
                  <button type="submit" class="danger mini-btn">{{ __('グループを削除') }}</button>
                </form>
              </div>
            @endif

            @if(($group['ownerUserId'] ?? null) === ($currentUser['id'] ?? null) && ($group['status'] ?? '') === 'approved')
              <form method="post" action="/groups/{{ $group['id'] }}/members" class="stack-form" style="margin-top:12px;">
                @csrf
                <label>{{ __('メールアドレスで招待') }}
                  <input type="email" name="email" value="{{ old('email') }}" required maxlength="255" placeholder="{{ __('登録済みのメール') }}" autocomplete="off" />
                </label>
                <p class="hint">{{ __('相手が承諾するまでメンバーにはなりません。ユーザー一覧は表示しません。') }}</p>
                <button type="submit" class="secondary">{{ __('招待する') }}</button>
              </form>

              @if(!empty($pendingByGroup[$group['id']]))
                <p class="hint" style="margin-top:12px">{{ __('承諾待ち') }}</p>
                <ul class="feature-access-list">
                  @foreach($pendingByGroup[$group['id']] as $invitation)
                    <li>
                      <span>{{ $invitation['inviteeName'] }} <span class="hint">({{ $invitation['inviteeEmail'] }})</span></span>
                      <span class="hint">{{ $invitation['statusLabel'] }}</span>
                    </li>
                  @endforeach
                </ul>
              @endif

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
