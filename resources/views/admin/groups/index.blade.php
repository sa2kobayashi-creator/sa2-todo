<!DOCTYPE html>
<html lang="{{ $htmlLang ?? app()->getLocale() }}">
  <head>
    <meta charset="UTF-8" />
    @include('partials.brand-head')
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ __('グループ管理') }} - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}" />
  </head>
  <body>
    @include('partials.header', ['active' => 'admin-groups'])
    <main class="page-main">
      @if(!empty($notice))<div class="banner notice">{{ $notice }}</div>@endif
      @if(!empty($error))<div class="banner error">{{ $error }}</div>@endif

      @include('partials.settings-subnav', ['active' => 'admin-groups'])

      <div class="panel">
        <h2>{{ __('グループ管理') }}</h2>
        <p class="hint">{{ __('申請されたグループの承認・却下を行います。承認後に ToDo / Photos の共有先として利用でき、メニュー権限もメンバーに付与できます。') }}</p>
      </div>

      <div class="panel">
        <h2>{{ __('グループを追加') }}</h2>
        <p class="hint">{{ __('ここから作成したグループは承認済みで登録され、すぐに共有先として使えます。') }}</p>
        @include('partials.form-errors')
        <form method="post" action="/admin/groups" class="stack-form">
          @csrf
          <label>{{ __('グループ名') }}
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="120" />
          </label>
          <label>{{ __('説明（任意）') }}
            <input type="text" name="description" value="{{ old('description') }}" maxlength="500" />
          </label>
          <label>{{ __('オーナー') }}
            <select name="owner_user_id">
              <option value="">{{ __('自分（管理者）') }}</option>
              @foreach($users as $user)
                <option value="{{ $user['id'] }}" @selected((string) old('owner_user_id') === (string) $user['id'])>
                  {{ $user['displayName'] }} ({{ $user['email'] }})
                </option>
              @endforeach
            </select>
          </label>
          <button type="submit">{{ __('グループを作成') }}</button>
        </form>
      </div>

      <div class="panel">
        <h2>{{ __('グループ一覧') }} ({{ count($groups) }})</h2>
        @forelse($groups as $group)
          <div class="admin-user-card">
            <div class="admin-user-card-head">
              <strong>{{ $group['name'] }}</strong>
              <span class="role-badge">{{ $group['statusLabel'] }}</span>
            </div>
            @if(!empty($group['description']))
              <p class="hint">{{ $group['description'] }}</p>
            @endif
            <p class="hint">
              {{ __('オーナー') }}: {{ $group['ownerName'] ?? '—' }}
              · {{ __('メンバー') }}: {{ $group['memberCount'] }}
              @if(!empty($group['reviewedAt']))
                · {{ __('審査') }}: {{ $group['reviewedAt'] }}
              @endif
            </p>
            @if(!empty($group['reviewNote']))
              <p class="hint">{{ __('メモ') }}: {{ $group['reviewNote'] }}</p>
            @endif

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
              <form method="post" action="/admin/groups/{{ $group['id'] }}/delete" class="inline-form" onsubmit='return confirm(@json(__('このグループを削除しますか？共有設定は解除されます。')))'>
                @csrf
                <button type="submit" class="danger mini-btn">{{ __('グループを削除') }}</button>
              </form>
            </div>

            @if(($group['status'] ?? '') === 'pending')
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                <form method="post" action="/admin/groups/{{ $group['id'] }}/approve" class="inline-form">
                  @csrf
                  <input type="hidden" name="review_note" value="" />
                  <button type="submit">{{ __('承認') }}</button>
                </form>
                <form method="post" action="/admin/groups/{{ $group['id'] }}/reject" class="inline-form">
                  @csrf
                  <input type="text" name="review_note" placeholder="{{ __('却下理由（任意）') }}" maxlength="500" />
                  <button type="submit" class="danger">{{ __('却下') }}</button>
                </form>
              </div>
            @endif

            @if(($group['status'] ?? '') === 'approved')
              <form method="post" action="/admin/groups/{{ $group['id'] }}/menus" class="group-menu-form">
                @csrf
                <fieldset class="menu-feature-fieldset">
                  <legend>{{ __('メンバーに付与するメニュー') }}</legend>
                  <p class="hint">{{ __('承認済みグループのメンバーに、ユーザー設定に加えてこれらのメニューを付与します。') }}</p>
                  <div class="menu-feature-checks">
                    @foreach($menuFeatures as $feature)
                      <label class="menu-feature-check">
                        <input
                          type="checkbox"
                          name="menuFeatures[]"
                          value="{{ $feature->value }}"
                          @checked(in_array($feature->value, $group['menuFeatures'] ?? [], true))
                        />
                        <span>{{ __($feature->label()) }}</span>
                      </label>
                    @endforeach
                  </div>
                </fieldset>
                <button type="submit" class="secondary mini-btn">{{ __('メニュー権限を保存') }}</button>
              </form>
            @endif
          </div>
        @empty
          <p class="hint">{{ __('グループはまだありません。') }}</p>
        @endforelse
      </div>
    </main>
  </body>
</html>
